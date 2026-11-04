<?php
/**
 * 后台：维护 / 磁盘清理
 *
 * 变量：$stats, $hours
 *
 * 这页解决的是「看不见的残留」：中断的上传分片、数据库无引用的
 * 孤立文件、过期回收站、只增不减的日志、孤儿下载记录。
 * 每项都能单独清，也能一次全清；清之前把数量和占用摆出来，
 * 让用户自己判断，而不是给一个「一键优化」黑盒。
 */
$disk    = $stats['disk'];
$chunks  = $stats['chunks'];
$orphans = $stats['orphans'];
$logs    = $stats['logs'];
$trash   = $stats['trash'];
$dlBad   = $stats['dl_orphan'];

$totalGarbage = $chunks['expired_bytes'] + $orphans['bytes'] + $logs['bytes'];
?>

<div class="adm-toolbar">
  <form class="adm-filter" method="get" action="<?= url('/admin/maintain') ?>">
    <label class="chk-label" style="gap:8px">
      分片过期阈值
      <select name="hours" onchange="this.form.submit()">
        <?php foreach ([6 => '6 小时', 24 => '24 小时', 72 => '3 天', 168 => '7 天'] as $h => $lab): ?>
          <option value="<?= $h ?>" <?= (int) $hours === $h ? 'selected' : '' ?>><?= $lab ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  </form>
  <div class="adm-toolbar-r">
    <span class="adm-meta">可清理约 <?= bytes_human($totalGarbage) ?></span>
    <button type="button" class="btn btn-primary btn-sm" id="cleanAll">全部清理</button>
  </div>
</div>

<div class="adm-panel">
  <div class="mnt-grid">
    <div class="mnt-cell">
      <div class="mnt-k">存储占用</div>
      <div class="mnt-v"><?= bytes_human($disk['storage_size']) ?></div>
      <div class="mnt-s">文件本体</div>
    </div>
    <div class="mnt-cell">
      <div class="mnt-k">运行残留</div>
      <div class="mnt-v"><?= bytes_human($disk['runtime_size']) ?></div>
      <div class="mnt-s">分片与日志</div>
    </div>
    <div class="mnt-cell">
      <div class="mnt-k">磁盘已用</div>
      <div class="mnt-v"><?= (float) $disk['disk_used_p'] ?>%</div>
      <div class="mnt-s">剩余 <?= bytes_human($disk['disk_free']) ?></div>
    </div>
    <div class="mnt-cell">
      <div class="mnt-k">可清理</div>
      <div class="mnt-v mnt-v-ok"><?= bytes_human($totalGarbage) ?></div>
      <div class="mnt-s">下表各项合计</div>
    </div>
  </div>
</div>

<div class="adm-panel">
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>项目</th>
          <th class="col-md" style="width:150px">数量</th>
          <th class="col-md" style="width:120px">占用</th>
          <th style="width:108px;text-align:right">操作</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>
            <b>过期分片</b>
            <div class="mnt-hint">
              上传中断留下的临时文件，超过 <?= (int) $hours ?> 小时未动才会被算作过期。
              共有 <?= (int) $chunks['total'] ?> 个任务在暂存，占 <?= bytes_human($chunks['bytes']) ?>。
            </div>
          </td>
          <td class="col-md"><?= (int) $chunks['expired'] ?> 个</td>
          <td class="col-md"><?= bytes_human($chunks['expired_bytes']) ?></td>
          <td style="text-align:right">
            <button type="button" class="btn btn-ghost btn-sm js-clean" data-act="chunks"
                    <?= $chunks['expired'] === 0 ? 'disabled' : '' ?>>清理</button>
          </td>
        </tr>

        <tr>
          <td>
            <b>孤立文件</b>
            <div class="mnt-hint">
              磁盘上有、数据库里没有任何记录引用。回收站里的文件算「有引用」，不会被误删。
            </div>
          </td>
          <td class="col-md"><?= (int) $orphans['count'] ?> 个</td>
          <td class="col-md"><?= bytes_human($orphans['bytes']) ?></td>
          <td style="text-align:right">
            <button type="button" class="btn btn-ghost btn-sm js-clean" data-act="orphans"
                    <?= $orphans['count'] === 0 ? 'disabled' : '' ?>>清理</button>
          </td>
        </tr>

        <tr>
          <td>
            <b>过期回收站</b>
            <div class="mnt-hint">
              超过保留期（当前 <?= (int) $trash['days'] ?> 天）的条目会被永久删除，可在系统设置里调整。
            </div>
          </td>
          <td class="col-md"><?= (int) $trash['files'] ?> 文件 / <?= (int) $trash['folders'] ?> 目录</td>
          <td class="col-md">—</td>
          <td style="text-align:right">
            <button type="button" class="btn btn-ghost btn-sm js-clean" data-act="trash"
                    <?= ($trash['files'] + $trash['folders']) === 0 ? 'disabled' : '' ?>>清理</button>
          </td>
        </tr>

        <tr>
          <td>
            <b>过期日志</b>
            <div class="mnt-hint">
              超过保留期（当前 <?= (int) $logs['keep_days'] ?> 天）的下载、登录、操作记录，
              以及 runtime/logs 里的日志文件内容。
            </div>
          </td>
          <td class="col-md"><?= (int) $logs['old_rows'] ?> 条</td>
          <td class="col-md"><?= bytes_human($logs['bytes']) ?></td>
          <td style="text-align:right">
            <button type="button" class="btn btn-ghost btn-sm js-clean" data-act="logs"
                    <?= ($logs['old_rows'] === 0 && $logs['bytes'] === 0) ? 'disabled' : '' ?>>清理</button>
          </td>
        </tr>

        <tr>
          <td>
            <b>孤儿下载记录</b>
            <div class="mnt-hint">指向已经不存在的文件，会让「总下载次数」虚高。</div>
          </td>
          <td class="col-md"><?= (int) $dlBad ?> 条</td>
          <td class="col-md">—</td>
          <td style="text-align:right">
            <button type="button" class="btn btn-ghost btn-sm js-clean" data-act="downloads"
                    <?= $dlBad === 0 ? 'disabled' : '' ?>>清理</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<div class="adm-panel">
  <div class="adm-note">
    <b>说明</b>
    <p>过期分片、过期回收站、过期日志这三项每小时会自动清一次，这里只是手动立即执行。</p>
    <p>孤立文件不会自动清——判断依据是全表 sha256 无引用，清之前建议先看一眼上表数量是否符合预期。</p>
  </div>
</div>

<script src="<?= asset('maintain.js') ?>"></script>
