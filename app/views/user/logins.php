<?php
/**
 * 我的登录记录（分页）
 * 变量：$rows, $pager
 */
$navTitle = '登录记录';
?>
<div class="page-head">
  <a class="back-link" href="<?= url('/user') ?>">
    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg>
    返回
  </a>
</div>

<section class="section">
  <div class="section-head">
    <h3>登录记录</h3>
    <span class="section-meta">共 <?= (int) $pager['total'] ?> 条</span>
  </div>

  <?php if (empty($rows)): ?>
    <div class="empty empty-sm"><p class="empty-title">暂无登录记录</p></div>
  <?php else: ?>
    <ul class="log-list log-list-card">
      <?php foreach ($rows as $lg): ?>
        <li>
          <span class="log-dot <?= $lg['status'] === 'ok' ? 'ok' : ($lg['status'] === 'locked' ? 'warn' : 'bad') ?>"></span>
          <div class="log-main">
            <b><?= e($lg['msg']) ?></b>
            <small class="mono"><span class="log-ip"><?= e($lg['ip']) ?></span><span class="log-ua"> · <?= e(mb_substr((string) $lg['ua'], 0, 32)) ?></span></small>
          </div>
          <time><?= e(time_human((string) $lg['created_at'])) ?></time>
        </li>
      <?php endforeach; ?>
    </ul>
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
