<?php
/**
 * 认证页布局：无 TabBar，居中卡片。
 */
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?= e(setting('theme_color', '#07C160')) ?>">
<title><?= e($pageTitle ?? '登录 · ' . setting('site_name', '云盘')) ?></title>
<link rel="stylesheet" href="<?= asset('app.css') ?>">
<link rel="icon" href="<?= asset('favicon.svg') ?>" type="image/svg+xml">
</head>
<body class="page-auth">
<div class="auth-wrap">
  <div class="auth-brand">
    <div class="auth-logo">盘</div>
    <h1><?= e(setting('site_name', '云盘')) ?></h1>
    <p><?= e(setting('site_desc', '')) ?></p>
  </div>
  <?= $content ?>
  <p class="auth-foot">© <?= date('Y') ?> <?= e(setting('site_name', '云盘')) ?><?php if ($icp = setting('icp', '')): ?> · <a href="https://beian.miit.gov.cn" target="_blank" rel="noopener"><?= e($icp) ?></a><?php endif; ?></p>
</div>

<div class="toast-host" id="toastHost">
<?php foreach (flash_take() as $f): ?>
  <div class="toast toast-<?= e($f['type'] === 'error' ? 'err' : 'ok') ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
</div>

<script src="<?= asset('app.js') ?>"></script>
</body>
</html>
