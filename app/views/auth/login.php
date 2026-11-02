<?php
/**
 * 登录页
 * 变量：$redirect
 */
$oldUser = $_SESSION['_old_username'] ?? '';
unset($_SESSION['_old_username']);
?>
<form class="auth-card" method="post" action="<?= url('/login') ?>" autocomplete="on" novalidate>
  <?= Auth::csrfField() ?>
  <input type="hidden" name="redirect" value="<?= e($redirect ?? '/') ?>">

  <div class="auth-field">
    <label for="username">账号</label>
    <input id="username" name="username" value="<?= e($oldUser) ?>" placeholder="用户名或邮箱" autocomplete="username" required autofocus>
  </div>

  <div class="auth-field">
    <label for="password">密码</label>
    <div class="pwd-wrap">
      <input id="password" name="password" type="password" placeholder="请输入密码" autocomplete="current-password" required>
      <button type="button" class="pwd-toggle" data-target="password" aria-label="显示密码" tabindex="-1">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>
      </button>
    </div>
  </div>

  <button class="btn btn-primary btn-block" type="submit">登 录</button>

  <?php if ((int) setting('allow_register', 1) === 1): ?>
    <div class="auth-links">
      <span>还没有账号？</span>
      <a href="<?= url('/register') ?>">立即注册</a>
    </div>
  <?php endif; ?>
</form>

<p class="auth-hint">登录即代表你同意本站的使用条款与隐私政策</p>
