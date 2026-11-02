<?php
/**
 * 注册页
 */
$old = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);
?>
<form class="auth-card" method="post" action="<?= url('/register') ?>" autocomplete="on" novalidate>
  <?= Auth::csrfField() ?>

  <div class="auth-field">
    <label for="username">账号</label>
    <input id="username" name="username" value="<?= e($old['username'] ?? '') ?>" placeholder="3–32 位字母、数字或下划线" autocomplete="username" required autofocus>
  </div>

  <div class="auth-field">
    <label for="email">邮箱 <span class="opt">选填</span></label>
    <input id="email" name="email" type="email" value="<?= e($old['email'] ?? '') ?>" placeholder="用于找回账号" autocomplete="email">
  </div>

  <div class="auth-field">
    <label for="password">密码</label>
    <div class="pwd-wrap">
      <input id="password" name="password" type="password" placeholder="至少 6 位" minlength="6" autocomplete="new-password" required>
      <button type="button" class="pwd-toggle" data-target="password" aria-label="显示密码" tabindex="-1">
        <svg viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg>
      </button>
    </div>
  </div>

  <div class="auth-field">
    <label for="password2">确认密码</label>
    <input id="password2" name="password2" type="password" placeholder="再输入一次" autocomplete="new-password" required>
  </div>

  <button class="btn btn-primary btn-block" type="submit">注 册</button>

  <div class="auth-links">
    <span>已有账号？</span>
    <a href="<?= url('/login') ?>">直接登录</a>
  </div>
</form>
