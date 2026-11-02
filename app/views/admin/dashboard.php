<?php
/**
 * 后台仪表盘
 * 变量：$stats, $recentFiles, $recentLogins
 */
$cards = [
    ['label' => '资源总数', 'value' => number_format((int) $stats['files']), 'unit' => '个', 'sub' => '今日新增 ' . (int) $stats['today_files'], 'tone' => 'green'],
    ['label' => '存储占用', 'value' => bytes_human((int) $stats['size']), 'unit' => '', 'sub' => '文件夹 ' . (int) $stats['folders'] . ' 个', 'tone' => 'blue'],
    ['label' => '注册用户', 'value' => number_format((int) $stats['users']), 'unit' => '人', 'sub' => '回收站 ' . (int) $stats['trash'] . ' 项', 'tone' => 'purple'],
    ['label' => '累计下载', 'value' => number_format((int) $stats['downloads']), 'unit' => '次', 'sub' => '今日 ' . (int) $stats['today_dl'] . ' 次', 'tone' => 'orange'],
];
?>
<div class="stat-grid">
  <?php foreach ($cards as $c): ?>
    <div class="stat-card tone-<?= e($c['tone']) ?>">
      <div class="stat-label"><?= e($c['label']) ?></div>
      <div class="stat-value"><?= e($c['value']) ?><small><?= e($c['unit']) ?></small></div>
      <div class="stat-sub"><?= e($c['sub']) ?></div>
    </div>
  <?php endforeach; ?>
</div>

<?php if ((int) setting('require_login_download', 0) === 1): ?>
  <div class="adm-alert">
    <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="5" y="10.5" width="14" height="9.5" rx="2"/><path d="M8.5 10.5V8a3.5 3.5 0 017 0v2.5"/></svg>
    <span>当前已开启「登录下载」：游客只能浏览资源，不能下载。</span>
    <a href="<?= url('/admin/settings') ?>">修改</a>
  </div>
<?php endif; ?>

<div class="adm-cols">
  <section class="adm-panel">
    <header class="adm-panel-h">
      <h2>最近上传</h2>
      <a class="adm-link" href="<?= url('/admin/files') ?>">全部 →</a>
    </header>
    <?php if (empty($recentFiles)): ?>
      <div class="adm-empty">
        <p>还没有上传任何资源</p>
        <button class="btn btn-primary btn-sm js-soon">上传资源</button>
      </div>
    <?php else: ?>
      <div class="cell-group cell-group-tight">
        <?php foreach ($recentFiles as $f): ?>
          <?php [$badge, $color] = file_badge((string) $f['ext']); ?>
          <div class="cell">
            <span class="fbadge" style="--c:<?= e($color) ?>"><?= e($badge) ?></span>
            <div class="cell-main">
              <div class="cell-title"><?= e($f['name']) ?></div>
              <div class="cell-sub">
                <span><?= e(bytes_human((int) $f['size'])) ?></span>
                <i class="dot">·</i><span>下载 <?= (int) $f['downloads'] ?></span>
                <i class="dot">·</i><span><?= e(time_human((string) $f['created_at'])) ?></span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="adm-panel">
    <header class="adm-panel-h">
      <h2>最近登录</h2>
      <a class="adm-link" href="<?= url('/admin/logs') ?>">全部 →</a>
    </header>
    <?php if (empty($recentLogins)): ?>
      <div class="adm-empty"><p>暂无登录记录</p></div>
    <?php else: ?>
      <ul class="log-list">
        <?php foreach ($recentLogins as $lg): ?>
          <li>
            <span class="log-dot <?= $lg['status'] === 'ok' ? 'ok' : ($lg['status'] === 'locked' ? 'warn' : 'bad') ?>"></span>
            <div class="log-main">
              <b><?= e($lg['username']) ?></b>
              <small><?= e($lg['msg']) ?> · <?= e($lg['ip']) ?></small>
            </div>
            <time><?= e(time_human((string) $lg['created_at'])) ?></time>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </section>
</div>

<section class="adm-panel">
  <header class="adm-panel-h"><h2>快速入口</h2></header>
  <div class="quick-grid">
    <button class="quick js-soon"><span class="q-ico">↑</span><b>上传资源</b><small>支持拖拽批量</small></button>
    <button class="quick js-soon"><span class="q-ico">＋</span><b>新建文件夹</b><small>组织资源结构</small></button>
    <a class="quick" href="<?= url('/admin/users') ?>"><span class="q-ico">人</span><b>用户管理</b><small>账号与配额</small></a>
    <a class="quick" href="<?= url('/admin/settings') ?>"><span class="q-ico">设</span><b>系统设置</b><small>下载开关与限制</small></a>
  </div>
</section>
