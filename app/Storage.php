<?php
/**
 * 物理存储层。
 *
 * 落盘规则：storage/files/<sha256[0:2]>/<sha256[2:4]>/<sha256>.bin
 *   —— 以内容哈希命名，天然去重（相同内容只存一份）
 *   —— 无扩展名，即使目录被直连也不会被 Web 服务器当成脚本执行
 *   —— 两级分桶，避免单目录堆积过多文件拖慢文件系统
 *
 * 分片暂存：runtime/chunks/<uploadId>/<index>.part
 */
declare(strict_types=1);

class Storage
{
    /** 分片目录 */
    public static function chunksRoot(): string
    {
        return RUNTIME_PATH . '/chunks';
    }

    /** 文件实体根目录 */
    public static function filesRoot(): string
    {
        return STORAGE_PATH . '/files';
    }

    /** sha256 → 相对 key（存库） */
    public static function relKey(string $sha256): string
    {
        return substr($sha256, 0, 2) . '/' . substr($sha256, 2, 2) . '/' . $sha256 . '.bin';
    }

    /** sha256 → 绝对路径 */
    public static function absPath(string $sha256): string
    {
        return self::filesRoot() . '/' . self::relKey($sha256);
    }

    public static function exists(string $sha256): bool
    {
        return $sha256 !== '' && is_file(self::absPath($sha256));
    }

    public static function size(string $sha256): int
    {
        $p = self::absPath($sha256);
        return is_file($p) ? (int) filesize($p) : 0;
    }

    /** 确保目标分桶目录存在 */
    public static function ensureBucket(string $sha256): string
    {
        $abs = self::absPath($sha256);
        $dir = dirname($abs);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $abs;
    }

    /**
     * 将临时文件按内容哈希入库。
     * @return array{sha256:string,size:int,key:string,deduped:bool}
     */
    public static function store(string $tmpFile, string $sha256): array
    {
        $size = (int) filesize($tmpFile);

        if (self::exists($sha256)) {
            @unlink($tmpFile);
            return [
                'sha256'  => $sha256,
                'size'    => self::size($sha256),
                'key'     => self::relKey($sha256),
                'deduped' => true,
            ];
        }

        $target = self::ensureBucket($sha256);
        if (!@rename($tmpFile, $target)) {
            // 跨分区时 rename 会失败，退化为复制
            if (!@copy($tmpFile, $target)) {
                throw new RuntimeException('文件入库失败，请检查 storage 目录权限');
            }
            @unlink($tmpFile);
        }
        @chmod($target, 0640);

        // 校验落地大小，防止磁盘写满导致静默截断
        if ((int) filesize($target) !== $size) {
            @unlink($target);
            throw new RuntimeException('文件写入不完整（磁盘可能已满）');
        }

        return [
            'sha256'  => $sha256,
            'size'    => $size,
            'key'     => self::relKey($sha256),
            'deduped' => false,
        ];
    }

    /** 删除物理文件（调用方需先确认没有其他记录引用） */
    public static function delete(string $sha256): bool
    {
        $p = self::absPath($sha256);
        if (!is_file($p)) {
            return true;
        }
        return @unlink($p);
    }

    // ---------- 分片 ----------

