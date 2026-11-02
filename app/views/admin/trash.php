<?php
/**
 * 后台：回收站
 * 变量：$tab, $files, $folders, $pager, $counts, $recycleDays
 */
?>
<div class="adm-tabs">
  <a class="adm-tab <?= $tab === 'files' ? 'active' : '' ?>" href="<?= url('/admin/trash?tab=files') ?>">
    文件 <em><?= (int) $counts['files'] ?></em>
  </a>
  <a class="adm-tab <?= $tab === 'folders' ? 'active' : '' ?>" href="<?= url('/admin/trash?tab=folders') ?>">
    文件夹 <em><?= (int) $counts['folders'] ?></em>
  </a>
</div>

<div class="adm-toolbar">
  <div class="adm-toolbar-r" style="margin-left:0">
    <span class="adm-meta">
      <?= $recycleDays > 0
          ? "超过 {$recycleDays} 天自动清理"
          : '未启用自动清理' ?>
      · 占用 <?= e(bytes_human((int) $counts['size'])) ?>
    </span>
  </div>
  <div class="adm-toolbar-r">
    <?php if ($tab === 'files' && !empty($files)): ?>
      <button type="button" class="btn btn-ghost btn-sm" id="restoreBtn" disabled>恢复选中</button>
      <button type="button" class="btn btn-danger-ghost btn-sm" id="purgeBtn" disabled>永久删除</button>
    <?php endif; ?>
    <?php if ((int) $counts['files'] + (int) $counts['folders'] > 0): ?>
      <button type="button" class="btn btn-danger btn-sm" id="emptyBtn">清空回收站</button>
    <?php endif; ?>
  </div>
</div>

