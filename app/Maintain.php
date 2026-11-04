<?php

declare(strict_types=1);

/**
 * 维护：统计并清理磁盘上看不见的残留。
 *
 * 系统里有几类东西用户在后台看不到，但会一直占空间：
 *
 *   1. 分片暂存  runtime/chunks/<uploadId>/
 *      上传中断、关掉页面、手机切网络，分片就留在那里。
 *      原本只有 UploadController::init 里 1/20 概率触发一次 GC，
 *      用户既看不到也不知道该不该清。
 *
 *   2. 孤立文件  storage/files/xx/yy/<sha>.bin
 *      数据库里没有任何记录引用它，但文件还在。
 *      常见来源：删库记录时漏删、恢复备份后残留、早期版本的删除 bug。
 *
 *   3. 过期回收站
 *      超过 recycle_days 的条目本该永久清除，但 trash_gc() 从来没人调用。
 *
 *   4. 过期日志
 *      同样，log_gc() 也从来没有被调用过，日志只增不减。
 *
 *   5. 孤儿下载记录
 *      指向已经不存在的文件 id，统计时会算进总次数。
 *
 * 安全约束：孤立文件的判定必须是「全表无引用」——包括回收站里那些
 * 还能恢复的记录。只删绝对没人指向的，宁可漏删也不误删。
 */
