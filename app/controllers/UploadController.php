<?php
/**
 * 分片上传 API（仅管理员）。
 *
 * 流程：
 *   init   → 建任务，返回 uploadId 与服务端允许的分片大小
 *   chunk  → 逐片上传，可并发
 *   finish → 合并、算 sha256、按哈希入库、建文件记录
 *   abort  → 放弃并清理
 */
declare(strict_types=1);

class UploadController
{
    /** 目标单片大小上限 2MB */
    /**
     * 单分片目标大小。实际值取 min(它, PHP 上传上限 * 0.8)，
     * 所以 php.ini 放宽后分片会自动变大，无需改代码。
     */
    private const CHUNK_TARGET = 33554432; // 32MB

    public function init(): void
    {
        // 文件名字段来自浏览器，部分客户端会带完整路径（如 ../../../x.txt）。
        // 物理存储用 sha256 定位，不受路径影响，但名字会进数据库、
        // 列表展示和 Content-Disposition，残留 ../ 会导致显示异常，
        // 某些下载工具也可能据此写到预期之外的目录。此处统一剥掉路径。
        $name = (string) post('name');
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name);
        $name = trim($name);

        $size     = (int) post('size');
        $chunks   = (int) post('chunks');
        $folderId = (int) post('folder_id');

        if ($name === '' || mb_strlen($name) > 255) {
            $this->fail('文件名无效');
        }
        if ($size <= 0) {
            $this->fail('不能上传空文件');
        }
        if ($chunks <= 0 || $chunks > 20000) {
            $this->fail('分片数量非法');
        }

        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        // 扩展名黑名单
        $blacklist = array_filter(array_map('trim', explode(',', (string) setting('ext_blacklist', ''))));
        if ($ext !== '' && in_array($ext, array_map('strtolower', $blacklist), true)) {
            $this->fail("出于安全考虑，不允许上传 .{$ext} 文件");
        }

        // 单文件大小上限
        $maxSize = (int) setting('max_upload_size', 2147483648);
        if ($maxSize > 0 && $size > $maxSize) {
            $this->fail('文件超过单文件上限 ' . bytes_human($maxSize));
        }

        // 磁盘余量：至少留 2 倍文件大小
        $free = Storage::freeSpace();
        if ($free > 0 && $free < $size * 2) {
            $this->fail('服务器存储空间不足，请联系管理员');
        }

        // 水位保护：接近阈值时直接停止接收，避免写满导致数据库损坏
        $guardPercent = (int) setting('disk_guard_percent', 92);
        if ($guardPercent > 0 && $guardPercent <= 100) {
            $total = (int) @disk_total_space(STORAGE_PATH);
            if ($total > 0 && $free > 0) {
                $usedPercent = (1 - $free / $total) * 100;
                if ($usedPercent >= $guardPercent) {
                    $this->fail(sprintf(
                        '服务器存储已达 %.0f%%（安全线 %d%%），暂停上传，请联系管理员',
                        $usedPercent,
                        $guardPercent
                    ), 507);
                }
            }
        }

        // 目标文件夹必须存在
        if ($folderId > 0) {
            $ok = Db::val(
                'SELECT 1 FROM ' . Db::t('folders') . ' WHERE `id` = ? AND `deleted_at` IS NULL',
                [$folderId]
            );
            if ($ok === null) {
                $this->fail('目标文件夹不存在');
            }
        }

        $chunkSize = $this->allowedChunkSize();
        $expectChunks = (int) ceil($size / $chunkSize);
        if ($chunks !== $expectChunks) {
            // 以服务端计算为准，容忍前端略有出入
            $chunks = $expectChunks;
        }

        // 顺带清理过期分片
        if (random_int(1, 20) === 1) {
            Storage::gcChunks();
        }

        $uploadId = Storage::initUpload([
            'name'      => $name,
            'size'      => $size,
            'chunks'    => $chunks,
            'folder_id' => $folderId,
            'user_id'   => Auth::id(),
            'chunk_size' => $chunkSize,
        ]);

