<?php
/**
 * 后台：仪表盘、系统设置。
 */
declare(strict_types=1);

class AdminController
{
    public function dashboard(): void
    {
        $stats = [
            'files'      => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NULL'),
            'folders'    => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NULL'),
            'users'      => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('users')),
            'trash'      => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL'),
            'size'       => (int) Db::val('SELECT COALESCE(SUM(`size`),0) FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NULL'),
            'downloads'  => (int) Db::val('SELECT COUNT(*) FROM ' . Db::t('downloads')),
            'today_dl'   => (int) Db::val(
                'SELECT COUNT(*) FROM ' . Db::t('downloads') . ' WHERE `created_at` >= ?',
                [date('Y-m-d 00:00:00')]
            ),
            'today_files' => (int) Db::val(
                'SELECT COUNT(*) FROM ' . Db::t('files') . ' WHERE `created_at` >= ?',
                [date('Y-m-d 00:00:00')]
            ),
        ];

        $recentFiles = Db::all(
            'SELECT `id`,`name`,`ext`,`size`,`downloads`,`created_at` FROM ' . Db::t('files')
            . ' WHERE `deleted_at` IS NULL ORDER BY `id` DESC LIMIT 6'
        );

        $recentLogins = Db::all(
            'SELECT `username`,`ip`,`status`,`msg`,`created_at` FROM ' . Db::t('login_logs')
            . ' ORDER BY `id` DESC LIMIT 8'
        );

        view('admin/dashboard', [
            'stats'        => $stats,
            'recentFiles'  => $recentFiles,
            'recentLogins' => $recentLogins,
            'active'       => 'dashboard',
        ], 'admin');
    }

    public function settings(): void
    {
        $settings = [];
        foreach (Db::all('SELECT `k`,`v` FROM ' . Db::t('settings')) as $row) {
            $decoded = json_decode((string) $row['v'], true);
            $settings[$row['k']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['v'];
        }

        view('admin/settings', [
            'settings' => $settings,
            'active'   => 'settings',
        ], 'admin');
    }

    public function saveSettings(): void
    {
        // 白名单：只允许保存已知键，避免任意写
        // 开关类必须全量写入：未勾选的 checkbox 不会出现在 POST 中，
        // 若按「存在才更新」处理，开关将永远无法关闭。
        $boolKeys = [
            'require_login_download', 'guest_visible', 'allow_register',
            'maintenance_mode', 'share_enabled', 'hide_locked',
        ];
        $intKeys = [
            'login_fail_limit', 'page_size', 'default_download_quota',
            'default_storage_quota', 'recycle_days', 'log_keep_days', 'disk_guard_percent',
            'login_lock_minutes',
        ];
        $strKeys = [
            'site_name', 'site_desc', 'site_url', 'icp', 'theme_color',
            'default_quota_period', 'sort_default', 'ext_blacklist',
        ];

        $saved = 0;
        foreach ($boolKeys as $k) {
            $on = isset($_POST[$k]) && (string) $_POST[$k] === '1' ? 1 : 0;
            setting_set($k, $on);
            $saved++;
        }
        foreach ($intKeys as $k) {
            if (array_key_exists($k, $_POST)) {
                setting_set($k, (int) $_POST[$k]);
                $saved++;
            }
        }
        foreach ($strKeys as $k) {
            if (array_key_exists($k, $_POST)) {
                $v = trim((string) $_POST[$k]);
                if ($k === 'default_quota_period' && !in_array($v, ['total', 'daily', 'monthly'], true)) {
                    $v = 'total';
                }
                setting_set($k, mb_substr($v, 0, 512));
                $saved++;
            }
        }

        $this->audit('save_settings', 'settings', 0, "更新 {$saved} 项");
        flash('success', "已保存 {$saved} 项设置");
        redirect('/admin/settings');
    }

    /** 后台操作审计 */
    private function audit(string $action, string $type, int $targetId, string $detail = ''): void
    {
        try {
            Db::insert('admin_logs', [
                'admin_id'    => Auth::id(),
                'action'      => $action,
                'target_type' => $type,
                'target_id'   => $targetId,
                'detail'      => mb_substr($detail, 0, 500),
                'ip'          => client_ip(),
                'created_at'  => now(),
            ]);
        } catch (Throwable) {
        }
    }
}
