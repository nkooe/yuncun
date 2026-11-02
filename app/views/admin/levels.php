<?php
/**
 * 后台：用户等级
 * 变量：$levels, $userCnt, $fileCnt, $folderCnt
 */
?>
<div class="adm-toolbar">
  <button type="button" class="btn btn-primary btn-sm" id="newLevel">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    新建等级
  </button>
  <div class="adm-toolbar-r">
    <span class="adm-meta">共 <?= count($levels) ?> 个等级 · 权重越大权限越高</span>
  </div>
</div>

<div class="adm-panel">
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>等级</th>
          <th style="width:76px">权重</th>
          <th class="col-md" style="width:90px">用户数</th>
          <th class="col-md" style="width:90px">资源数</th>
          <th style="width:80px">默认</th>
          <th style="width:100px;text-align:right">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($levels as $lv): ?>
          <tr data-id="<?= (int) $lv['id'] ?>">
            <td>
              <div class="fcell">
                <span class="level-dot" style="--lc:<?= e($lv['color']) ?>"></span>
                <span class="fname"><?= e($lv['name']) ?></span>
                <?php if ($lv['description'] !== ''): ?>
                  <span class="dim col-md" style="font-size:12px"><?= e($lv['description']) ?></span>
                <?php endif; ?>
              </div>
            </td>
            <td class="mono"><?= (int) $lv['sort'] ?></td>
            <td class="col-md mono"><?= (int) ($userCnt[(int) $lv['id']] ?? 0) ?></td>
            <td class="col-md mono"><?= (int) (($fileCnt[(int) $lv['id']] ?? 0) + ($folderCnt[(int) $lv['id']] ?? 0)) ?></td>
            <td><?= (int) $lv['is_default'] === 1 ? '<span class="tag-ok">默认</span>' : '<span class="dim">—</span>' ?></td>
            <td class="ta-r">
              <button type="button" class="icon-btn js-edit-level"
                data-id="<?= (int) $lv['id'] ?>"
                data-name="<?= e($lv['name']) ?>"
                data-sort="<?= (int) $lv['sort'] ?>"
                data-color="<?= e($lv['color']) ?>"
                data-desc="<?= e($lv['description']) ?>"
                data-default="<?= (int) $lv['is_default'] ?>"
                title="编辑">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M13.5 5.5l4 4"/></svg>
              </button>
              <button type="button" class="icon-btn danger js-del-level" data-id="<?= (int) $lv['id'] ?>" title="删除">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 6.5h15"/><path d="M9 6.5V4.8a1 1 0 011-1h4a1 1 0 011 1v1.7"/><path d="M6.5 6.5l.8 13a1 1 0 001 .9h7.4a1 1 0 001-.9l.8-13"/></svg>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="adm-alert">
  <svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16.5v.5" stroke-linecap="round"/></svg>
  <span>文件的「所需等级」权重 ≤ 用户等级权重时才能访问；文件等级设为「不限」则所有访客可见。</span>
</div>

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

<!-- 弹窗 -->
<div class="modal" id="levelModal" hidden>
  <div class="modal-mask" data-close></div>
  <div class="modal-box">
    <div class="modal-head">
      <h3 id="levelModalTitle">新建等级</h3>
      <button type="button" class="icon-btn" data-close>&times;</button>
    </div>
    <form id="levelForm" method="post" action="<?= url('/admin/levels/save') ?>">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="id" id="lvId" value="0">
      <div class="modal-body">
        <div class="field-row">
          <label>等级名称</label>
          <input name="name" id="lvName" maxlength="32" required placeholder="例如：VIP 会员">
        </div>
        <div class="field-row">
          <label>权重</label>
          <input name="sort" id="lvSort" type="number" value="0">
          <small class="field-hint">数值越大等级越高，例如 普通=0 / VIP=10 / 超级=20</small>
        </div>
        <div class="field-row">
          <label>标识色</label>
          <input name="color" id="lvColor" value="#07C160" placeholder="#07C160">
        </div>
        <div class="field-row">
          <label>说明</label>
          <input name="description" id="lvDesc" maxlength="255" placeholder="选填">
        </div>
        <div class="field-row">
          <label>设为默认</label>
          <select name="is_default" id="lvDefault">
            <option value="0">否</option>
            <option value="1">是（新注册用户自动归入）</option>
          </select>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" class="btn btn-ghost btn-sm" data-close>取消</button>
        <button type="submit" class="btn btn-primary btn-sm">保存</button>
      </div>
    </form>
  </div>
</div>

<script>
(function(){
  var modal = document.getElementById('levelModal');
  var title = document.getElementById('levelModalTitle');
  var f = document.getElementById('levelForm');
  var fId = document.getElementById('lvId');
  var fName = document.getElementById('lvName');
  var fSort = document.getElementById('lvSort');
  var fColor = document.getElementById('lvColor');
  var fDesc = document.getElementById('lvDesc');
  var fDefault = document.getElementById('lvDefault');
  var meta = function(n){ var e=document.querySelector('meta[name="'+n+'"]'); return e?e.getAttribute('content'):''; };
  var CSRF = meta('csrf-token'), BASE = meta('base-url')||'';

  function toast(m,t){ if(window.wxToast) return window.wxToast(m,t); }

  function open(mode, d){
    if(mode==='edit' && d){
      title.textContent='编辑等级';
      fId.value=d.id; fName.value=d.name; fSort.value=d.sort;
      fColor.value=d.color; fDesc.value=d.desc||''; fDefault.value=d.def;
    } else {
      title.textContent='新建等级';
      fId.value='0'; f.reset(); fColor.value='#07C160'; fSort.value='0'; fDefault.value='0';
    }
    modal.hidden=false; fName.focus(); fName.select();
  }

  document.addEventListener('click', function(e){
    if(!e.target.closest) return;
    if(e.target.closest('#newLevel')){ e.preventDefault(); open('new'); return; }
    var eb = e.target.closest('.js-edit-level');
    if(eb){ e.preventDefault(); open('edit', {
      id: eb.getAttribute('data-id'), name: eb.getAttribute('data-name'),
      sort: eb.getAttribute('data-sort'), color: eb.getAttribute('data-color'),
      desc: eb.getAttribute('data-desc'), def: eb.getAttribute('data-default')
    }); return; }
    if(e.target.closest('[data-close]')) modal.hidden=true;
  });

  document.addEventListener('keydown', function(e){ if(e.key==='Escape') modal.hidden=true; });

  document.addEventListener('click', async function(e){
    var b = e.target.closest ? e.target.closest('.js-del-level') : null;
    if(!b) return;
    if(!confirm('确定删除该等级？仍有用户或资源使用时会被拒绝。')) return;
    var fd = new FormData(); fd.append('id', b.getAttribute('data-id'));
    try{
      var r = await fetch(BASE+'/admin/levels/delete', {
        method:'POST',
        headers:{'X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest'},
        body: fd
      });
      var j = await r.json();
      if(!j.ok) throw new Error(j.msg);
      toast(j.msg); setTimeout(function(){ location.reload(); }, 500);
    }catch(err){ toast(err.message,'error'); }
  });
})();
</script>
