<?php
/**
 * 后台：记录中心
 * 变量：$tab, $rows, $pager, $counts
 */
$tabs = [
    'downloads' => ['下载记录', $counts['downloads']],
    'logins'    => ['登录记录', $counts['logins']],
    'admin'     => ['操作日志', $counts['admin']],
];
?>
<div class="adm-tabs">
  <?php foreach ($tabs as $k => [$label, $n]): ?>
    <a class="adm-tab <?= $tab === $k ? 'active' : '' ?>" href="<?= url('/admin/logs?tab=' . $k) ?>">
      <?= e($label) ?> <em><?= (int) $n ?></em>
    </a>
  <?php endforeach; ?>
</div>

<div class="adm-toolbar">
  <div class="log-clean">
    <select id="cleanScope" aria-label="清理范围">
      <option value="7">清理 7 天前</option>
      <option value="30" selected>清理 30 天前</option>
      <option value="90">清理 90 天前</option>
      <option value="all">清空所选类型</option>
    </select>
    <button type="button" class="btn btn-ghost btn-sm" id="cleanCurrent">清理当前类型</button>
    <button type="button" class="btn btn-danger-ghost btn-sm" id="cleanAll">清理全部</button>
  </div>
  <div class="adm-toolbar-r">
    <span class="adm-meta">
      <?= $keepDays > 0 ? "自动保留 {$keepDays} 天" : '未启用自动清理' ?>
    </span>
  </div>
</div>

<div class="adm-panel">
  <div class="table-wrap">
    <?php if ($tab === 'downloads'): ?>
      <table class="adm-table">
        <thead>
          <tr>
            <th>文件</th>
            <th style="width:110px">用户</th>
            <th class="col-md" style="width:140px">IP</th>
            <th class="col-md" style="width:100px">流量</th>
            <th style="width:80px">状态</th>
            <th class="col-md" style="width:148px">时间</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><span class="fname" style="max-width:280px"><?= e($r['file_name'] ?: '文件已删除') ?></span></td>
              <td><?= $r['username'] ? e($r['username']) : '<span class="dim">游客</span>' ?></td>
              <td class="col-md mono dim"><?= e($r['ip']) ?></td>
              <td class="col-md mono"><?= e(bytes_human((int) $r['bytes_sent'])) ?></td>
              <td>
                <?php
                $m = ['done' => ['完成', 'tag-ok'], 'pending' => ['进行中', 'tag-warn'], 'aborted' => ['中断', 'tag-off']];
                [$txt, $cls] = $m[$r['status']] ?? ['未知', 'tag-off'];
                ?>
                <span class="<?= $cls ?>"><?= $txt ?></span>
              </td>
              <td class="col-md dim nowrap"><?= e(time_human((string) $r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (empty($rows)): ?><div class="adm-empty"><p>暂无下载记录</p></div><?php endif; ?>

    <?php elseif ($tab === 'logins'): ?>
      <table class="adm-table">
        <thead>
          <tr>
            <th style="width:140px">账号</th>
            <th>结果</th>
            <th class="col-md" style="width:150px">IP</th>
            <th class="col-md">客户端</th>
            <th style="width:148px">时间</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><span class="fname"><?= e($r['username']) ?></span></td>
              <td>
                <?php if ($r['status'] === 'ok'): ?>
                  <span class="tag-ok">成功</span>
                <?php elseif ($r['status'] === 'locked'): ?>
                  <span class="tag-warn">锁定</span>
                <?php else: ?>
                  <span class="tag-off">失败</span>
                <?php endif; ?>
                <span class="dim" style="font-size:12px;margin-left:6px"><?= e($r['msg']) ?></span>
              </td>
              <td class="col-md mono dim"><?= e($r['ip']) ?></td>
              <td class="col-md dim" style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e(mb_substr((string) $r['ua'], 0, 40)) ?></td>
              <td class="dim nowrap"><?= e(time_human((string) $r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (empty($rows)): ?><div class="adm-empty"><p>暂无登录记录</p></div><?php endif; ?>

    <?php else: ?>
      <table class="adm-table">
        <thead>
          <tr>
            <th style="width:130px">操作人</th>
            <th style="width:140px">动作</th>
            <th>详情</th>
            <th class="col-md" style="width:140px">IP</th>
            <th style="width:148px">时间</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= $r['username'] ? e($r['username']) : '<span class="dim">系统</span>' ?></td>
              <td><code class="mini-code"><?= e($r['action']) ?></code></td>
              <td class="dim" style="max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= e($r['detail']) ?></td>
              <td class="col-md mono dim"><?= e($r['ip']) ?></td>
              <td class="dim nowrap"><?= e(time_human((string) $r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (empty($rows)): ?><div class="adm-empty"><p>暂无操作日志</p></div><?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($pager['pages'] > 1): ?>
  <nav class="pager">
    <?php if ($pager['has_prev']): ?>
      <a class="pager-btn" href="?tab=<?= e($tab) ?>&page=<?= (int) ($pager['page'] - 1) ?>">上一页</a>
    <?php else: ?><span class="pager-btn disabled">上一页</span><?php endif; ?>
    <span class="pager-info"><?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?></span>
    <?php if ($pager['has_next']): ?>
      <a class="pager-btn" href="?tab=<?= e($tab) ?>&page=<?= (int) ($pager['page'] + 1) ?>">下一页</a>
    <?php else: ?><span class="pager-btn disabled">下一页</span><?php endif; ?>
  </nav>
<?php endif; ?>

<script>
(function(){
  var meta = function(n){ var e=document.querySelector('meta[name="'+n+'"]'); return e?e.getAttribute('content'):''; };
  var CSRF = meta('csrf-token'), BASE = meta('base-url')||'';
  var TAB = <?= json_encode($tab) ?>;
  function toast(m,t){ if(window.wxToast) return window.wxToast(m,t); }

  async function run(tab, scope, label){
    var fd = new FormData();
    fd.append('tab', tab);
    fd.append('scope', scope);
    try{
      var r = await fetch(BASE+'/admin/logs/clean', {
        method:'POST',
        headers:{'X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest'},
        body: fd
      });
      var j = await r.json();
      if(!j.ok) throw new Error(j.msg||'清理失败');
      toast(j.msg);
      setTimeout(function(){ location.reload(); }, 600);
    }catch(err){ toast(err.message,'error'); }
  }

  var scopeSel = document.getElementById('cleanScope');
  var btnCur = document.getElementById('cleanCurrent');
  var btnAll = document.getElementById('cleanAll');

  if(btnCur) btnCur.addEventListener('click', function(){
    var s = scopeSel.value;
    var label = s === 'all' ? '清空当前类型全部记录' : ('清理当前类型 ' + s + ' 天前的记录');
    if(!confirm(label + '？此操作不可恢复。')) return;
    run(TAB, s);
  });

  if(btnAll) btnAll.addEventListener('click', function(){
    var s = scopeSel.value;
    var label = s === 'all' ? '清空全部三类日志' : ('清理全部日志 ' + s + ' 天前的记录');
    if(!confirm(label + '？此操作不可恢复。')) return;
    run('all', s);
  });
})();
</script>
