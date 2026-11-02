<?php
/**
 * 文件夹详情（前台）
 * 变量：$folder, $crumbs, $subs, $files, $pager, $canDownload
 */
$navTitle = $folder['name'];
?>
<nav class="crumbs" aria-label="路径">
  <a href="<?= url('/categories') ?>">分类</a>
  <?php foreach ($crumbs as $c): ?>
    <span class="sep">/</span>
    <?php if ((int) $c['id'] === (int) $folder['id']): ?>
      <span class="cur"><?= e($c['name']) ?></span>
    <?php else: ?>
      <a href="<?= url('/folder/' . (int) $c['id']) ?>"><?= e($c['name']) ?></a>
    <?php endif; ?>
  <?php endforeach; ?>
</nav>

<div class="front-hero" style="padding-top:8px">
  <div class="hero-text">
    <h2><?= e($folder['name']) ?></h2>
    <p>
      <?= count($subs) ?> 个子目录 · <?= (int) $pager['total'] ?> 个文件
      <?php if (!empty($folder['description'])): ?> · <?= e($folder['description']) ?><?php endif; ?>
    </p>
  </div>
</div>

<?php if (!empty($subs)): ?>
  <section class="section">
    <div class="section-head"><h3>子目录</h3></div>
    <div class="folder-scroll">
      <?php foreach ($subs as $fo): ?>
        <a class="folder-card" href="<?= url('/folder/' . (int) $fo['id']) ?>">
          <span class="folder-ico">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M3.5 7A1.5 1.5 0 015 5.5h4l1.8 2H19A1.5 1.5 0 0120.5 9v9A1.5 1.5 0 0119 19.5H5A1.5 1.5 0 013.5 18V7z"/></svg>
          </span>
          <b><?= e($fo['name']) ?></b>
          <small><?= (int) $fo['file_count'] ?> 个文件</small>
        </a>
      <?php endforeach; ?>
    </div>
  </section>
<?php endif; ?>

<section class="section">
  <div class="section-head">
    <h3>文件</h3>
    <span class="section-meta">第 <?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?> 页</span>
  </div>

  <?php if (empty($files)): ?>
    <div class="empty empty-sm">
      <p class="empty-title">这个文件夹还是空的</p>
      <p class="empty-sub">去其他分类看看</p>
      <a class="btn btn-ghost btn-sm" href="<?= url('/categories') ?>">返回分类</a>
    </div>
  <?php else: ?>
    <div class="cell-group">
      <?php foreach ($files as $f):
          [$badge, $color] = file_badge((string) $f['ext']);
          $needLv = (int) ($f['level_id'] ?? 0);
          $lvInfo = level_by_id($needLv);
          $lvOk   = can_access_level($needLv);
      ?>
        <div class="cell">
          <span class="fbadge" style="--c:<?= e($color) ?>"><?= e($badge) ?></span>
          <div class="cell-main">
            <div class="cell-title" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></div>
            <div class="cell-sub">
              <span><?= e(bytes_human((int) $f['size'])) ?></span>
              <?php if ($lvInfo !== null): ?>
                <i class="dot">·</i>
                <span class="level-badge level-badge-sm" style="--lc:<?= e($lvInfo['color']) ?>"><?= e($lvInfo['name']) ?></span>
              <?php endif; ?>
              <i class="dot">·</i><span><?= e(time_human((string) $f['created_at'])) ?></span>
            </div>
          </div>
          <?php if (!$lvOk): ?>
            <span class="cell-lock level-lock" title="需要「<?= e($lvInfo['name'] ?? '更高') ?>」等级">
              <svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3.6l2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8z"/></svg>
              <span><?= e($lvInfo['name'] ?? '等级') ?></span>
            </span>
          <?php elseif ($canDownload): ?>
            <a class="cell-btn" href="<?= url('/download/' . (int) $f['id']) ?>" title="下载">下载</a>
          <?php else: ?>
            <a class="cell-lock" href="<?= url('/login?redirect=' . rawurlencode('/folder/' . (int) $folder['id'])) ?>" title="登录后可下载">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/></svg>
              <span>登录</span>
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<?php if ($pager['pages'] > 1): ?>
  <nav class="pager">
    <?php if ($pager['has_prev']): ?>
      <a class="pager-btn" href="?page=<?= (int) ($pager['page'] - 1) ?>">上一页</a>
    <?php else: ?><span class="pager-btn disabled">上一页</span><?php endif; ?>
    <span class="pager-info"><?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?></span>
    <?php if ($pager['has_next']): ?>
      <a class="pager-btn" href="?page=<?= (int) ($pager['page'] + 1) ?>">下一页</a>
    <?php else: ?><span class="pager-btn disabled">下一页</span><?php endif; ?>
  </nav>
<?php endif; ?>
