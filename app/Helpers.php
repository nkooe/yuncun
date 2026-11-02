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
