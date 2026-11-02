<?php
/**
 * 个人资料页
 * 变量：$user, $level
 */
$u = $user;
$initial = mb_strtoupper(mb_substr($u['nickname'] !== '' ? $u['nickname'] : $u['username'], 0, 1));
?>
<div class="page-head">
  <a class="back-link" href="<?= url('/user') ?>">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
    返回
  </a>
</div>

<div class="profile-card">
  <div class="profile-top">
    <?= avatar_html($u, 'lg') ?>
    <div class="profile-id">
      <b><?= e($u['nickname'] !== '' ? $u['nickname'] : $u['username']) ?></b>
      <small>@<?= e($u['username']) ?></small>
    </div>
    <?php if ($level !== null): ?>
      <span class="level-badge" style="--lc:<?= e($level['color']) ?>"><?= e($level['name']) ?></span>
    <?php elseif (($u['role'] ?? '') === 'admin'): ?>
      <span class="tag-admin">管理员</span>
    <?php endif; ?>
  </div>
</div>

<section class="section">
  <div class="section-head"><h3>基本资料</h3></div>
  <form class="cell-group form-card" method="post" action="<?= url('/user/profile') ?>">
    <?= Auth::csrfField() ?>
    <div class="field-row">
      <label>登录账号</label>
      <input type="text" value="<?= e($u['username']) ?>" disabled>
    </div>
    <div class="field-row">
      <label>昵称</label>
      <input type="text" name="nickname" value="<?= e($u['nickname']) ?>" maxlength="32" placeholder="显示名称" required>
    </div>
    <div class="field-row">
      <label>邮箱</label>
      <input type="email" name="email" value="<?= e($u['email'] ?? '') ?>" placeholder="选填，用于找回账号">
    </div>
    <div class="form-actions">
      <button class="btn btn-primary btn-block" type="submit">保存资料</button>
    </div>
  </form>
</section>

<section class="section">
  <div class="section-head"><h3>修改密码</h3></div>
  <form class="cell-group form-card" method="post" action="<?= url('/user/profile/password') ?>" autocomplete="off">
    <?= Auth::csrfField() ?>
    <div class="field-row">
      <label>原密码</label>
      <input type="password" name="old_password" placeholder="请输入当前密码" autocomplete="current-password" required>
    </div>
    <div class="field-row">
      <label>新密码</label>
      <input type="password" name="new_password" placeholder="至少 6 位" minlength="6" autocomplete="new-password" required>
    </div>
    <div class="field-row">
      <label>确认新密码</label>
      <input type="password" name="new_password2" placeholder="再输入一次" autocomplete="new-password" required>
    </div>
    <p class="form-hint">修改成功后会自动退出登录，需要用新密码重新登录。</p>
    <div class="form-actions">
      <button class="btn btn-primary btn-block" type="submit">修改密码</button>
    </div>
  </form>
</section>

<div class="user-actions">
  <a class="btn btn-danger-ghost btn-sm" href="<?= url('/logout') ?>">退出登录</a>
</div>
