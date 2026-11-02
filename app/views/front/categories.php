<?php
/**
 * 分类总览（前台）
 * 变量：$folders, $uncategorized, $totalFiles, $canDownload
 */
$navTitle = '分类';
?>
<div class="front-hero">
  <div class="hero-text">
    <h2>分类浏览</h2>
    <p><?= count($folders) ?> 个分类 · <?= (int) $totalFiles ?> 个资源</p>
  </div>
  <form class="hero-search" method="get" action="<?= url('/search') ?>">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4" stroke-linecap="round"/></svg>
    <input type="search" name="q" placeholder="搜索资源" autocomplete="off">
  </form>
</div>

<section class="section">
  <div class="section-head">
    <h3>全部文件夹</h3>
    <?php if ($uncategorized > 0): ?>
      <a class="section-more" href="<?= url('/') ?>">未分类 <?= (int) $uncategorized ?> 个 →</a>
    <?php endif; ?>
  </div>

  <?php if (empty($folders)): ?>
    <div class="empty empty-sm">
      <p class="empty-title">还没有分类</p>
      <p class="empty-sub">管理员建好文件夹后会显示在这里</p>
      <a class="btn btn-ghost btn-sm" href="<?= url('/') ?>">浏览全部资源</a>
    </div>
  <?php else: ?>
    <div class="folder-scroll">
      <?php foreach ($folders as $fo):
          $lvId = (int) ($fo['level_id'] ?? 0);
          $lv   = level_by_id($lvId);
          $lvOk = can_access_level($lvId);
      ?>
        <a class="folder-card <?= $lvOk ? '' : 'is-locked' ?>" href="<?= url('/folder/' . (int) $fo['id']) ?>">
          <span class="folder-ico">
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M3.5 7A1.5 1.5 0 015 5.5h4l1.8 2H19A1.5 1.5 0 0120.5 9v9A1.5 1.5 0 0119 19.5H5A1.5 1.5 0 013.5 18V7z"/></svg>
          </span>
          <b><?= e($fo['name']) ?></b>
          <small>
            <?php if ((int) $fo['sub_count'] > 0): ?><?= (int) $fo['sub_count'] ?> 个子目录 · <?php endif; ?>
            <?= (int) $fo['file_count'] ?> 个文件
          </small>
          <?php if ($lv !== null): ?>
            <span class="level-badge level-badge-sm folder-lv" style="--lc:<?= e($lv['color']) ?>"><?= e($lv['name']) ?></span>
          <?php endif; ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
