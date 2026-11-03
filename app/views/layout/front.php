<?php
/**
 * 前台布局：简洁风格顶部导航 + 底部 TabBar。
 * 变量：$content, $navTitle(可选), $pageTitle(可选), $showTab(可选)
 */
$u = Auth::user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$bp = base_path();
if ($bp !== '' && str_starts_with($currentPath, $bp)) {
    $currentPath = substr($currentPath, strlen($bp));
}
$currentPath = '/' . ltrim($currentPath, '/');
$showTab = $showTab ?? true;

$tabActive = static function (array $prefixes) use ($currentPath): string {
    foreach ($prefixes as $p) {
        if ($p === '/') {
            if ($currentPath === '/') return 'active';
            continue;
        }
        if (str_starts_with($currentPath, $p)) return 'active';
    }
    return '';
};
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e(setting('theme_color', '#07C160')) ?>">
<meta name="format-detection" content="telephone=no">
<title><?= e($pageTitle ?? setting('site_name', '云盘')) ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" type="image/svg+xml" href="data:image/svg+xml;base64,<?= base64_encode(site_favicon_svg()) ?>">
</head>
<body class="page-front">
<div class="frame">

  <header class="navbar">
    <div class="navbar-inner">
      <div class="nav-side">
        <?php if (!empty($navBack)): ?>
          <a class="nav-back" href="<?= e($navBack) ?>" aria-label="返回">
            <svg viewBox="0 0 24 24" width="22" height="22"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </a>
        <?php else: ?>
          <?= site_logo_svg('logo-img logo-nav') ?>
        <?php endif; ?>
      </div>
      <h1 class="nav-title"><?= e($navTitle ?? setting('site_name', '云盘')) ?></h1>

      <nav class="pc-nav">
        <a class="<?= $tabActive(['/']) ?>" href="<?= url('/') ?>">全部资源</a>
        <a class="<?= $tabActive(['/categories', '/folder']) ?>" href="<?= url('/categories') ?>">分类</a>
        <a class="<?= $tabActive(['/search']) ?>" href="<?= url('/search') ?>">搜索</a>
      </nav>
      <div class="nav-side nav-side-r">
        <?php if (!empty($navRight)): ?>
          <?= $navRight ?>
        <?php else: ?>
          <a class="nav-icon" href="<?= url('/search') ?>" aria-label="搜索">
            <svg viewBox="0 0 24 24" width="21" height="21"><circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M16 16l4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
          </a>
        <?php endif; ?>

        <?php if ($u): ?>
          <?php
            $uNick  = (string) (($u['nickname'] ?? '') !== '' ? $u['nickname'] : $u['username']);
            $uIsAdm = ($u['role'] ?? '') === 'admin';
          ?>
          <div class="nav-user" id="navUser">
            <button class="nav-user-btn" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="navUserMenu">
              <?= avatar_html($u, 'xs') ?>
              <span class="nav-user-name"><?= e($uNick) ?></span>
              <svg class="nav-user-caret" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="nav-user-menu" id="navUserMenu" role="menu">
              <div class="nav-user-head">
                <?= avatar_html($u, 'sm') ?>
                <div class="nav-user-head-txt">
                  <b><?= e($uNick) ?></b>
                  <small>@<?= e($u['username']) ?><?= $uIsAdm ? ' · 管理员' : '' ?></small>
                </div>
              </div>
              <a role="menuitem" href="<?= url('/user/profile') ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.4"/><path d="M5 20c.6-3.6 3.4-5.4 7-5.4s6.4 1.8 7 5.4" stroke-linecap="round"/></svg>
                个人资料
              </a>
              <a role="menuitem" href="<?= url('/user') ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3.5" y="5" width="17" height="14" rx="2.2"/><path d="M3.5 10h17" stroke-linecap="round"/></svg>
                我的中心
              </a>
              <?php if ($uIsAdm): ?>
                <a role="menuitem" href="<?= url('/admin') ?>">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3.5" y="4.5" width="17" height="15" rx="2.2"/><path d="M3.5 9h17M8.5 9v10.5" stroke-linecap="round"/></svg>
                  后台管理
                </a>
              <?php endif; ?>
              <div class="nav-user-sep"></div>
              <a role="menuitem" class="danger" href="<?= url('/logout') ?>">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 8V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2h7a2 2 0 002-2v-2" stroke-linecap="round"/><path d="M10 12h10m0 0l-3-3m3 3l-3 3" stroke-linecap="round" stroke-linejoin="round"/></svg>
                退出登录
              </a>
            </div>
          </div>
        <?php else: ?>
          <a class="nav-login" href="<?= url('/login') ?>">登录</a>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="page-body">
    <?= $content ?>
  </main>

  <?php if ($showTab): ?>
  <nav class="tabbar">
    <a class="tab <?= $tabActive(['/']) ?>" href="<?= url('/') ?>">
      <svg viewBox="0 0 24 24" width="24" height="24"><path d="M4 7.5A1.5 1.5 0 015.5 6h3.6l1.6 2H18.5A1.5 1.5 0 0120 9.5v8A1.5 1.5 0 0118.5 19h-13A1.5 1.5 0 014 17.5v-10z" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/></svg>
      <span>资源</span>
    </a>
    <a class="tab <?= $tabActive(['/categories', '/folder']) ?>" href="<?= url('/categories') ?>">
      <svg viewBox="0 0 24 24" width="24" height="24"><rect x="4" y="4" width="7" height="7" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="4" width="7" height="7" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.7"/><rect x="4" y="13" width="7" height="7" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.7"/><rect x="13" y="13" width="7" height="7" rx="1.6" fill="none" stroke="currentColor" stroke-width="1.7"/></svg>
      <span>分类</span>
    </a>
    <a class="tab <?= $tabActive(['/user', '/login', '/register']) ?>" href="<?= $u ? url('/user') : url('/login') ?>">
      <svg viewBox="0 0 24 24" width="24" height="24"><circle cx="12" cy="8.5" r="3.6" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M4.8 20c.6-3.7 3.6-5.6 7.2-5.6s6.6 1.9 7.2 5.6" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
      <span><?= $u ? '我的' : '登录' ?></span>
    </a>
  </nav>
  <?php endif; ?>

</div>

<div class="toast-host" id="toastHost">
<?php foreach (flash_take() as $f): ?>
  <div class="toast toast-<?= e($f['type'] === 'error' ? 'err' : 'ok') ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
</div>

<script src="<?= asset('app.js') ?>"></script>
</body>
</html>
