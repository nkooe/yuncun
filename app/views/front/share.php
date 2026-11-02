<?php
/**
 * 分享页（前台）
 * 变量：$token, $share, $file, $needCode, $verified, $error
 */
[$badge, $color] = file_badge((string) $file['ext']);
$expired = !empty($share['expires_at']) ? (string) $share['expires_at'] : '';
$max = (int) $share['max_downloads'];
$left = $max > 0 ? max(0, $max - (int) $share['downloads']) : -1;
$navTitle = '文件分享';
?>
<div class="share-wrap">
  <div class="share-card">
    <div class="share-file">
      <span class="fbadge" style="--c:<?= e($color) ?>;width:52px;height:52px;border-radius:12px;font-size:12px"><?= e($badge) ?></span>
      <div class="share-file-main">
        <b><?= e($file['name']) ?></b>
        <small>
          <?= e(bytes_human((int) $file['size'])) ?>
          <?php if ($expired !== ''): ?> · <?= e(date('Y-m-d', strtotime($expired))) ?> 到期<?php endif; ?>
          <?php if ($left >= 0): ?> · 剩余 <?= $left ?> 次<?php endif; ?>
        </small>
      </div>
    </div>

    <?php if ($error !== ''): ?>
      <div class="share-err"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($needCode && !$verified): ?>
      <form class="share-code" method="post" action="<?= url('/s/' . $token) ?>">
        <?= Auth::csrfField() ?>
        <label for="code">请输入提取码</label>
        <div class="share-code-row">
          <input id="code" name="code" type="text" maxlength="16" placeholder="4 位提取码"
                 autocomplete="off" autocapitalize="off" spellcheck="false" required autofocus>
          <button class="btn btn-primary" type="submit">提取</button>
        </div>
      </form>
    <?php else: ?>
      <a class="btn btn-primary btn-block share-dl" href="<?= url('/s/' . $token . '/download') ?>">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v12"/><path d="M7 11l5 5 5-5"/><path d="M4 20h16"/></svg>
        下载文件
      </a>
      <?php if ($needCode): ?>
        <p class="share-note">提取码已验证，2 小时内可重复下载</p>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <p class="share-foot">来自 <?= e(setting('site_name', '云盘')) ?></p>
</div>
