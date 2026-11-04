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
        //
        // 开关类的两难：未勾选的 checkbox 不会出现在 POST 中，
        // 所以「存在才更新」会让开关永远关不掉；
        // 而「全量写入」又会让任何不带该字段的请求静默关闭功能
        // （脚本、第三方调用、表单少渲染一个字段都会中招）。
        //
        // 解法：视图为每个开关输出 <input type="hidden" name="_present[]" value="键名">，
        // 只有出现在 _present 里的开关才参与写入，其余保持原值。
        $present = (array) ($_POST['_present'] ?? []);

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
            if (!in_array($k, $present, true)) {
                continue;   // 表单未包含该开关，保持原值
            }
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
        // 每个键各自的上限。视图里 site_name 有 maxlength="32"、site_desc 是 120，
        // 但那只是浏览器端的约束，直接 POST 能绕过——实测把 500 字塞进站点名，
        // 会原样入库，前台的 <title> 和分享卡都会变得没法看。
        $strLimits = [
            'site_name'    => [32,  '云盘'],
            'site_desc'    => [120,  '简洁、快速的资源分享'],
            'site_url'     => [255,  ''],
            'icp'          => [64,   ''],
            'theme_color'  => [7,    '#07C160'],
            'ext_blacklist'=> [512,  ''],
        ];

        foreach ($strKeys as $k) {
            if (!array_key_exists($k, $_POST)) {
                continue;
            }
            $v = trim((string) $_POST[$k]);

            if ($k === 'default_quota_period') {
                setting_set($k, in_array($v, ['total', 'daily', 'monthly'], true) ? $v : 'total');
                $saved++;
                continue;
            }
            if ($k === 'sort_default') {
                // 这个键原来完全没校验，脏值会落库；而前台也从不读它（见 FrontController）
                setting_set($k, in_array($v, ['new', 'hot', 'name'], true) ? $v : 'new');
                $saved++;
                continue;
            }

            [$max, $fallback] = $strLimits[$k] ?? [512, ''];

            if ($k === 'theme_color') {
                // 只接受 6 位十六进制，否则前台的 :root 变量会变成非法值
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $v)) {
                    $v = $fallback;
                }
            } else {
                $v = mb_substr($v, 0, $max);
            }

            setting_set($k, $v);
            $saved++;
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