        $this->ok([
            'upload_id'  => $uploadId,
            'chunk_size' => $chunkSize,
            'chunks'     => $chunks,
        ]);
    }

    public function chunk(): void
    {
        $uploadId = post('upload_id');
        $index    = (int) post('index');

        if (!Storage::isValidUploadId($uploadId)) {
            $this->fail('上传任务无效', 400);
        }
        $meta = Storage::readMeta($uploadId);
        if ($meta === null) {
            $this->fail('上传任务不存在或已过期', 410);
        }
        if ((int) ($meta['user_id'] ?? 0) !== Auth::id()) {
            $this->fail('无权操作该上传任务', 403);
        }
        if ($index < 0 || $index >= (int) $meta['chunks']) {
            $this->fail('分片序号越界', 400);
        }
        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            $this->fail('未接收到分片数据', 400);
        }

        $f = $_FILES['file'];
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->fail($this->uploadErrorText((int) $f['error']), 400);
        }
        if ((int) $f['size'] <= 0) {
            $this->fail('分片内容为空', 400);
        }
        // 单片不得超出声明上限太多，防止恶意大包
        $limit = (int) $meta['chunk_size'] + 65536;
        if ((int) $f['size'] > $limit) {
            $this->fail('分片体积异常', 400);
        }

        $dir = Storage::chunkDir($uploadId);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->fail('无法创建分片目录', 500);
        }

        $dest = Storage::chunkPath($uploadId, $index);
        if (!@move_uploaded_file($f['tmp_name'], $dest)) {
            $this->fail('分片落盘失败（磁盘可能已满）', 500);
        }

        // 刷新目录时间戳，供 GC 判断活跃度
        @touch($dir);

        $received = count(Storage::receivedChunks($uploadId));
        $this->ok([
            'received' => $received,
            'total'    => (int) $meta['chunks'],
        ]);
    }

    public function finish(): void
    {
        $uploadId = post('upload_id');
        if (!Storage::isValidUploadId($uploadId)) {
            $this->fail('上传任务无效', 400);
        }
        $meta = Storage::readMeta($uploadId);
        if ($meta === null) {
            $this->fail('上传任务不存在或已过期', 410);
        }
        if ((int) ($meta['user_id'] ?? 0) !== Auth::id()) {
            $this->fail('无权操作该上传任务', 403);
        }

        $totalChunks = (int) $meta['chunks'];
        $received = Storage::receivedChunks($uploadId);
        if (count($received) !== $totalChunks) {
            $this->fail('分片不完整：已收 ' . count($received) . " / {$totalChunks}", 409);
        }

        try {
            $merged = Storage::mergeChunks($uploadId, $totalChunks);
            $stored = Storage::store($merged['tmp'], $merged['sha256']);
        } catch (Throwable $e) {
            Storage::cleanupUpload($uploadId);
            $this->fail($e->getMessage(), 500);
        }

        $name = (string) $meta['name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $now  = now();

        $fileId = Db::insert('files', [
            'folder_id'   => (int) $meta['folder_id'],
            'name'        => $name,
            'orig_name'   => $name,
            'ext'         => mb_substr($ext, 0, 16),
            'mime'        => mime_for_ext($ext),
            'size'        => (int) $stored['size'],
            'storage_key' => $stored['key'],
            'sha256'      => $stored['sha256'],
            'downloads'   => 0,
            'is_public'   => 1,
            'sort'        => 0,
            'status'      => 1,
            'uploader_id' => Auth::id(),
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // 累计用户已用空间
        if (!$stored['deduped']) {
            Db::exec(
                'UPDATE ' . Db::t('users') . ' SET `storage_used` = `storage_used` + ?, `updated_at` = ? WHERE `id` = ?',
                [(int) $stored['size'], $now, Auth::id()]
            );
        }

        Storage::cleanupUpload($uploadId);

        $this->ok([
            'file_id' => $fileId,
            'name'    => $name,
            'size'    => (int) $stored['size'],
            'size_h'  => bytes_human((int) $stored['size']),
            'sha256'  => $stored['sha256'],
            'deduped' => $stored['deduped'],
        ]);
    }

    public function abort(): void
    {
        $uploadId = post('upload_id');
        if (!Storage::isValidUploadId($uploadId)) {
            $this->ok(['aborted' => false]);
        }
        $meta = Storage::readMeta($uploadId);
        if ($meta !== null && (int) ($meta['user_id'] ?? 0) !== Auth::id()) {
            $this->fail('无权操作该上传任务', 403);
        }
        Storage::cleanupUpload($uploadId);
        $this->ok(['aborted' => true]);
    }

    // ---------- 内部 ----------

    /** 服务端允许的单片大小：受 php.ini 限制，最大 2MB */
    private function allowedChunkSize(): int
    {
        $up   = $this->iniBytes((string) ini_get('upload_max_filesize'));
        $post = $this->iniBytes((string) ini_get('post_max_size'));
        $cap  = min($up ?: PHP_INT_MAX, $post ?: PHP_INT_MAX);

        if ($cap === PHP_INT_MAX) {
            return self::CHUNK_TARGET;
        }
        // 留 20% 余量给 multipart 头部
        $size = (int) min(self::CHUNK_TARGET, (int) ($cap * 0.8));
        return max(262144, $size);
    }

    private function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') {
            return 0;
        }
        $unit = strtolower(substr($v, -1));
        $num = (float) $v;
        return match ($unit) {
            'g' => (int) ($num * 1024 ** 3),
            'm' => (int) ($num * 1024 ** 2),
            'k' => (int) ($num * 1024),
            default => (int) $num,
        };
    }

    private function uploadErrorText(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '分片超过 PHP 上传限制',
            UPLOAD_ERR_PARTIAL   => '分片只上传了一部分，请重试',
            UPLOAD_ERR_NO_FILE   => '没有选择文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入磁盘失败',
            UPLOAD_ERR_EXTENSION => '上传被 PHP 扩展中断',
            default => '上传失败（错误码 ' . $code . '）',
        };
    }

    private function ok(array $data = []): never
    {
        json_out(['ok' => true] + $data);
    }

    private function fail(string $msg, int $status = 400): never
    {
        json_out(['ok' => false, 'msg' => $msg], $status);
    }
}
