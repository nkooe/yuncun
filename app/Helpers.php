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
/**
 * 把后台设置的主题色变成 CSS 变量。
 *
 * 原来 theme_color 只进了 <meta name="theme-color">，那是浏览器地址栏的颜色，
 * 网页本身的配色写死在 app.css 的 :root 里，用户在后台改了没任何反应。
 * 这里根据主色算出深色和浅色两个变体，注入覆盖。
 */
function theme_css_vars(): string
{
    $hex = (string) setting('theme_color', '#07C160');
    if (!preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $m)) {
        $hex = '#07C160';
        $m = [1 => '07C160'];
    }

    $ch = str_split($m[1], 2);
    $r = hexdec($ch[0]); $g = hexdec($ch[1]); $b = hexdec($ch[2]);

    // 深色：整体压暗 8%，用作按下态
    $dk = static fn(int $c): int => max(0, min(255, (int) round($c * 0.92)));
    // 浅色：往白色里混 92%，用作浅底
    $lt = static fn(int $c): int => (int) round($c + (255 - $c) * 0.92);

    $dark  = sprintf('#%02X%02X%02X', $dk($r), $dk($g), $dk($b));
    $light = sprintf('#%02X%02X%02X', $lt($r), $lt($g), $lt($b));

    return ':root{--green:' . $hex . ';--green-d:' . $dark . ';--green-l:' . $light . '}';
}
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
