<?php

declare(strict_types=1);

/**
 * 维护页：磁盘清理。
 *
 * 后台原先没有任何入口能看到「看不见的垃圾」：中断的上传分片、
 * 数据库无引用的孤立文件、过期的回收站条目、只增不减的日志。
 * 这些都会一直占空间，而且用户完全无从判断该不该清。
 */
final class AdminMaintainController
{
    private function respond(bool $ok, string $msg, array $extra = []): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(array_merge(['ok' => $ok, 'msg' => $msg], $extra), JSON_UNESCAPED_UNICODE);
        exit;
    }

    public function index(): void
    {
        $hours = (int) ($_GET['hours'] ?? 24);
        if ($hours < 1) {
            $hours = 1;
        }
        if ($hours > 720) {
            $hours = 720;
        }

        $data = Maintain::overview($hours);

        view('admin/maintain', [
            'active'   => 'maintain',
            'hours'    => $hours,
            // 键名不能用 data：view() 里是 extract($data, EXTR_SKIP)，
            // 参数 $data 已存在，同名的键会被直接跳过，视图里就成了 null。
            'stats'    => $data,
            'pageTitle' => '维护',
        ], 'admin');   // view() 自己会拼 layout/ 前缀
    }

    /** 清过期分片 */
    public function chunks(): void
    {
        $hours = (int) post('hours', '24');
        $n = Maintain::clearChunks($hours);

        admin_audit('maintain_chunks', 'maintain', 0, "清理过期分片 {$n} 个（{$hours} 小时阈值）");
        $this->respond(true, $n > 0 ? "已清理 {$n} 个过期任务" : '没有需要清理的分片', ['count' => $n]);
    }

    /** 清孤立文件 */
    public function orphans(): void
    {
        $r = Maintain::clearOrphans();

        if ($r['count'] > 0) {
            admin_audit(
                'maintain_orphans',
                'maintain',
                0,
                sprintf('清理孤立文件 %d 个，释放 %s', $r['count'], bytes_human($r['bytes']))
            );
        }

        $this->respond(
            true,
            $r['count'] > 0
                ? sprintf('已删除 %d 个孤立文件，释放 %s', $r['count'], bytes_human($r['bytes']))
                : '没有孤立文件',
            $r
        );
    }

    /** 清过期回收站 */
    public function trash(): void
    {
        $n = Maintain::clearTrash();

        admin_audit('maintain_trash', 'maintain', 0, "清理过期回收站 {$n} 项");
        $this->respond(true, $n > 0 ? "已永久删除 {$n} 项" : '回收站里没有过期条目', ['count' => $n]);
    }

    /** 清过期日志 */
    public function logs(): void
    {
        $r = Maintain::clearLogs();

        admin_audit(
            'maintain_logs',
            'maintain',
            0,
            sprintf('清理过期日志 %d 条，清空文件 %s', $r['rows'], bytes_human($r['bytes']))
        );

        $this->respond(
            true,
            $r['rows'] > 0 || $r['bytes'] > 0
                ? sprintf('已清理 %d 条过期记录，腾出 %s', $r['rows'], bytes_human($r['bytes']))
                : '没有需要清理的日志',
            $r
        );
    }

    /** 清孤儿下载记录 */
    public function downloads(): void
    {
        $n = Maintain::clearOrphanDownloads();

        if ($n > 0) {
            admin_audit('maintain_downloads', 'maintain', 0, "清理孤儿下载记录 {$n} 条");
        }
        $this->respond(true, $n > 0 ? "已清理 {$n} 条记录" : '没有孤儿下载记录', ['count' => $n]);
    }

    /** 全部执行 */
    public function all(): void
    {
        $hours = (int) post('hours', '24');

        $chunks = Maintain::clearChunks($hours);
        $orphans = Maintain::clearOrphans();
        $trash = Maintain::clearTrash();
        $logs = Maintain::clearLogs();
        $dl = Maintain::clearOrphanDownloads();

        admin_audit(
            'maintain_all',
            'maintain',
            0,
            sprintf(
                '全部清理：分片 %d、孤立文件 %d（%s）、回收站 %d、日志 %d 条、孤儿下载 %d',
                $chunks,
                $orphans['count'],
                bytes_human($orphans['bytes']),
                $trash,
                $logs['rows'],
                $dl
            )
        );

        $this->respond(true, sprintf(
            '完成：分片 %d、孤立文件 %d（%s）、回收站 %d、日志 %d 条、孤儿下载 %d 条',
            $chunks,
            $orphans['count'],
            bytes_human($orphans['bytes']),
            $trash,
            $logs['rows'],
            $dl
        ));
    }
}
