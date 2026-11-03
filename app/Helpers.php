<?php
/**
 * 全局函数库：转义、URL、设置、Flash、格式化。
 */
declare(strict_types=1);

/** HTML 转义（所有输出必须经过） */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** 站点根路径，如 '' 或 '/pan' */
function base_path(): string
{
    static $bp = null;
    if ($bp === null) {
        if (defined('APP_INSTALLED') && APP_INSTALLED) {
            $bp = (string) Config::get('app.base_path', '');
        } else {
            $bp = detect_base_path();
        }
        $bp = rtrim($bp, '/');
    }
    return $bp;
}

/** 生成站内绝对路径：url('/admin') => /pan/admin */
function url(string $path = '/'): string
{
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }
    if ($path === '' || $path === '/') {
        return base_path() . '/';
    }
    return base_path() . '/' . ltrim($path, '/');
}

/**
 * 静态资源 URL，附带文件修改时间作为版本号。
 * 文件更新后 URL 随之变化，可绕过浏览器与 CDN 的强缓存。
 */
function asset(string $path): string
{
    $rel = ltrim($path, '/');
    $file = dirname(__DIR__) . '/assets/' . $rel;
    $ver = is_file($file) ? (string) filemtime($file) : APP_VERSION;
    return url('/assets/' . $rel) . '?v=' . $ver;
}

/** 302 跳转并终止 */
function redirect(string $to): never
{
    header('Location: ' . url($to));
    exit;
}

/** 读取系统设置（settings 表，按请求缓存） */
function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        if (defined('APP_INSTALLED') && APP_INSTALLED) {
            try {
                foreach (Db::all('SELECT `k`,`v` FROM ' . Db::t('settings')) as $row) {
                    $decoded = json_decode((string) $row['v'], true);
                    $cache[$row['k']] = json_last_error() === JSON_ERROR_NONE ? $decoded : $row['v'];
                }
            } catch (Throwable) {
                $cache = [];
            }
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

/** 写入系统设置 */
function setting_set(string $key, mixed $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    Db::q(
        'INSERT INTO ' . Db::t('settings') . ' (`k`,`v`) VALUES (?,?) '
        . 'ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)',
        [$key, $json]
    );
}

/** 一次性提示消息 */
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $message];
}

function flash_take(): array
{
    $list = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $list;
}

