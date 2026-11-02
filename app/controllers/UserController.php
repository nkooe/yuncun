<?php
/**
 * 用户中心：概览、下载记录、登录记录。
 */
declare(strict_types=1);

class UserController
{
    public function index(): void
    {
        $u = Auth::user();
        if ($u === null) {
            redirect('/login');
        }
        $uid = (int) $u['id'];

        // 配额视图
        $quota = $this->quotaView($u);

        // 统计
        $createdAt = (string) ($u['created_at'] ?? '');
        $stats = [
            'downloads_total' => (int) Db::val(
                'SELECT COUNT(*) FROM ' . Db::t('downloads') . ' WHERE `user_id` = ? AND `status` <> ?',
                [$uid, 'aborted']
            ),
            'downloads_bytes' => (int) Db::val(
                'SELECT COALESCE(SUM(`bytes_sent`),0) FROM ' . Db::t('downloads') . ' WHERE `user_id` = ?',
                [$uid]
            ),
            'login_count'     => (int) $u['login_count'],
            'last_login'      => (string) ($u['last_login_at'] ?? ''),
            'last_ip'         => (string) ($u['last_login_ip'] ?? ''),
            'created_at'      => $createdAt !== '' ? date('Y-m-d H:i', strtotime($createdAt)) : '',
            'days'            => max(1, (int) ceil((time() - strtotime($createdAt)) / 86400)),
        ];

        // 两条记录各自独立分页：dp = 下载页，lp = 登录页
        $perPage = 10;

        $dp = max(1, get_int('dp', 1));
        $dTotal = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('downloads') . ' WHERE `user_id` = ?',
            [$uid]
        );
        $dPager = paginate($dTotal, $dp, $perPage);

        $recentDownloads = Db::all(
            'SELECT d.`id`, d.`file_id`, d.`ip`, d.`bytes_sent`, d.`status`, d.`created_at`, '
            . 'f.`name` AS file_name, f.`ext` '
            . 'FROM ' . Db::t('downloads') . ' d '
            . 'LEFT JOIN ' . Db::t('files') . ' f ON f.`id` = d.`file_id` '
            . 'WHERE d.`user_id` = ? ORDER BY d.`id` DESC '
            . 'LIMIT ' . (int) $dPager['per_page'] . ' OFFSET ' . (int) $dPager['offset'],
            [$uid]
        );

        $lp = max(1, get_int('lp', 1));
        $lTotal = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('login_logs') . ' WHERE `user_id` = ?',
            [$uid]
        );
        $lPager = paginate($lTotal, $lp, $perPage);

        $recentLogins = Db::all(
            'SELECT `ip`, `ua`, `status`, `msg`, `created_at` FROM ' . Db::t('login_logs')
            . ' WHERE `user_id` = ? ORDER BY `id` DESC '
            . 'LIMIT ' . (int) $lPager['per_page'] . ' OFFSET ' . (int) $lPager['offset'],
            [$uid]
        );

        view('user/index', [
            'user'            => $u,
            'level'           => level_by_id((int) ($u['level_id'] ?? 0)),
            'quota'           => $quota,
            'stats'           => $stats,
            'recentDownloads' => $recentDownloads,
            'recentLogins'    => $recentLogins,
            'dPager'          => $dPager,
            'lPager'          => $lPager,
        ], 'front');
    }

    /** 我的下载记录（分页） */
    public function downloads(): void
    {
        $u = Auth::user();
        if ($u === null) {
            redirect('/login');
        }
        $uid = (int) $u['id'];
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        $total = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('downloads') . ' WHERE `user_id` = ?',
            [$uid]
        );
        $pager = paginate($total, $page, $perPage);

        $rows = Db::all(
            'SELECT d.`id`, d.`file_id`, d.`ip`, d.`bytes_sent`, d.`status`, d.`created_at`, '
            . 'f.`name` AS file_name, f.`ext` '
            . 'FROM ' . Db::t('downloads') . ' d '
            . 'LEFT JOIN ' . Db::t('files') . ' f ON f.`id` = d.`file_id` '
            . 'WHERE d.`user_id` = ? ORDER BY d.`id` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'],
            [$uid]
        );

        view('user/downloads', [
            'rows'  => $rows,
            'pager' => $pager,
        ], 'front');
    }

    /** 我的登录记录（分页） */
    public function logins(): void
    {
        $u = Auth::user();
        if ($u === null) {
            redirect('/login');
        }
        $uid = (int) $u['id'];
        $page = max(1, get_int('page', 1));
        $perPage = max(5, min(100, (int) setting('page_size', 20)));

        $total = (int) Db::val(
            'SELECT COUNT(*) FROM ' . Db::t('login_logs') . ' WHERE `user_id` = ?',
            [$uid]
        );
        $pager = paginate($total, $page, $perPage);

        $rows = Db::all(
            'SELECT `ip`, `ua`, `status`, `msg`, `created_at` FROM ' . Db::t('login_logs')
            . ' WHERE `user_id` = ? ORDER BY `id` DESC '
            . 'LIMIT ' . (int) $pager['per_page'] . ' OFFSET ' . (int) $pager['offset'],
            [$uid]
        );

        view('user/logins', [
            'rows'  => $rows,
            'pager' => $pager,
        ], 'front');
    }

    /**
     * 计算当前周期配额状态，并在需要时懒重置。
     * 采用懒重置而不是定时任务：只有访问时才判断，零运维成本。
     */
    private function quotaView(array $u): array
    {
        $period   = (string) ($u['quota_period'] ?? 'total');
        $quota    = (int) ($u['download_quota'] ?? 0);
        $used     = (int) ($u['download_used'] ?? 0);
        $resetAt  = $u['quota_reset_at'] ?? null;

        if ($period !== 'total') {
            $needReset = false;
            if (empty($resetAt)) {
                $needReset = true;
            } elseif (strtotime((string) $resetAt) <= time()) {
                $needReset = true;
            }
            if ($needReset) {
                $next = $period === 'daily'
                    ? strtotime('tomorrow 00:00:00')
                    : strtotime('first day of next month 00:00:00');
                Db::update('users', [
                    'download_used'   => 0,
                    'quota_reset_at'  => date('Y-m-d H:i:s', $next),
                    'updated_at'      => now(),
                ], '`id` = ?', [(int) $u['id']]);
                $used = 0;
                $resetAt = date('Y-m-d H:i:s', $next);
            }
        }

        return [
            'period'    => $period,
            'quota'     => $quota,
            'used'      => $used,
            'unlimited' => $quota === 0,
            'left'      => $quota === 0 ? -1 : max(0, $quota - $used),
            'reset_at'  => $resetAt,
            'percent'   => $quota === 0 ? 0 : min(100, (int) round($used / max(1, $quota) * 100)),
        ];
    }
}
