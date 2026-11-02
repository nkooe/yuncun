<?php
/**
 * 我的下载记录（分页）
 * 变量：$rows, $pager
 */
$navTitle = '下载记录';
$statusMap = [
    'done'    => ['已完成', 'ok'],
    'pending' => ['进行中', 'warn'],
    'aborted' => ['已中断', 'bad'],
];
?>
<div class="page-head">
  <a class="back-link" href="<?= url('/user') ?>">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
    返回
  </a>
</div>

<section class="section">
  <div class="section-head">
    <h3>下载记录</h3>
    <span class="section-meta">共 <?= (int) $pager['total'] ?> 条</span>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty empty-sm">
      <p class="empty-title">还没有下载记录</p>
      <p class="empty-sub">去首页看看有什么资源</p>
      <a class="btn btn-ghost btn-sm" href="<?= url('/') ?>">浏览资源</a>
    </div>
  <?php else: ?>
    <div class="cell-group">
      <?php foreach ($rows as $d):
          [$badge, $color] = file_badge((string) ($d['ext'] ?? ''));
          [$stText, $stCls] = $statusMap[$d['status']] ?? ['未知', ''];
      ?>
        <div class="cell">
          <span class="fbadge" style="--c:<?= e($color) ?>"><?= e($badge) ?></span>
          <div class="cell-main">
            <div class="cell-title"><?= e($d['file_name'] ?: '文件已删除') ?></div>
            <div class="cell-sub">
              <span class="st-<?= e($stCls) ?>"><?= e($stText) ?></span>
              <i class="dot">·</i><span><?= e(bytes_human((int) $d['bytes_sent'])) ?></span>
              <i class="dot">·</i><span class="mono"><?= e($d['ip']) ?></span>
              <i class="dot">·</i><span><?= e(time_human((string) $d['created_at'])) ?></span>
            </div>
          </div>
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
