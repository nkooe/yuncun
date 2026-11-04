<?php
/**
 * 唯一入口。
 */
declare(strict_types=1);

require __DIR__ . '/app/bootstrap.php';

// 未安装 → 安装向导
if (!APP_INSTALLED) {
    header('Location: ' . detect_base_path() . '/install.php');
    exit;
}

// 静态资源由 Web 服务器直接返回；走 PHP 时快速放行
$rawPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (str_starts_with($rawPath, '/assets/')) {
    http_response_code(404);
    exit;
}

// POST 统一 CSRF 校验
Auth::guardCsrf();

$router = new Router();

// ---- 前台 ----
$router->get('/', 'FrontController@index');
$router->get('/categories', 'FrontController@categories');
$router->get('/folder/{id}', 'FrontController@folder');
$router->get('/search', 'FrontController@search');
$router->get('/download/{id}', 'DownloadController@file');

// 分享（无需登录）
$router->get('/s/{token}', 'ShareController@show');
$router->post('/s/{token}', 'ShareController@unlock');
$router->get('/s/{token}/download', 'ShareController@download');

// ---- 认证 ----
$router->get('/login', 'AuthController@showLogin');
$router->post('/login', 'AuthController@doLogin');
$router->get('/register', 'AuthController@showRegister');
$router->post('/register', 'AuthController@doRegister');
$router->get('/logout', 'AuthController@logout');

// ---- 用户中心 ----
$router->get('/user', 'UserController@index', ['requireLogin']);
$router->get('/user/downloads', 'UserController@downloads', ['requireLogin']);
$router->get('/user/logins', 'UserController@logins', ['requireLogin']);
$router->get('/user/profile', 'ProfileController@show', ['requireLogin']);
$router->post('/user/profile', 'ProfileController@save', ['requireLogin']);
$router->post('/user/profile/password', 'ProfileController@changePassword', ['requireLogin']);

// ---- 分片上传 API ----
$router->post('/api/upload/init', 'UploadController@init', ['requireAdmin']);
$router->post('/api/upload/chunk', 'UploadController@chunk', ['requireAdmin']);
$router->post('/api/upload/finish', 'UploadController@finish', ['requireAdmin']);
$router->post('/api/upload/abort', 'UploadController@abort', ['requireAdmin']);

// ---- 后台 ----
$router->get('/admin', 'AdminController@dashboard', ['requireAdmin']);

// 资源
$router->get('/admin/files', 'AdminFileController@index', ['requireAdmin']);
$router->post('/admin/files/update', 'AdminFileController@update', ['requireAdmin']);
$router->post('/admin/files/delete', 'AdminFileController@delete', ['requireAdmin']);
$router->post('/admin/files/batch-delete', 'AdminFileController@batchDelete', ['requireAdmin']);
$router->post('/admin/files/batch-move', 'AdminFileController@batchMove', ['requireAdmin']);

// 文件夹
$router->get('/admin/folders', 'AdminFolderController@index', ['requireAdmin']);
$router->post('/admin/folders/save', 'AdminFolderController@save', ['requireAdmin']);
$router->post('/admin/folders/delete', 'AdminFolderController@delete', ['requireAdmin']);

// 用户与等级
$router->get('/admin/users', 'AdminUserController@index', ['requireAdmin']);
$router->post('/admin/users/update', 'AdminUserController@update', ['requireAdmin']);
$router->post('/admin/users/delete', 'AdminUserController@delete', ['requireAdmin']);

$router->get('/admin/levels', 'AdminLevelController@index', ['requireAdmin']);
$router->post('/admin/levels/save', 'AdminLevelController@save', ['requireAdmin']);
$router->post('/admin/levels/delete', 'AdminLevelController@delete', ['requireAdmin']);

// 回收站
$router->get('/admin/trash', 'AdminTrashController@index', ['requireAdmin']);
$router->post('/admin/trash/restore', 'AdminTrashController@restore', ['requireAdmin']);
$router->post('/admin/trash/restore-folder', 'AdminTrashController@restoreFolder', ['requireAdmin']);
$router->post('/admin/trash/purge', 'AdminTrashController@purge', ['requireAdmin']);
$router->post('/admin/trash/purge-folder', 'AdminTrashController@purgeFolder', ['requireAdmin']);
$router->post('/admin/trash/empty', 'AdminTrashController@empty', ['requireAdmin']);

// 记录
$router->get('/admin/logs', 'AdminLogController@index', ['requireAdmin']);
$router->post('/admin/logs/clean', 'AdminLogController@clean', ['requireAdmin']);

// 分享
$router->get('/admin/shares', 'AdminShareController@index', ['requireAdmin']);
$router->post('/admin/shares/create', 'AdminShareController@create', ['requireAdmin']);
$router->post('/admin/shares/revoke', 'AdminShareController@revoke', ['requireAdmin']);

// 设置
$router->get('/admin/settings', 'AdminController@settings', ['requireAdmin']);
$router->post('/admin/settings', 'AdminController@saveSettings', ['requireAdmin']);

$router->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
