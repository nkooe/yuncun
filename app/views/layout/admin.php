<?php
/**
 * 后台布局：左侧固定菜单 + 右侧内容，移动端抽屉。
 * 变量：$content, $active
 */
$u = Auth::user();

// 分组菜单：视觉上按职责归类，避免八九个条目平铺
$navGroups = [
    ['title' => '', 'items' => [
        ['key' => 'dashboard', 'label' => '仪表盘', 'href' => '/admin', 'icon' => 'grid'],
    ]],
    ['title' => '内容', 'items' => [
        ['key' => 'files',   'label' => '资源管理', 'href' => '/admin/files', 'icon' => 'file'],
        ['key' => 'folders', 'label' => '文件夹',   'href' => '/admin/folders', 'icon' => 'folder'],
    ]],
    ['title' => '用户', 'items' => [
        ['key' => 'users',  'label' => '用户管理', 'href' => '/admin/users', 'icon' => 'user'],
        ['key' => 'levels', 'label' => '用户等级', 'href' => '/admin/levels', 'icon' => 'level'],
    ]],
    ['title' => '运营', 'items' => [
        ['key' => 'shares', 'label' => '分享管理', 'href' => '/admin/shares', 'icon' => 'share'],
        ['key' => 'trash',  'label' => '回收站',   'href' => '/admin/trash', 'icon' => 'trash'],
        ['key' => 'logs',   'label' => '记录',     'href' => '/admin/logs', 'icon' => 'clock'],
    ]],
    ['title' => '系统', 'items' => [
        ['key' => 'settings', 'label' => '系统设置', 'href' => '/admin/settings', 'icon' => 'gear'],
    ]],
];
$icons = [
    'grid'   => '<rect x="3.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="3.5" width="7" height="7" rx="1.6"/><rect x="3.5" y="13.5" width="7" height="7" rx="1.6"/><rect x="13.5" y="13.5" width="7" height="7" rx="1.6"/>',
    'file'   => '<path d="M6 3.5h7l5 5V20a1 1 0 01-1 1H6a1 1 0 01-1-1V4.5a1 1 0 011-1z"/><path d="M13 3.5V9h5"/>',
    'folder' => '<path d="M3.5 7A1.5 1.5 0 015 5.5h4l1.8 2H19A1.5 1.5 0 0120.5 9v9A1.5 1.5 0 0119 19.5H5A1.5 1.5 0 013.5 18V7z"/>',
    'user'   => '<circle cx="12" cy="8" r="3.6"/><path d="M4.8 20c.6-3.7 3.6-5.6 7.2-5.6s6.6 1.9 7.2 5.6"/>',
    'trash'  => '<path d="M4.5 6.5h15"/><path d="M9 6.5V4.8a1 1 0 011-1h4a1 1 0 011 1v1.7"/><path d="M6.5 6.5l.8 13a1 1 0 001 .9h7.4a1 1 0 001-.9l.8-13"/>',
    'clock'  => '<circle cx="12" cy="12" r="8.2"/><path d="M12 7.5V12l3.2 2"/>',
    'gear'   => '<circle cx="12" cy="12" r="3"/><path d="M12 3.5v2M12 18.5v2M3.5 12h2M18.5 12h2M6 6l1.4 1.4M16.6 16.6L18 18M18 6l-1.4 1.4M7.4 16.6L6 18"/>',
    'level'  => '<path d="M12 3.6l2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8z"/>',
    'share'  => '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/>',
];
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e(setting('theme_color', '#07C160')) ?>">
<meta name="csrf-token" content="<?= e(Auth::csrfToken()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<title><?= e($pageTitle ?? '后台 · ' . setting('site_name', '云盘')) ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="page-admin">

<aside class="adm-side" id="admSide">
  <div class="adm-brand">
    <img class="logo-img logo-adm" src="<?= asset('logo.svg') ?>" alt="" width="27" height="27">
    <span class="adm-title"><?= e(setting('site_name', '云盘')) ?></span>
  </div>
  <nav class="adm-menu">
    <?php foreach ($navGroups as $gi => $g): ?>
      <?php if ($g['title'] !== ''): ?>
        <span class="adm-group-t"><?= e($g['title']) ?></span>
      <?php endif; ?>
      <?php foreach ($g['items'] as $m): ?>
        <a class="adm-item <?= (($active ?? '') === $m['key']) ? 'active' : '' ?>" href="<?= url($m['href']) ?>">
          <svg class="adm-ico" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><?= $icons[$m['icon']] ?></svg>
          <span><?= e($m['label']) ?></span>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </nav>
  <div class="adm-foot">
    <a class="adm-item" href="<?= url('/') ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>
      <span>查看前台</span>
    </a>
    <div class="adm-user">
      <?= avatar_html($u, 'sm') ?>
      <div class="adm-user-i">
        <b><?= e($u['nickname'] ?? $u['username'] ?? '') ?></b>
        <small>管理员</small>
      </div>
      <a class="adm-logout" href="<?= url('/logout') ?>" title="退出登录">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M15 8l4 4-4 4"/><path d="M19 12H9"/><path d="M12 4H6a1 1 0 00-1 1v14a1 1 0 001 1h6"/></svg>
      </a>
    </div>
  </div>
</aside>

<div class="adm-backdrop" id="admBackdrop"></div>

<div class="adm-main">
  <header class="adm-top">
    <button class="adm-burger" id="admBurger" aria-label="菜单">
      <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
    </button>
    <h1><?= e($pageTitle ?? '后台管理') ?></h1>
    <div class="adm-top-r">
      <a class="adm-chip" href="<?= url('/') ?>">前台</a>
    </div>
  </header>

  <div class="adm-content">
    <?= $content ?>
  </div>
</div>

<div class="toast-host" id="toastHost">
<?php foreach (flash_take() as $f): ?>
  <div class="toast toast-<?= e($f['type'] === 'error' ? 'err' : 'ok') ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
</div>

<script src="<?= asset('app.js') ?>"></script>
</body>
</html>
