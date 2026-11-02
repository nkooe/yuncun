<?php
/**
 * 前台资源列表
 * 变量：$files, $folders, $pager, $stats, $canDownload, $keyword
 */
$siteName = setting('site_name', '云盘');
$needLogin = !$canDownload;
$q = $keyword ?? '';
?>
<div class="front-hero">
  <div class="hero-text">
    <h2><?= $q !== '' ? '搜索结果' : '全部资源' ?></h2>
    <p>
      <?php if ($q !== ''): ?>
        关键词「<?= e($q) ?>」 · 共 <?= (int) $pager['total'] ?> 个
      <?php else: ?>
        <?= (int) ($stats['files'] ?? 0) ?> 个资源 · <?= e(bytes_human((int) ($stats['size'] ?? 0))) ?>
      <?php endif; ?>
    </p>
  </div>
  <form class="hero-search" method="get" action="<?= url('/search') ?>" role="search">
    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="6.5"/><path d="M16 16l4 4" stroke-linecap="round"/></svg>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="搜索资源" enterkeyhint="search">
  </form>
</div>

<?php if ($needLogin): ?>
  <div class="notice-bar">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/></svg>
    <span>本站已开启登录下载，资源可浏览，登录后即可下载</span>
    <a href="<?= url('/login') ?>">去登录</a>
  </div>
<?php endif; ?>

<?php if (!empty($folders)): ?>
<section class="section">
  <div class="section-head">
    <h3>分类</h3>
  </div>
  <div class="folder-scroll">
    <?php foreach ($folders as $fo): ?>
      <button type="button" class="folder-card js-soon">
        <span class="folder-ico">
          <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"><path d="M3.5 7A1.5 1.5 0 015 5.5h4l1.8 2H19A1.5 1.5 0 0120.5 9v9.5A1.5 1.5 0 0119 20H5a1.5 1.5 0 01-1.5-1.5V7z"/></svg>
        </span>
        <b><?= e($fo['name']) ?></b>
        <small><?= (int) $fo['cnt'] ?> 个文件</small>
      </button>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<section class="section">
  <div class="section-head">
    <h3><?= $q !== '' ? '匹配结果' : '最新资源' ?></h3>
    <?php if ($pager['total'] > 0): ?>
      <span class="section-meta">第 <?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?> 页</span>
    <?php endif; ?>
  </div>

  <?php if (empty($files)): ?>
    <div class="empty">
      <div class="empty-ico">
        <svg viewBox="0 0 48 48" width="72" height="72" fill="none" stroke="#CFCFCF" stroke-width="1.6" stroke-linejoin="round">
          <path d="M8 14a3 3 0 013-3h9l4 5h13a3 3 0 013 3v16a3 3 0 01-3 3H11a3 3 0 01-3-3V14z"/>
          <path d="M16 27h16" stroke-linecap="round" opacity=".45"/>
        </svg>
      </div>
      <p class="empty-title"><?= $q !== '' ? '没有找到相关内容' : '还没有资源' ?></p>
      <p class="empty-sub"><?= $q !== '' ? '换个关键词试试' : '管理员上传后，资源会出现在这里' ?></p>
      <?php if ($q !== ''): ?>
        <a class="btn btn-ghost btn-sm" href="<?= url('/') ?>">返回全部</a>
      <?php endif; ?>
    </div>
  <?php else: ?>
    <div class="cell-group">
      <?php foreach ($files as $f):
          [$badge, $color] = file_badge((string) $f['ext']);
          $needLv  = (int) ($f['level_id'] ?? 0);
          $lvInfo  = level_by_id($needLv);
          $lvOk    = can_access_level($needLv);
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
              <?php if (!empty($f['folder_name'])): ?>
                <i class="dot">·</i><span><?= e($f['folder_name']) ?></span>
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
            <a class="cell-lock" href="<?= url('/login?redirect=' . rawurlencode('/')) ?>" title="登录后可下载">
              <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/></svg>
              <span>登录</span>
            </a>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pager['pages'] > 1): ?>
      <nav class="pager">
        <?php if ($pager['has_prev']): ?>
          <a class="pager-btn" href="?<?= e(http_build_query(array_filter(['q' => $q, 'page' => $pager['page'] - 1]))) ?>">上一页</a>
        <?php else: ?>
          <span class="pager-btn disabled">上一页</span>
        <?php endif; ?>
        <span class="pager-info"><?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?></span>
        <?php if ($pager['has_next']): ?>
          <a class="pager-btn" href="?<?= e(http_build_query(array_filter(['q' => $q, 'page' => $pager['page'] + 1]))) ?>">下一页</a>
        <?php else: ?>
          <span class="pager-btn disabled">下一页</span>
        <?php endif; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</section>

<?php if ($needLogin): ?>
<div class="bottom-cta">
  <div class="cta-text">
    <b>登录后解锁下载</b>
    <small>同步你的下载记录与次数</small>
  </div>
  <a class="btn btn-primary btn-sm" href="<?= url('/login') ?>">立即登录</a>
</div>
<?php endif; ?>
