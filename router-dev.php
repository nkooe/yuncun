<?php
/**
 * 仅供 PHP 内置服务器开发调试使用：
 *   php -S 127.0.0.1:8088 -t . router-dev.php
 *
 * 生产环境（Apache/Nginx/IIS）不需要本文件，伪静态规则见
 * .htaccess / nginx.conf.txt / web.config
 */
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

// 真实存在的文件（含 .php）交给内置服务器处理
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