/** 字节数转可读大小 */
function bytes_human(int|float $n, int $precision = 1): string
{
    $n = (float) $n;
    if ($n <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
    $i = (int) floor(log($n, 1024));
    $i = min($i, count($units) - 1);
    $v = $n / (1024 ** $i);
    return ($i === 0 ? (string) (int) $v : number_format($v, $precision)) . ' ' . $units[$i];
}

/** 相对时间：刚刚 / 5 分钟前 / 昨天 */
function time_human(?string $dt): string
{
    if (empty($dt)) {
        return '—';
    }
    $ts = strtotime($dt);
    if ($ts === false) {
        return '—';
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return date('Y-m-d H:i', $ts);
    }
    if ($diff < 60) {
        return '刚刚';
    }
    if ($diff < 3600) {
        return intdiv($diff, 60) . ' 分钟前';
    }
    if ($diff < 86400 && date('Y-m-d', $ts) === date('Y-m-d')) {
        return '今天 ' . date('H:i', $ts);
    }
    if (date('Y-m-d', $ts) === date('Y-m-d', time() - 86400)) {
        return '昨天 ' . date('H:i', $ts);
    }
    return date('Y-m-d H:i', $ts);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** 客户端 IP（考虑反向代理） */
function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            $ip = explode(',', (string) $_SERVER[$k])[0];
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function user_agent(): string
{
    return mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/** 取 POST 字段（去首尾空白） */
function post(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get(string $key, string $default = ''): string
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function get_int(string $key, int $default = 0): int
{
    return (int) ($_GET[$key] ?? $default);
}

/** 简单分页数据 */
function paginate(int $total, int $page, int $perPage): array
{
    $perPage = max(1, $perPage);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = max(1, min($page, $pages));
    return [
        'total'    => $total,
        'page'     => $page,
        'per_page' => $perPage,
        'pages'    => $pages,
        'offset'   => ($page - 1) * $perPage,
        'has_prev' => $page > 1,
        'has_next' => $page < $pages,
    ];
}

/** 渲染视图 */
function view(string $template, array $data = [], ?string $layout = null): void
{
    extract($data, EXTR_SKIP);
    $tplFile = APP_PATH . '/views/' . $template . '.php';
    if (!is_file($tplFile)) {
        http_response_code(500);
        echo 'View not found: ' . e($template);
        return;
    }
    if ($layout === null) {
        require $tplFile;
        return;
    }
    ob_start();
    require $tplFile;
    $content = ob_get_clean();
    require APP_PATH . '/views/layout/' . $layout . '.php';
}

/** 输出 JSON 并终止 */
function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** 文件类型 → 展示用的短标签与配色 */
function file_badge(string $ext): array
{
    $ext = strtolower($ext);
    $map = [
        'doc'  => ['DOC', '#2B7CD3'], 'docx' => ['DOC', '#2B7CD3'],
        'xls'  => ['XLS', '#217346'], 'xlsx' => ['XLS', '#217346'],
        'ppt'  => ['PPT', '#D24726'], 'pptx' => ['PPT', '#D24726'],
        'pdf'  => ['PDF', '#E5252A'],
        'zip'  => ['ZIP', '#F5A623'], 'rar'  => ['RAR', '#F5A623'],
        '7z'   => ['7Z', '#F5A623'],  'tar'  => ['TAR', '#F5A623'],
        'gz'   => ['GZ', '#F5A623'],
        'jpg'  => ['IMG', '#07C160'], 'jpeg' => ['IMG', '#07C160'],
        'png'  => ['IMG', '#07C160'], 'gif'  => ['IMG', '#07C160'],
        'webp' => ['IMG', '#07C160'], 'svg'  => ['IMG', '#07C160'],
        'mp4'  => ['VID', '#9B59B6'], 'mkv'  => ['VID', '#9B59B6'],
        'avi'  => ['VID', '#9B59B6'], 'mov'  => ['VID', '#9B59B6'],
        'mp3'  => ['AUD', '#E67E22'], 'wav'  => ['AUD', '#E67E22'],
        'flac' => ['AUD', '#E67E22'],
        'txt'  => ['TXT', '#7A7E83'], 'md'   => ['TXT', '#7A7E83'],
        'exe'  => ['EXE', '#576B95'], 'msi'  => ['EXE', '#576B95'],
        'apk'  => ['APK', '#3DDC84'],
    ];
    if (isset($map[$ext])) {
        return $map[$ext];
    }
    $label = $ext === '' ? 'FILE' : mb_strtoupper(mb_substr($ext, 0, 4));
    return [$label, '#9AA0A6'];
}

/** 扩展名 → MIME（服务器未装 fileinfo 时的降级方案） */
function mime_for_ext(string $ext): string
{
    static $map = [
        'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
        'html' => 'text/html', 'htm' => 'text/html', 'css' => 'text/css',
        'js' => 'application/javascript', 'json' => 'application/json',
        'xml' => 'application/xml', 'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
        '7z' => 'application/x-7z-compressed', 'tar' => 'application/x-tar',
        'gz' => 'application/gzip', 'bz2' => 'application/x-bzip2',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'bmp' => 'image/bmp', 'ico' => 'image/x-icon',
        'mp4' => 'video/mp4', 'mkv' => 'video/x-matroska', 'avi' => 'video/x-msvideo',
        'mov' => 'video/quicktime', 'webm' => 'video/webm',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'flac' => 'audio/flac',
        'aac' => 'audio/aac', 'ogg' => 'audio/ogg',
        'exe' => 'application/vnd.microsoft.portable-executable',
        'msi' => 'application/x-msi', 'apk' => 'application/vnd.android.package-archive',
        'iso' => 'application/x-iso9660-image', 'dmg' => 'application/x-apple-diskimage',
        'ttf' => 'font/ttf', 'otf' => 'font/otf', 'woff' => 'font/woff', 'woff2' => 'font/woff2',
    ];
    $ext = strtolower(ltrim($ext, '.'));
    return $map[$ext] ?? 'application/octet-stream';
}

// ==========================================================================
// 后台操作审计
// ==========================================================================

/**
 * 写入一条后台操作日志。
 * 失败时静默（不能因为记日志失败而中断业务），但会写 PHP 错误日志。
 *
 * @param string $action     动作标识，如 create_folder
 * @param string $targetType 对象类型，如 folder / level / share / file / user / setting
 * @param int    $targetId   对象 id
 * @param string $detail     人类可读描述
 */
/**
 * 后台写操作统一返回：fetch 请求返回 JSON，表单提交则 flash + 跳回列表。
 *
 * @param string $back 表单提交时跳回的列表页路径
 */
function admin_respond(bool $ok, string $msg, string $back, array $extra = []): never
{
    if (Auth::wantsJson()) {
        json_out(['ok' => $ok, 'msg' => $msg] + $extra, $ok ? 200 : 400);
    }
    flash($ok ? 'success' : 'error', $msg);
    redirect($back);
}

function admin_audit(string $action, string $targetType, int $targetId, string $detail): void
{
    try {
        Db::insert('admin_logs', [
            'admin_id'    => Auth::id(),
            'action'      => mb_substr($action, 0, 64),
            'target_type' => mb_substr($targetType, 0, 32),
            'target_id'   => max(0, $targetId),
            'detail'      => mb_substr($detail, 0, 500),
            'ip'          => client_ip(),
            'created_at'  => now(),
        ]);
    } catch (Throwable $e) {
        error_log('[wxpan] admin_audit failed: ' . $e->getMessage());
    }
}

// ==========================================================================
// 头像
// ==========================================================================

/**
 * 用户头像 HTML。
 * - 管理员：金色渐变 + 皇冠角标
 * - 有等级：等级色渐变 + 等级色圆点
 * - 无等级：灰蓝渐变
 *
 * @param array  $user 至少包含 nickname / username / role / level_id
 * @param string $size xs | sm | md | lg
 */
function avatar_html(array $user, string $size = 'md'): string
{
    $nick = trim((string) ($user['nickname'] ?? ''));
    if ($nick === '') {
        $nick = trim((string) ($user['username'] ?? ''));
    }
    $initial = mb_strtoupper(mb_substr($nick !== '' ? $nick : '?', 0, 1));

    $isAdmin = ($user['role'] ?? '') === 'admin';
    $lv = level_by_id((int) ($user['level_id'] ?? 0));

    if ($isAdmin) {
        $color = '#F5A623';
        $badge = '<svg class="av-badge" viewBox="0 0 24 24" aria-hidden="true">'
            . '<path d="M3.5 18.5 5 8.2l4.6 4.1L12 5.2l2.4 7.1L19 8.2l1.5 10.3z" fill="#fff"/></svg>';
    } elseif ($lv !== null) {
        $color = (string) ($lv['color'] !== '' ? $lv['color'] : '#86909C');
        $badge = '<span class="av-badge av-dot" style="--ac:' . e($color) . '"></span>';
    } else {
        $color = '#86909C';
        $badge = '';
    }

    $cls = 'avatar avatar-' . $size . ($isAdmin ? ' is-admin' : '');

    return '<span class="' . $cls . '" style="--ac:' . e($color) . '" title="' . e($nick) . '">'
        . '<span class="av-txt">' . e($initial) . '</span>'
        . $badge
        . '</span>';
}

// ==========================================================================
// 用户等级
// ==========================================================================

/** 全部等级（按 sort 升序） */
function levels_all(): array
{
    static $cache = null;
    if ($cache === null) {
        try {
            $cache = Db::all('SELECT * FROM ' . Db::t('levels') . ' ORDER BY `sort` ASC, `id` ASC');
        } catch (Throwable) {
            $cache = [];
        }
    }
    return $cache;
}

function level_by_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    foreach (levels_all() as $lv) {
        if ((int) $lv['id'] === $id) {
            return $lv;
        }
    }
    return null;
}

/** 用户的等级权重（sort 值）；无等级或游客为 -1 */
function user_level_sort(?array $user = null): int
{
    $user = $user ?? Auth::user();
    if ($user === null) {
        return -1;
    }
    $lv = level_by_id((int) ($user['level_id'] ?? 0));
    return $lv === null ? -1 : (int) $lv['sort'];
}

/**
 * 当前访客能否访问「需要 $needLevelId 等级」的资源。
 * needLevelId = 0 表示不限；管理员不受限制。
 */
function can_access_level(int $needLevelId, ?array $user = null): bool
{
    if ($needLevelId <= 0) {
        return true;
    }
    $user = $user ?? Auth::user();
    if ($user !== null && ($user['role'] ?? '') === 'admin') {
        return true;
    }
    $need = level_by_id($needLevelId);
    if ($need === null) {
        return true; // 等级已被删除，视为不限
    }
    return user_level_sort($user) >= (int) $need['sort'];
}

// ==========================================================================
// 下载配额
// ==========================================================================



/**
 * 读取配额状态，必要时按周期懒重置。
 * @return array{unlimited:bool,quota:int,used:int,left:int,period:string,reset_at:?string,percent:int}
 */
function quota_state(array $user): array
{
    $uid     = (int) $user['id'];
    $period  = (string) ($user['quota_period'] ?? 'total');
    $quota   = (int) ($user['download_quota'] ?? 0);
    $used    = (int) ($user['download_used'] ?? 0);
    $resetAt = $user['quota_reset_at'] ?? null;

    if ($period !== 'total') {
        $needReset = empty($resetAt) || strtotime((string) $resetAt) <= time();
        if ($needReset) {
            $next = $period === 'daily'
                ? strtotime('tomorrow 00:00:00')
                : strtotime('first day of next month 00:00:00');
            Db::update('users', [
                'download_used'  => 0,
                'quota_reset_at' => date('Y-m-d H:i:s', $next),
                'updated_at'     => now(),
            ], '`id` = ?', [$uid]);
            $used = 0;
            $resetAt = date('Y-m-d H:i:s', $next);
        }
    }

    return [
        'unlimited' => $quota === 0,
        'quota'     => $quota,
        'used'      => $used,
        'left'      => $quota === 0 ? -1 : max(0, $quota - $used),
        'period'    => $period,
        'reset_at'  => $resetAt,
        'percent'   => $quota === 0 ? 0 : min(100, (int) round($used / max(1, $quota) * 100)),
    ];
}

/**
 * 扣减一次下载配额。必须先扣再输出，防止并发绕过。
 * @return array{ok:bool,msg:string,state:array}
 */
function quota_consume(array $user): array
{
    $st = quota_state($user);

    if ($st['unlimited']) {
        return ['ok' => true, 'msg' => '', 'state' => $st];
    }
    if ($st['left'] <= 0) {
        return ['ok' => false, 'msg' => '下载次数已用完', 'state' => $st];
    }

    Db::exec(
        'UPDATE ' . Db::t('users') . ' SET `download_used` = `download_used` + 1, `updated_at` = ? WHERE `id` = ?',
        [now(), (int) $user['id']]
    );

    $st['used']++;
    $st['left'] = max(0, $st['left'] - 1);
    $st['percent'] = min(100, (int) round($st['used'] / max(1, $st['quota']) * 100));

    return ['ok' => true, 'msg' => '', 'state' => $st];
}

/**
 * 站点图标（内联 SVG）。
 *
 * 仓库约定不带图片文件，所以 logo 与 favicon 不能作为 .svg 资源存在。
 * 直接内联进 HTML，既不违反约定，也不额外产生请求。
 */
function site_favicon_svg(): string
{
    // 站点图标：蓝色云朵 + 下载箭头，PNG 以 base64 内联。
    // 仓库约定不带图片文件，所以走 data URI。
    // 返回的是完整的 data URI，调用方直接放进 <link rel="icon" href>。
    static $b64 = null;
    if ($b64 === null) {
        $b64 = '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';
    }
    return 'data:image/png;base64,' . $b64;
}

/** 站点 logo（内联 SVG，尺寸由调用方通过 class 控制） */
function site_logo_svg(string $class = 'logo-img'): string
{
    // 站点 logo：与图标同一张图。原图 238x200 是横图，
    // 而容器是 27x27 / 30x30 的方块，必须 object-fit: contain，
    // 否则会被拉扁。
    static $b64 = null;
    if ($b64 === null) {
        $b64 = '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';
    }
    return '<img class="' . e($class) . '" alt="" src="data:image/png;base64,' . $b64 . '"'
        . ' style="object-fit:contain">';
}
/**
 * 回收站与日志的定期清理。
 *
 * 原先这两件事各挂在对应页面上：只有打开 /admin/trash 才清回收站，
 * 只有打开 /admin/logs 才清日志。管理员几个月不看这两个页面，
 * 过期数据就一直在库里和磁盘上堆着。
 *
 * 改成挂在后台任意页面的中间件上，用文件锁每小时最多跑一次。
 * 用文件而不是 SESSION：不同管理员会话各自独立，用 SESSION
 * 会让每个会话都触发一轮。
 *
 * @param bool $force 忽略节流立即执行（管理员主动打开回收站/日志页时用）
 */
function housekeeping(bool $force = false): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $dir = RUNTIME_PATH . '/cache';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        return;
    }
    $lockFile = $dir . '/housekeeping.lock';

    // 先判断文件是否存在，再 fopen('c')。
    // 反过来写会有坑：fopen('c') 会创建文件并把 mtime 设成当前时间，
    // 紧接着读 mtime 就得到「刚刚跑过」，首次运行反而被节流跳过。
    $exists = is_file($lockFile);

    $fp = @fopen($lockFile, 'c');
    if ($fp === false) {
        return;
    }

    // 非阻塞独占锁：已有其他请求在清理时直接放弃，避免并发重复执行
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        @fclose($fp);
        return;
    }

    try {
        if (!$force && $exists) {
            $last = (int) @filemtime($lockFile);
            if ($last > 0 && time() - $last < 3600) {
                return;
            }
        }
        @touch($lockFile);
        $done = true;

        trash_gc();
        log_gc();
    } catch (Throwable) {
        // 清理失败不能影响正常页面
    } finally {
        @flock($fp, LOCK_UN);
        @fclose($fp);
    }
}

/** 永久删除超过保留期的回收站条目 */
function trash_gc(): void
{
    $days = (int) setting('recycle_days', 30);
    if ($days <= 0) {
        return;
    }
    $before = date('Y-m-d H:i:s', time() - $days * 86400);

    $ids = array_column(
        Db::all(
            'SELECT `id` FROM ' . Db::t('files') . ' WHERE `deleted_at` IS NOT NULL AND `deleted_at` < ?',
            [$before]
        ),
        'id'
    );
    if ($ids) {
        AdminFileController::purge(['ids' => $ids]);
    }

    Db::exec(
        'DELETE FROM ' . Db::t('folders') . ' WHERE `deleted_at` IS NOT NULL AND `deleted_at` < ?',
        [$before]
    );
}

/** 清理超过保留期的下载、登录与操作日志 */
function log_gc(): void
{
    $days = (int) setting('log_keep_days', 30);
    if ($days <= 0) {
        return;
    }
    $before = date('Y-m-d H:i:s', time() - $days * 86400);

    foreach (['downloads', 'login_logs', 'admin_logs'] as $t) {
        try {
            Db::exec('DELETE FROM ' . Db::t($t) . ' WHERE `created_at` < ?', [$before]);
        } catch (Throwable) {
        }
    }
}
