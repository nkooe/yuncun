<?php
/**
 * 用户中心
 * 变量：$user, $quota, $stats, $recentDownloads, $recentLogins
 */
$nick = $user['nickname'] !== '' ? $user['nickname'] : $user['username'];
$initial = mb_strtoupper(mb_substr($nick, 0, 1));
?>
<div class="user-page">
<div class="user-top">

  <div class="profile-card">
    <div class="profile-top">
      <?= avatar_html($user, 'lg') ?>
      <div class="profile-id">
        <b><?= e($nick) ?></b>
        <small>@<?= e($user['username']) ?> · 加入 <?= (int) $stats['days'] ?> 天</small>
      </div>
      <?php if (($user['role'] ?? '') === 'admin'): ?>
        <span class="tag-admin">管理员</span>
      <?php elseif (!empty($level)): ?>
        <span class="level-badge" style="--lc:<?= e($level['color']) ?>"><?= e($level['name']) ?></span>
      <?php endif; ?>
      <a class="profile-edit" href="<?= url('/user/profile') ?>" title="编辑资料" aria-label="编辑资料">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M13.5 5.5l4 4"/></svg>
      </a>
    </div>

    <div class="quota-box">
      <div class="quota-head">
        <span>下载配额</span>
        <?php if ($quota['unlimited']): ?>
          <b class="quota-unlimited">不限次数</b>
        <?php else: ?>
          <b>剩余 <?= (int) $quota['left'] ?> 次</b>
        <?php endif; ?>
      </div>
      <?php if (!$quota['unlimited']): ?>
        <div class="quota-bar"><i style="width:<?= (int) $quota['percent'] ?>%"></i></div>
        <div class="quota-foot">
          <span>已用 <?= (int) $quota['used'] ?> / <?= (int) $quota['quota'] ?></span>
          <span>
            <?php if ($quota['period'] === 'daily'): ?>每日 0 点重置
            <?php elseif ($quota['period'] === 'monthly'): ?>每月 1 日重置
            <?php else: ?>总量限制<?php endif; ?>
          </span>
        </div>
      <?php else: ?>
        <div class="quota-foot"><span>当前账号不受下载次数限制</span></div>
      <?php endif; ?>
    </div>

    <div class="profile-stats">
      <div class="ps"><b><?= (int) $stats['downloads_total'] ?></b><span>下载次数</span></div>
      <div class="ps"><b><?= (int) $stats['login_count'] ?></b><span>登录次数</span></div>
      <div class="ps"><b><?= e(bytes_human((int) $stats['downloads_bytes'])) ?></b><span>累计流量</span></div>
    </div>
  </div>

  <div class="info-list">
    <div class="info-row"><span>注册时间</span><b><?= e($stats['created_at'] !== '' ? $stats['created_at'] : '—') ?></b></div>
    <div class="info-row"><span>最近登录</span><b><?= e(time_human($stats['last_login'])) ?></b></div>
    <div class="info-row"><span>登录 IP</span><b class="mono"><?= e($stats['last_ip'] !== '' ? $stats['last_ip'] : '—') ?></b></div>
    <div class="info-row"><span>账号状态</span><b class="ok">正常</b></div>
  </div>

</div>

<div class="user-two-col">

  <section class="section user-col">
    <div class="section-head">
      <h3>下载记录</h3>
      <span class="section-meta">共 <?= (int) $dPager['total'] ?> 条</span>
    </div>
    <?php if (empty($recentDownloads)): ?>
      <div class="empty empty-sm">
        <p class="empty-title">还没有下载记录</p>
        <p class="empty-sub">去首页看看有什么资源</p>
        <a class="btn btn-ghost btn-sm" href="<?= url('/') ?>">浏览资源</a>
      </div>
    <?php else: ?>
      <div class="cell-group">
        <?php foreach ($recentDownloads as $d):
            $ext = (string) ($d['ext'] ?? '');
            [$badge, $color] = file_badge($ext);
            $statusMap = ['done' => ['已完成', 'ok'], 'pending' => ['进行中', 'warn'], 'aborted' => ['已中断', 'bad']];
            [$stText, $stCls] = $statusMap[$d['status']] ?? ['未知', ''];
        ?>
          <div class="cell">
            <span class="fbadge" style="--c:<?= e($color) ?>"><?= e($badge) ?></span>
            <div class="cell-main">
              <div class="cell-title"><?= e($d['file_name'] ?: '文件已删除') ?></div>
              <div class="cell-sub">
                <span class="st-<?= e($stCls) ?>"><?= e($stText) ?></span>
                <i class="dot">·</i><span><?= e(bytes_human((int) $d['bytes_sent'])) ?></span>
              </div>
            </div>
            <time class="cell-time"><?= e(time_human((string) $d['created_at'])) ?></time>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($dPager['pages'] > 1): ?>
      <nav class="pager pager-sm">
        <?php if ($dPager['has_prev']): ?>
          <a class="pager-btn" href="?dp=<?= (int) ($dPager['page'] - 1) ?>&lp=<?= (int) $lPager['page'] ?>">上一页</a>
        <?php else: ?><span class="pager-btn disabled">上一页</span><?php endif; ?>
        <span class="pager-info"><?= (int) $dPager['page'] ?> / <?= (int) $dPager['pages'] ?></span>
        <?php if ($dPager['has_next']): ?>
          <a class="pager-btn" href="?dp=<?= (int) ($dPager['page'] + 1) ?>&lp=<?= (int) $lPager['page'] ?>">下一页</a>
        <?php else: ?><span class="pager-btn disabled">下一页</span><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>

  <section class="section user-col">
    <div class="section-head">
      <h3>登录记录</h3>
      <span class="section-meta">共 <?= (int) $lPager['total'] ?> 条</span>
    </div>
    <?php if (empty($recentLogins)): ?>
      <div class="empty empty-sm"><p class="empty-title">暂无登录记录</p></div>
    <?php else: ?>
      <ul class="log-list log-list-card">
        <?php foreach ($recentLogins as $lg): ?>
          <li>
            <span class="log-dot <?= $lg['status'] === 'ok' ? 'ok' : ($lg['status'] === 'locked' ? 'warn' : 'bad') ?>"></span>
            <div class="log-main">
              <b><?= e($lg['msg']) ?></b>
              <small class="mono"><span class="log-ip"><?= e($lg['ip']) ?></span><span class="log-ua"> · <?= e(mb_substr((string) $lg['ua'], 0, 24)) ?></span></small>
            </div>
            <time><?= e(time_human((string) $lg['created_at'])) ?></time>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>

    <?php if ($lPager['pages'] > 1): ?>
      <nav class="pager pager-sm">
        <?php if ($lPager['has_prev']): ?>
          <a class="pager-btn" href="?dp=<?= (int) $dPager['page'] ?>&lp=<?= (int) ($lPager['page'] - 1) ?>">上一页</a>
        <?php else: ?><span class="pager-btn disabled">上一页</span><?php endif; ?>
        <span class="pager-info"><?= (int) $lPager['page'] ?> / <?= (int) $lPager['pages'] ?></span>
        <?php if ($lPager['has_next']): ?>
          <a class="pager-btn" href="?dp=<?= (int) $dPager['page'] ?>&lp=<?= (int) ($lPager['page'] + 1) ?>">下一页</a>
        <?php else: ?><span class="pager-btn disabled">下一页</span><?php endif; ?>
      </nav>
    <?php endif; ?>
  </section>

</div>

<div class="user-actions-quiet">
  <a class="link-quiet" href="<?= url('/logout') ?>">退出登录</a>
</div>
</div><!-- /.user-page -->
