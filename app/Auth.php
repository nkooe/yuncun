<?php
/**
 * 认证与权限：登录、登出、CSRF、会话用户缓存。
 */
declare(strict_types=1);

class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    /** 当前登录用户（未登录返回 null） */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }
        self::$loaded = true;

        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0) {
            self::$user = null;
            return null;
        }
        try {
            $u = Db::row(
                'SELECT * FROM ' . Db::t('users') . ' WHERE `id` = ? AND `status` = 1 LIMIT 1',
                [$uid]
            );
        } catch (Throwable) {
            $u = null;
        }
        if ($u === null) {
            unset($_SESSION['uid']);
        }
        self::$user = $u;
        return self::$user;
    }

    public static function id(): int
    {
        return (int) (self::user()['id'] ?? 0);
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        return (self::user()['role'] ?? '') === 'admin';
    }

    /** 登录失败计数（基于 session，简单有效，避免暴力破解） */
    /**
     * 最近窗口内的失败次数，从数据库统计。
     *
     * 早期实现把计数存在 $_SESSION 里，攻击者丢掉 Cookie 就能重置，
     * 等于没有防护。这里改为查 login_logs，客户端无法伪造。
     *
     * @return array{acc:int, ip:int}
     */
    public static function recentFails(string $username): array
    {
        $minutes = (int) setting('login_lock_minutes', 60);
        if ($minutes <= 0) {
            return ['acc' => 0, 'ip' => 0];
        }
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);
        $table = Db::t('login_logs');

        $acc = (int) Db::val(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE `status` = ? AND `created_at` >= ? AND `username` = ?',
            ['fail', $since, $username]
        );
        $ip = (int) Db::val(
            'SELECT COUNT(*) FROM ' . $table
            . ' WHERE `status` = ? AND `created_at` >= ? AND `ip` = ?',
            ['fail', $since, client_ip()]
        );

        return ['acc' => $acc, 'ip' => $ip];
    }

    /** 该账号当前是否处于锁定状态 */
    public static function isLocked(string $username): bool
    {
        $limit = (int) setting('login_fail_limit', 5);
        if ($limit <= 0) {
            return false;
        }
        $f = self::recentFails($username);
        // 账号维度按阈值；IP 维度放宽 4 倍，避免办公网 NAT 互相牵连
        return $f['acc'] >= $limit || $f['ip'] >= $limit * 4;
    }

    /**
     * 尝试登录。
     * @return array{ok:bool, msg:string, user:?array}
     */
    public static function attempt(string $username, string $password): array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['ok' => false, 'msg' => '请输入账号和密码', 'user' => null];
        }

        if (self::isLocked($username)) {
            $minutes = max(1, (int) setting('login_lock_minutes', 60));
            self::log($username, 'locked', '连续登录失败次数过多');
            return ['ok' => false, 'msg' => "失败次数过多，请 {$minutes} 分钟后再试", 'user' => null];
        }

        $u = Db::row(
            'SELECT * FROM ' . Db::t('users')
            . ' WHERE `username` = ? OR (`email` IS NOT NULL AND `email` = ?) LIMIT 1',
            [$username, $username]
        );

        if ($u === null) {
            self::log($username, 'fail', '账号不存在');
            return ['ok' => false, 'msg' => '账号或密码错误', 'user' => null];
        }

        if ((int) $u['status'] !== 1) {
            self::log($username, 'fail', '账号已被禁用', (int) $u['id']);
            return ['ok' => false, 'msg' => '该账号已被禁用', 'user' => null];
        }

        if (!password_verify($password, (string) $u['password_hash'])) {

            self::log($username, 'fail', '密码错误', (int) $u['id']);
            return ['ok' => false, 'msg' => '账号或密码错误', 'user' => null];
        }

        // 密码算法升级
        if (password_needs_rehash((string) $u['password_hash'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], '`id` = ?', [(int) $u['id']]);
        }

        // 登录成功：清掉历史失败计数（数据库方案下无需重置，窗口自然过期）
        self::establish($u);
        return ['ok' => true, 'msg' => '登录成功', 'user' => $u];
    }

    /** 建立登录态 */
    public static function establish(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['login_at'] = time();
        self::$loaded = false;
        self::$user = null;

        Db::update('users', [
            'last_login_at' => now(),
            'last_login_ip' => client_ip(),
            'login_count'   => (int) $user['login_count'] + 1,
            'updated_at'    => now(),
        ], '`id` = ?', [(int) $user['id']]);

        self::log((string) $user['username'], 'ok', '登录成功', (int) $user['id']);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], (bool) $p['secure'], (bool) $p['httponly']);
        }
        session_destroy();
        self::$loaded = true;
        self::$user = null;
    }

    /** 写登录日志 */
    public static function log(string $username, string $status, string $msg, int $userId = 0): void
    {
        try {
            Db::insert('login_logs', [
                'user_id'    => $userId,
                'username'   => mb_substr($username, 0, 32),
                'ip'         => client_ip(),
                'ua'         => user_agent(),
                'status'     => $status,
                'msg'        => mb_substr($msg, 0, 128),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // 日志失败不阻断登录
        }
    }

    // ---------- CSRF ----------

    public static function csrfToken(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_token" value="' . e(self::csrfToken()) . '">';
    }

    public static function verifyCsrf(): bool
    {
        $sent = (string) ($_POST['_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        $real = (string) ($_SESSION['_csrf'] ?? '');
        return $real !== '' && $sent !== '' && hash_equals($real, $sent);
    }

    /** POST 请求统一校验 CSRF，失败直接 419 */
    public static function guardCsrf(): void
    {
        if (is_post() && !self::verifyCsrf()) {
            http_response_code(419);
            if (self::wantsJson()) {
                json_out(['ok' => false, 'msg' => '会话已过期，请刷新页面重试'], 419);
            }
            echo '<!doctype html><meta charset="utf-8"><div style="font-family:sans-serif;text-align:center;padding:100px">'
               . '<h3>会话已过期</h3><p style="color:#888">请刷新页面后重试。</p>'
               . '<p><a href="javascript:history.back()" style="color:#07C160">返回</a></p></div>';
            exit;
        }
    }

    public static function wantsJson(): bool
    {
        $accept = (string) ($_SERVER['HTTP_ACCEPT'] ?? '');
        return stripos($accept, 'application/json') !== false
            || (string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';
    }

    // ---------- 中间件 ----------

    public static function requireLogin(): void
    {
        if (self::check()) {
            return;
        }
        $back = $_SERVER['REQUEST_URI'] ?? '/';
        if (self::wantsJson()) {
            json_out(['ok' => false, 'msg' => '请先登录', 'code' => 'need_login'], 401);
        }
        redirect('/login?redirect=' . rawurlencode($back));
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (self::isAdmin()) {
            // 后台任意页面都顺带跑一次过期清理（内部按小时节流）
            housekeeping();
            return;
        }
        http_response_code(403);
        if (self::wantsJson()) {
            json_out(['ok' => false, 'msg' => '无权限'], 403);
        }
        view('errors/403', [], 'front');
        exit;
    }
}