final class Maintain
{
    /** 目录占用（递归求和，不跟随符号链接） */
    private static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if ($f->isFile() && !$f->isLink()) {
                $total += (int) $f->getSize();
            }
        }
        return $total;
    }

    /** 磁盘总览 */
    public static function disk(): array
    {
        $total = (int) @disk_total_space(STORAGE_PATH);
        $free  = Storage::freeSpace();

        return [
            'storage_size' => self::dirSize(Storage::filesRoot()),
            'runtime_size' => self::dirSize(RUNTIME_PATH),
            'disk_total'   => $total,
            'disk_free'    => $free,
            'disk_used_p'  => $total > 0 ? round((1 - $free / $total) * 100, 1) : 0,
        ];
    }

    /** 分片暂存：总数、过期数、占用 */
    public static function chunks(int $hours = 24): array
    {
        $root = Storage::chunksRoot();
        $now  = time();
        $max  = max(1, $hours) * 3600;

        $total = 0; $expired = 0; $bytes = 0; $expiredBytes = 0;

        if (is_dir($root)) {
            foreach (scandir($root) ?: [] as $d) {
                if ($d === '.' || $d === '..' || !Storage::isValidUploadId($d)) {
                    continue;
                }
                $dir = $root . '/' . $d;
                if (!is_dir($dir)) {
                    continue;
                }
                $sz = self::dirSize($dir);
                $total++;
                $bytes += $sz;

                $mtime = (int) @filemtime($dir);
                if ($now - $mtime > $max) {
                    $expired++;
                    $expiredBytes += $sz;
                }
            }
        }

        return [
            'total' => $total, 'bytes' => $bytes,
            'expired' => $expired, 'expired_bytes' => $expiredBytes,
        ];
    }

    /**
     * 孤立文件：磁盘上有、数据库里没有任何记录引用。
     *
     * 引用集合取自 wx_files 全表（含回收站），因为回收站里的文件
     * 还能被恢复，它们指向的 bin 必须留着。
     */
    public static function orphans(): array
    {
        $root = Storage::filesRoot();
        if (!is_dir($root)) {
            return ['count' => 0, 'bytes' => 0, 'list' => []];
        }

        $referenced = [];
        foreach (Db::all('SELECT DISTINCT `sha256` FROM ' . Db::t('files')) as $r) {
            $sha = (string) ($r['sha256'] ?? '');
            if ($sha !== '') {
                $referenced[$sha] = true;
            }
        }

        $count = 0; $bytes = 0; $list = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) {
                continue;
            }
            $name = $f->getFilename();
            if (!str_ends_with($name, '.bin')) {
                continue;
            }
            $sha = substr($name, 0, -4);
            if (isset($referenced[$sha])) {
                continue;
            }
            $count++;
            $bytes += (int) $f->getSize();
            if (count($list) < 20) {
                $list[] = ['path' => $f->getPathname(), 'size' => (int) $f->getSize()];
            }
        }

        return ['count' => $count, 'bytes' => $bytes, 'list' => $list];
    }

    /** 日志文件占用 */
    public static function logs(): array
    {
        $dir = RUNTIME_PATH . '/logs';
        $count = 0; $bytes = 0;
        if (is_dir($dir)) {
            foreach (glob($dir . '/*.log') ?: [] as $f) {
                if (is_file($f)) {
                    $count++;
                    $bytes += (int) filesize($f);
                }
            }
        }
        $days = (int) setting('log_keep_days', 30);
        $before = $days > 0 ? date('Y-m-d H:i:s', time() - $days * 86400) : '';

        $oldRows = 0;
        if ($before !== '') {
            foreach (['downloads', 'login_logs', 'admin_logs'] as $t) {
                try {
                    $oldRows += (int) Db::val(
                        'SELECT COUNT(*) FROM ' . Db::t($t) . ' WHERE `created_at` < ?',
                        [$before]
                    );
                } catch (Throwable) {
                }
            }
        }

        return ['files' => $count, 'bytes' => $bytes, 'old_rows' => $oldRows, 'keep_days' => $days];
    }

    /** 回收站里已过保留期的条目 */
    public static function trash(): array
    {
        $days = (int) setting('recycle_days', 30);
        if ($days <= 0) {
            return ['days' => $days, 'files' => 0, 'folders' => 0];
        }
        $before = date('Y-m-d H:i:s', time() - $days * 86400);

        $files = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL AND `deleted_at` < ?',
            [$before]
        );
        $folders = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NOT NULL AND `deleted_at` < ?',
            [$before]
        );

        return ['days' => $days, 'files' => $files, 'folders' => $folders];
    }

    /** 孤儿下载记录：指向已不存在的文件 */
    public static function orphanDownloads(): int
    {
        try {
            return (int) Db::val(
                'SELECT COUNT(*) FROM ' . Db::t('downloads')
                . ' WHERE `file_id` NOT IN (SELECT `id` FROM ' . Db::t('files') . ')'
            );
        } catch (Throwable) {
            return 0;
        }
    }

    /** 汇总，一次给页面 */
    public static function overview(int $hours = 24): array
    {
        return [
            'disk'      => self::disk(),
            'chunks'    => self::chunks($hours),
            'orphans'   => self::orphans(),
            'logs'      => self::logs(),
            'trash'     => self::trash(),
            'dl_orphan' => self::orphanDownloads(),
        ];
    }

    // ── 清理动作 ────────────────────────────────────────────

    /** 清过期分片 */
    public static function clearChunks(int $hours = 24): int
    {
        return Storage::gcChunks(max(1, $hours) * 3600);
    }

    /** 清孤立文件，返回删除个数与释放字节 */
    public static function clearOrphans(): array
    {
        $root = Storage::filesRoot();
        if (!is_dir($root)) {
            return ['count' => 0, 'bytes' => 0];
        }

        $referenced = [];
        foreach (Db::all('SELECT DISTINCT `sha256` FROM ' . Db::t('files')) as $r) {
            $sha = (string) ($r['sha256'] ?? '');
            if ($sha !== '') {
                $referenced[$sha] = true;
            }
        }

        $count = 0; $bytes = 0;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($it as $f) {
            if (!$f->isFile() || $f->isLink()) {
                continue;
            }
            $name = $f->getFilename();
            if (!str_ends_with($name, '.bin')) {
                continue;
            }
            $sha = substr($name, 0, -4);
            if (isset($referenced[$sha])) {
                continue;
            }
            $sz = (int) $f->getSize();
            if (@unlink($f->getPathname())) {
                $count++;
                $bytes += $sz;
            }
        }

        // 删完全空的哈希桶目录
        self::pruneEmptyDirs($root);

        return ['count' => $count, 'bytes' => $bytes];
    }

    /** 清过期日志（磁盘文件 + 数据库记录） */
    public static function clearLogs(): array
    {
        $rowsBefore = 0;
        $days = (int) setting('log_keep_days', 30);
        if ($days > 0) {
            $before = date('Y-m-d H:i:s', time() - $days * 86400);
            foreach (['downloads', 'login_logs', 'admin_logs'] as $t) {
                try {
                    $rowsBefore += (int) Db::val(
                        'SELECT COUNT(*) FROM ' . Db::t($t) . ' WHERE `created_at` < ?',
                        [$before]
                    );
                } catch (Throwable) {
                }
            }
        }
        log_gc();

        // 清空日志文件内容但保留文件本身，避免 PHP 正持有句柄时删不掉
        $bytes = 0;
        foreach (glob(RUNTIME_PATH . '/logs/*.log') ?: [] as $f) {
            $sz = (int) @filesize($f);
            if ($sz > 0 && @file_put_contents($f, '') !== false) {
                $bytes += $sz;
            }
        }

        return ['rows' => $rowsBefore, 'bytes' => $bytes];
    }

    /** 清过期回收站 */
    public static function clearTrash(): int
    {
        $days = (int) setting('recycle_days', 30);
        if ($days <= 0) {
            return 0;
        }
        $before = date('Y-m-d H:i:s', time() - $days * 86400);
        $n = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL AND `deleted_at` < ?',
            [$before]
        );
        $f = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NOT NULL AND `deleted_at` < ?',
            [$before]
        );
        trash_gc();
        return $n + $f;
    }

    /** 清孤儿下载记录 */
    public static function clearOrphanDownloads(): int
    {
        $n = self::orphanDownloads();
        if ($n > 0) {
            Db::exec(
                'DELETE FROM ' . Db::t('downloads')
                . ' WHERE `file_id` NOT IN (SELECT `id` FROM ' . Db::t('files') . ')'
            );
        }
        return $n;
    }

    /** 删掉空的两级哈希桶目录 */
    private static function pruneEmptyDirs(string $root): void
    {
        foreach (scandir($root) ?: [] as $a) {
            if ($a === '.' || $a === '..') {
                continue;
            }
            $pa = $root . '/' . $a;
            if (!is_dir($pa)) {
                continue;
            }
            foreach (scandir($pa) ?: [] as $b) {
                if ($b === '.' || $b === '..') {
                    continue;
                }
                $pb = $pa . '/' . $b;
                if (is_dir($pb) && count(scandir($pb) ?: []) <= 2) {
                    @rmdir($pb);
                }
            }
            if (count(scandir($pa) ?: []) <= 2) {
                @rmdir($pa);
            }
        }
    }

    /**
     * 低频自动维护。每个小时最多跑一次，用文件时间戳节流，
     * 避免每个请求都去扫目录。
     */
    public static function tick(): void
    {
        $flag = RUNTIME_PATH . '/cache/.gc-tick';
        $now  = time();
        $last = (int) (@filemtime($flag) ?: 0);
        if ($last > 0 && $now - $last < 3600) {
            return;
        }
        @file_put_contents($flag, (string) $now);

        try {
            // 过期分片留 24 小时，够用户切页面回来续传
            Storage::gcChunks(86400);
            trash_gc();
            log_gc();
        } catch (Throwable) {
            // 维护失败不能影响正常请求
        }
    }
}
