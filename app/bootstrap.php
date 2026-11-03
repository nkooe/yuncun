<?php
/**
 * 应用引导：常量、错误处理、自动加载、session
 * 所有请求（除安装向导）都从这里进入。
 */
declare(strict_types=1);

define('APP_ROOT', str_replace('\\', '/', dirname(__DIR__)));
define('APP_PATH', APP_ROOT . '/app');
define('DATA_PATH', APP_ROOT . '/data');
define('STORAGE_PATH', APP_ROOT . '/storage');
define('RUNTIME_PATH', APP_ROOT . '/runtime');
define('APP_VERSION', '1.1.26');
define('APP_NAME', '云盘');

// 运行环境
date_default_timezone_set('Asia/Shanghai');
mb_internal_encoding('UTF-8');
ini_set('default_charset', 'UTF-8');

// 是否已安装
define('APP_INSTALLED', is_file(DATA_PATH . '/config.php') && is_file(DATA_PATH . '/install.lock'));

// 错误策略：未安装时全量显示便于排障；安装后关闭显示、写日志
if (APP_INSTALLED) {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
    ini_set('display_errors', '0');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}
ini_set('log_errors', '1');
if (is_dir(RUNTIME_PATH . '/logs')) {
    ini_set('error_log', RUNTIME_PATH . '/logs/php-error.log');
}

// 自动加载：app/Xxx.php 与 app/controllers/Xxx.php
spl_autoload_register(static function (string $class): void {
    $candidates = [
        APP_PATH . '/' . $class . '.php',
        APP_PATH . '/controllers/' . $class . '.php',
    ];
    foreach ($candidates as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});

// Helpers 是函数库，显式加载
require_once APP_PATH . '/Helpers.php';

// 站点根路径（支持子目录部署，例如 /pan/）
function detect_base_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
    $dir = str_replace('\\', '/', dirname($script));
    return ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
}

// session
if (session_status() === PHP_SESSION_NONE) {
    $bp = APP_INSTALLED ? (Config::get('app.base_path', '') ?: '') : '';
    // 仅在 HTTPS 下加 Secure，避免 HTTP 调试环境拿不到 session
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => $bp === '' ? '/' : $bp . '/',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_name('WXPANSID');
    session_start();
}

// 载入配置并定义运行时设置
if (APP_INSTALLED) {
    Config::load();
    date_default_timezone_set(Config::get('app.timezone', 'Asia/Shanghai') ?: 'Asia/Shanghai');

    // 站点处于维护模式且非管理员时拦截
    if ((int) setting('maintenance_mode', 0) === 1 && !Auth::isAdmin()) {
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if (stripos($uri, '/admin') === false && stripos($uri, '/login') === false) {
            http_response_code(503);
            echo '<!doctype html><meta charset="utf-8"><title>维护中</title>'
               . '<div style="font:16px/-apple-system,\'PingFang SC\',sans-serif;text-align:center;padding:120px 24px;color:#333">'
               . '<h2 style="color:#07C160">系统维护中</h2><p style="color:#888">站点正在升级，请稍后再访问。</p></div>';
            exit;
        }
    }
}

// ==========================================================================
// 全局错误处理：不把堆栈暴露给访问者，只写日志
// ==========================================================================

set_exception_handler(static function (Throwable $e): void {
    error_log(sprintf(
        '[wxpan] %s: %s in %s:%d',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine()
    ));

    if (!headers_sent()) {
        http_response_code(500);
    }

    // JSON 接口保持 JSON 形态
    try {
        if (class_exists('Auth') && Auth::wantsJson()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'msg' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
            return;
        }
    } catch (Throwable) {
    }

    $msg = '服务器出了点问题，请稍后再试';
    $view = APP_PATH . '/views/errors/500.php';
    if (is_file($view)) {
        include $view;
    } else {
        echo '500 Internal Server Error';
    }
});

// 捕获致命错误（内存溢出、解析失败等），否则会输出白页
register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatal = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
    if (!in_array($err['type'], $fatal, true)) {
        return;
    }

    error_log(sprintf('[wxpan] FATAL: %s in %s:%d', $err['message'], $err['file'], $err['line']));

    if (headers_sent()) {
        return;
    }
    http_response_code(500);
    $msg = '服务器出了点问题，请稍后再试';
    $view = APP_PATH . '/views/errors/500.php';
    if (is_file($view)) {
        include $view;
    } else {
        echo '500 Internal Server Error';
    }
});