    public static function isValidUploadId(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9]{32}$/', $id);
    }

    public static function chunkDir(string $uploadId): string
    {
        return self::chunksRoot() . '/' . $uploadId;
    }

    public static function chunkPath(string $uploadId, int $index): string
    {
        return self::chunkDir($uploadId) . '/' . $index . '.part';
    }

    public static function metaPath(string $uploadId): string
    {
        return self::chunkDir($uploadId) . '/meta.json';
    }

    /** 初始化一个上传任务，返回 uploadId */
    public static function initUpload(array $meta): string
    {
        $id = bin2hex(random_bytes(16));
        $dir = self::chunkDir($id);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new RuntimeException('无法创建分片目录，请检查 runtime 目录权限');
        }
        $meta['created_at'] = time();
        @file_put_contents(self::metaPath($id), json_encode($meta, JSON_UNESCAPED_UNICODE));
        return $id;
    }

    public static function readMeta(string $uploadId): ?array
    {
        $p = self::metaPath($uploadId);
        if (!is_file($p)) {
            return null;
        }
        $j = json_decode((string) file_get_contents($p), true);
        return is_array($j) ? $j : null;
    }

    /** 已接收的分片序号 */
    public static function receivedChunks(string $uploadId): array
    {
        $dir = self::chunkDir($uploadId);
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/^(\d+)\.part$/', $f, $m)) {
                $out[] = (int) $m[1];
            }
        }
        sort($out);
        return $out;
    }

    /**
     * 合并分片并计算 sha256。
     * 先写到 runtime 下的临时文件，算完哈希再入库。
     * @return array{sha256:string,size:int,tmp:string}
     */
    public static function mergeChunks(string $uploadId, int $totalChunks): array
    {
        $dir = self::chunkDir($uploadId);
        $tmpOut = $dir . '/__merged.tmp';

        $out = @fopen($tmpOut, 'wb');
        if ($out === false) {
            throw new RuntimeException('无法创建合并临时文件');
        }

        $ctx = hash_init('sha256');
        $total = 0;

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $part = self::chunkPath($uploadId, $i);
                if (!is_file($part)) {
                    throw new RuntimeException("分片 {$i} 缺失，请重新上传");
                }
                $in = @fopen($part, 'rb');
                if ($in === false) {
                    throw new RuntimeException("无法读取分片 {$i}");
                }
                while (!feof($in)) {
                    $buf = fread($in, 1 << 20);
                    if ($buf === false) {
                        break;
                    }
                    if ($buf === '') {
                        continue;
                    }
                    hash_update($ctx, $buf);
                    $w = fwrite($out, $buf);
                    if ($w === false) {
                        throw new RuntimeException('写入合并文件失败（磁盘可能已满）');
                    }
                    $total += $w;
                }
                fclose($in);
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($tmpOut);
            throw $e;
        }

        fclose($out);
        $sha = hash_final($ctx);

        if ($total <= 0) {
            @unlink($tmpOut);
            throw new RuntimeException('合并结果为空文件');
        }

        return ['sha256' => $sha, 'size' => $total, 'tmp' => $tmpOut];
    }

    /** 清理一个上传任务的全部分片 */
    public static function cleanupUpload(string $uploadId): void
    {
        if (!self::isValidUploadId($uploadId)) {
            return;
        }
        $dir = self::chunkDir($uploadId);
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $p = $dir . '/' . $f;
            is_dir($p) ? @rmdir($p) : @unlink($p);
        }
        @rmdir($dir);
    }

    /**
     * 清理过期分片（默认 24 小时）。
     * 上传初始化时按 1/20 概率触发，避免每次请求都扫描目录。
     */
    public static function gcChunks(int $maxAge = 86400): int
    {
        $root = self::chunksRoot();
        if (!is_dir($root)) {
            return 0;
        }
        $now = time();
        $removed = 0;
        foreach (scandir($root) ?: [] as $d) {
            if ($d === '.' || $d === '..') {
                continue;
            }
            if (!self::isValidUploadId($d)) {
                continue;
            }
            $dir = $root . '/' . $d;
            if (!is_dir($dir)) {
                continue;
            }
            $mtime = (int) @filemtime($dir);
            if ($now - $mtime > $maxAge) {
                self::cleanupUpload($d);
                $removed++;
            }
        }
        return $removed;
    }

    /** 剩余磁盘空间（字节） */
    public static function freeSpace(): int
    {
        $p = self::filesRoot();
        if (!is_dir($p)) {
            @mkdir($p, 0755, true);
        }
        $free = @disk_free_space($p);
        return $free === false ? 0 : (int) $free;
    }

    /**
     * 流式输出文件（支持 Range 断点续传）。
     * 调用前应确保已通过全部权限校验。
     *
     * @return array{sent:int, aborted:bool, size:int}
     */
    public static function stream(string $path, string $mime, string $name): array
    {
        $size = (int) @filesize($path);
        if ($size <= 0) {
            return ['sent' => 0, 'aborted' => false, 'size' => 0];
        }

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        set_time_limit(0);
        ignore_user_abort(true);

        $start = 0;
        $end = $size - 1;
        $partial = false;

        $range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
        if ($range !== '' && preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $m)) {
            $hasStart = $m[1] !== '';
            $hasEnd = $m[2] !== '';
            if ($hasStart && $hasEnd) {
                $start = (int) $m[1];
                $end = (int) $m[2];
            } elseif ($hasStart) {
                $start = (int) $m[1];
                $end = $size - 1;
            } elseif ($hasEnd) {
                $start = max(0, $size - (int) $m[2]);
                $end = $size - 1;
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header('Content-Range: bytes */' . $size);
                return ['sent' => 0, 'aborted' => false, 'size' => $size];
            }
            $end = min($end, $size - 1);
            $partial = true;
        }

        $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $name) ?: 'download';
        $asciiName = str_replace(['"', '\\', "\r", "\n"], '', $asciiName);

        header('Content-Type: ' . ($mime !== '' ? $mime : 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . $asciiName . '"; '
            . "filename*=UTF-8''" . rawurlencode($name));
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');

        if ($partial) {
            http_response_code(206);
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
        }
        header('Content-Length: ' . ($end - $start + 1));

        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return ['sent' => 0, 'aborted' => false, 'size' => $size];
        }
        fseek($fp, $start);

        $remaining = $end - $start + 1;
        $sent = 0;
        $blockSize = 64 * 1024;

        while ($remaining > 0) {
            if (connection_aborted()) {
                break;
            }
            $buf = fread($fp, (int) min($blockSize, $remaining));
            if ($buf === false || $buf === '') {
                break;
            }
            echo $buf;
            flush();
            $n = strlen($buf);
            $sent += $n;
            $remaining -= $n;
        }

        fclose($fp);

        return ['sent' => $sent, 'aborted' => connection_aborted() === 1, 'size' => $size];
    }

    /**
     * 把文件记录解析为磁盘绝对路径；实体缺失返回空串。
     * 优先按 sha256 定位（内容寻址），退回到 storage_key 的传统路径。
     */
    public static function resolveFilePath(array $file): string
    {
        $sha = (string) ($file['sha256'] ?? '');
        if ($sha !== '' && self::exists($sha)) {
            return self::absPath($sha);
        }
        $key = (string) ($file['storage_key'] ?? '');
        if ($key !== '') {
            $p = STORAGE_PATH . '/files/' . ltrim(str_replace('..', '', $key), '/');
            if (is_file($p)) {
                return $p;
            }
        }
        return '';
    }
}