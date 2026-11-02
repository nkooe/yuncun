<?php
/**
 * 后台：记录中心（下载 / 登录 / 操作日志）。
 */
declare(strict_types=1);

class AdminLogController
{
    public function index(): void
    {
        $this->autoClean();

        $tab = get('tab', 'downloads');
        if (!in_array($tab, ['downloads', 'logins', 'admin'], true)) {
            $tab = 'downloads';
        }
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        $rows = [];
        $pager = paginate(0, 1, $perPage);

        if ($tab === 'downloads') {
            $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('downloads'));
            $pager = paginate($total, $page, $perPage);
            $rows = Db::all(
                'SELECT d.*, f.`name` AS file_name, f.`ext`, u.`username` '
                . 'FROM ' . Db::t('downloads') . ' d '
                . 'LEFT JOIN ' . Db::t('files') . ' f ON f.`id` = d.`file_id` '
                . 'LEFT JOIN ' . Db::t('users') . ' u ON u.`id` = d.`user_id` '
                . 'ORDER BY d.`id` DESC '
                . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
            );
        } elseif ($tab === 'logins') {
            $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('login_logs'));
            $pager = paginate($total, $page, $perPage);
            $rows = Db::all(
                'SELECT * FROM ' . Db::t('login_logs') . ' ORDER BY `id` DESC '
                . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
            );
        } else {
            $total = (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('admin_logs'));
            $pager = paginate($total, $page, $perPage);
            $rows = Db::all(
                'SELECT a.*, u.`username` FROM ' . Db::t('admin_logs') . ' a '
                . 'LEFT JOIN ' . Db::t('users') . ' u ON u.`id` = a.`admin_id` '
                . 'ORDER BY a.`id` DESC '
                . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset']
            );
        }

        $counts = [
            'downloads' => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('downloads')),
            'logins'    => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('login_logs')),
            'admin'     => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('admin_logs')),
        ];

        view('admin/logs', [
            'tab'       => $tab,
            'rows'      => $rows,
            'pager'     => $pager,
            'counts'    => $counts,
            'keepDays'  => (int) setting('log_keep_days', 30),
            'active'    => 'logs',
        ], 'admin');
    }

    /**
     * 手动清理日志。
     * scope: 7 / 30 / 90 / all（all=清空）
     * tab:   downloads / logins / admin / all
     */
    public function clean(): void
    {
        $tab = (string) post('tab', 'downloads');
        $scope = (string) post('scope', '30');

        $map = [
            'downloads' => ['downloads'],
            'logins'    => ['login_logs'],
            'admin'     => ['admin_logs'],
            'all'       => ['downloads', 'login_logs', 'admin_logs'],
        ];
        $tables = $map[$tab] ?? ['downloads'];

        $total = 0;
        if ($scope === 'all') {
            foreach ($tables as $t) {
                $total += (int) Db::exec('DELETE FROM ' . Db::t($t));
            }
        } else {
            $days = max(1, (int) $scope);
            $before = date('Y-m-d H:i:s', time() - $days * 86400);
            foreach ($tables as $t) {
                $total += (int) Db::exec(
                    'DELETE FROM ' . Db::t($t) . ' WHERE `created_at` < ?',
                    [$before]
                );
            }
        }

        try {
            Db::insert('admin_logs', [
                'admin_id'    => Auth::id(),
                'action'      => 'clean_logs',
                'target_type' => 'log',
                'target_id'   => 0,
                'detail'      => "清理日志（{$tab} / " . ($scope === 'all' ? '全部' : $scope . ' 天前') . "）：{$total} 条",
                'ip'          => client_ip(),
                'created_at'  => now(),
            ]);
        } catch (Throwable) {
        }

        if (Auth::wantsJson()) {
            json_out(['ok' => true, 'msg' => "已清理 {$total} 条记录", 'count' => $total]);
        }
        flash('success', "已清理 {$total} 条记录");
        redirect('/admin/logs?tab=' . $tab);
    }

    /** 超过保留期的日志在打开页面时自动清理（每小时最多一次） */
    private function autoClean(): void
    {
        $days = (int) setting('log_keep_days', 30);
        if ($days <= 0) {
            return;
        }

        $last = (int) ($_SESSION['_log_clean_at'] ?? 0);
        if (time() - $last < 3600) {
            return;
        }
        $_SESSION['_log_clean_at'] = time();

        $before = date('Y-m-d H:i:s', time() - $days * 86400);
        foreach (['downloads', 'login_logs', 'admin_logs'] as $t) {
            try {
                Db::exec('DELETE FROM ' . Db::t($t) . ' WHERE `created_at` < ?', [$before]);
            } catch (Throwable) {
            }
        }
    }
}