<?php if ($tab === 'files'): ?>
  <?php if (empty($files)): ?>
    <div class="adm-panel"><div class="adm-empty"><p>回收站是空的</p></div></div>
  <?php else: ?>
    <div class="adm-panel">
      <div class="adm-table-bar">
        <label class="chk-label"><input type="checkbox" id="checkAll"> 全选</label>
      </div>
      <div class="table-wrap">
        <table class="adm-table">
          <thead>
            <tr>
              <th style="width:38px"></th>
              <th>文件名</th>
              <th class="col-md" style="width:96px">大小</th>
              <th class="col-md" style="width:130px">原文件夹</th>
              <th class="col-md" style="width:148px">删除时间</th>
              <th style="width:100px;text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($files as $f): [$badge, $color] = file_badge((string) $f['ext']); ?>
              <tr data-id="<?= (int) $f['id'] ?>">
                <td><input type="checkbox" class="row-chk" value="<?= (int) $f['id'] ?>"></td>
                <td>
                  <div class="fcell">
                    <span class="fbadge fbadge-sm" style="--c:<?= e($color) ?>"><?= e($badge) ?></span>
                    <span class="fname"><?= e($f['name']) ?></span>
                  </div>
                </td>
                <td class="col-md mono"><?= e(bytes_human((int) $f['size'])) ?></td>
                <td class="col-md dim"><?= $f['folder_name'] ? e($f['folder_name']) : '未分类' ?></td>
                <td class="col-md dim nowrap"><?= e(time_human((string) $f['deleted_at'])) ?></td>
                <td class="ta-r">
                  <button type="button" class="btn-link js-restore" data-id="<?= (int) $f['id'] ?>">恢复</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php else: ?>
  <?php if (empty($folders)): ?>
    <div class="adm-panel"><div class="adm-empty"><p>没有已删除的文件夹</p></div></div>
  <?php else: ?>
    <div class="adm-panel">
      <div class="table-wrap">
        <table class="adm-table">
          <thead>
            <tr>
              <th>文件夹</th>
              <th class="col-md" style="width:148px">删除时间</th>
              <th style="width:150px;text-align:right">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($folders as $fo): ?>
              <tr>
                <td>
                  <div class="fcell">
                    <span class="folder-ico-sm">
                      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M3.5 7A1.5 1.5 0 015 5.5h4l1.8 2H19A1.5 1.5 0 0120.5 9v9A1.5 1.5 0 0119 19.5H5A1.5 1.5 0 013.5 18V7z"/></svg>
                    </span>
                    <span class="fname"><?= e($fo['name']) ?></span>
                  </div>
                </td>
                <td class="col-md dim"><?= e(time_human((string) $fo['deleted_at'])) ?></td>
                <td class="ta-r">
                  <button type="button" class="btn-link js-restore-folder" data-id="<?= (int) $fo['id'] ?>">恢复</button>
                  <button type="button" class="btn-link danger js-purge-folder" data-id="<?= (int) $fo['id'] ?>" data-name="<?= e($fo['name']) ?>">永久删除</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

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
  function toast(m,t){ if(window.wxToast) return window.wxToast(m,t); }
  async function post(path, data){
    var r = await fetch(BASE+path, {method:'POST', headers:{'X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:data});
    var j = await r.json();
    if(!j.ok) throw new Error(j.msg||'操作失败');
    return j;
  }
  var checkAll = document.getElementById('checkAll');
  var restoreBtn = document.getElementById('restoreBtn');
  var purgeBtn = document.getElementById('purgeBtn');
  function ids(){ return Array.prototype.map.call(document.querySelectorAll('.row-chk:checked'), function(c){return c.value;}).filter(Boolean); }
  function sync(){
    var n = ids().length;
    if(restoreBtn) restoreBtn.disabled = n===0;
    if(purgeBtn) purgeBtn.disabled = n===0;
  }
  if(checkAll){ checkAll.addEventListener('change', function(){ document.querySelectorAll('.row-chk').forEach(function(c){c.checked=checkAll.checked;}); sync(); }); }
  document.addEventListener('change', function(e){ if(e.target.classList && e.target.classList.contains('row-chk')) sync(); });

  if(restoreBtn) restoreBtn.addEventListener('click', async function(){
    var list = ids(); if(!list.length) return;
    var fd = new FormData(); fd.append('ids', list.join(','));
    try{ var r = await post('/admin/trash/restore', fd); toast(r.msg); setTimeout(function(){location.reload();},500); }catch(err){ toast(err.message,'error'); }
  });

  if(purgeBtn) purgeBtn.addEventListener('click', async function(){
    var list = ids(); if(!list.length) return;
    if(!confirm('永久删除选中的 '+list.length+' 个文件？此操作不可恢复。')) return;
    var fd = new FormData(); fd.append('ids', list.join(','));
    try{ var r = await post('/admin/trash/purge', fd); toast(r.msg); setTimeout(function(){location.reload();},500); }catch(err){ toast(err.message,'error'); }
  });

  document.addEventListener('click', async function(e){
    if(!e.target.closest) return;
    var b = e.target.closest('.js-restore');
    if(b){
      var fd = new FormData(); fd.append('ids', b.getAttribute('data-id'));
      try{ var r = await post('/admin/trash/restore', fd); toast(r.msg); setTimeout(function(){location.reload();},400); }catch(err){ toast(err.message,'error'); }
      return;
    }
    var rf = e.target.closest('.js-restore-folder');
    if(rf){
      var fd2 = new FormData(); fd2.append('id', rf.getAttribute('data-id'));
      try{ var r2 = await post('/admin/trash/restore-folder', fd2); toast(r2.msg); setTimeout(function(){location.reload();},400); }catch(err){ toast(err.message,'error'); }
      return;
    }
    var pf = e.target.closest('.js-purge-folder');
    if(pf){
      if(!confirm('永久删除文件夹「'+(pf.getAttribute('data-name')||'')+'」及其内部所有文件？')) return;
      var fd3 = new FormData(); fd3.append('id', pf.getAttribute('data-id'));
      try{ var r3 = await post('/admin/trash/purge-folder', fd3); toast(r3.msg); setTimeout(function(){location.reload();},400); }catch(err){ toast(err.message,'error'); }
      return;
    }
    var eb = e.target.closest('#emptyBtn');
    if(eb){
      if(!confirm('清空回收站？所有项目将被永久删除，不可恢复。')) return;
      try{ var r4 = await post('/admin/trash/empty', new FormData()); toast(r4.msg); setTimeout(function(){location.reload();},400); }catch(err){ toast(err.message,'error'); }
    }
  });
})();
</script>
