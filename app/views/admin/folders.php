<?php
/**
 * 后台：文件夹管理
 * 变量：$tree, $rows
 */
?>
<div class="adm-toolbar">
  <button type="button" class="btn btn-primary btn-sm" id="newFolder">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    新建文件夹
  </button>
  <div class="adm-toolbar-r">
    <span class="adm-meta">共 <?= count($rows) ?> 个文件夹</span>
  </div>
</div>

<?php if (empty($tree)): ?>
  <div class="adm-panel">
    <div class="adm-empty">
      <p>还没有创建文件夹</p>
      <button type="button" class="btn btn-primary btn-sm" id="newFolder2">新建第一个</button>
    </div>
  </div>
<?php else: ?>
  <div class="adm-panel">
    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th>文件夹</th>
            <th class="col-md" style="width:96px">文件数</th>
            <th class="col-md" style="width:80px">排序</th>
            <th style="width:90px">前台可见</th>
            <th class="col-md" style="width:92px">所需等级</th>
            <th class="col-md" style="width:130px">创建时间</th>
            <th style="width:100px;text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tree as $fo): ?>
            <tr data-id="<?= (int) $fo['id'] ?>">
              <td>
                <div class="fcell">
                  <span class="tree-indent" style="width:<?= (int) $fo['depth'] * 30 ?>px"></span>
                  <span class="folder-ico-sm">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"><path d="M3.5 7A1.5 1.5 0 015 5.5h4l1.8 2H19A1.5 1.5 0 0120.5 9v9A1.5 1.5 0 0119 19.5H5A1.5 1.5 0 013.5 18V7z"/></svg>
                  </span>
                  <span class="fname"><?= e($fo['name']) ?></span>
                </div>
              </td>
              <td class="col-md mono"><?= (int) $fo['file_count'] ?></td>
              <td class="col-md mono dim"><?= (int) $fo['sort'] ?></td>
              <td>
                <?php if ((int) $fo['is_public'] === 1): ?>
                  <span class="tag-ok">公开</span>
                <?php else: ?>
                  <span class="tag-off">隐藏</span>
                <?php endif; ?>
              </td>
              <td class="col-md">
                <?php $lv = level_by_id((int) ($fo['level_id'] ?? 0)); ?>
                <?= $lv !== null
                    ? '<span class="level-badge" style="--lc:' . e($lv['color']) . '">' . e($lv['name']) . '</span>'
                    : '<span class="dim">不限</span>' ?>
              </td>
              <td class="col-md dim"><?= e(time_human((string) $fo['created_at'])) ?></td>
              <td class="ta-r">
                <button type="button" class="icon-btn js-edit-folder"
                  data-id="<?= (int) $fo['id'] ?>"
                  data-name="<?= e($fo['name']) ?>"
                  data-parent="<?= (int) $fo['parent_id'] ?>"
                  data-sort="<?= (int) $fo['sort'] ?>"
                  data-public="<?= (int) $fo['is_public'] ?>"
                  data-level="<?= (int) ($fo['level_id'] ?? 0) ?>"
                  data-view-level="<?= (int) ($fo['view_level_id'] ?? 0) ?>"
                  data-login-req="<?= (int) ($fo['login_required'] ?? 0) ?>"
                  data-desc="<?= e($fo['description']) ?>"
                  title="编辑">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M13.5 5.5l4 4"/></svg>
                </button>
                <button type="button" class="icon-btn danger js-del-folder" data-id="<?= (int) $fo['id'] ?>" data-name="<?= e($fo['name']) ?>" title="删除">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 6.5h15"/><path d="M9 6.5V4.8a1 1 0 011-1h4a1 1 0 011 1v1.7"/><path d="M6.5 6.5l.8 13a1 1 0 001 .9h7.4a1 1 0 001-.9l.8-13"/></svg>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- 新建/编辑弹窗 -->
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

<div class="modal" id="folderModal" hidden>
  <div class="modal-mask" data-close></div>
  <div class="modal-box">
    <div class="modal-head">
      <h3 id="folderModalTitle">新建文件夹</h3>
      <button type="button" class="icon-btn" data-close>&times;</button>
    </div>
    <form id="folderForm" method="post" action="<?= url('/admin/folders/save') ?>">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="id" id="fId" value="0">
      <div class="modal-body">
        <div class="field-row">
          <label>名称</label>
          <input name="name" id="fName" maxlength="128" required placeholder="例如：产品文档">
        </div>
        <div class="field-row">
          <label>上级文件夹</label>
          <select name="parent_id" id="fParent">
            <option value="0">（顶级目录）</option>
            <?php foreach ($tree as $fo): ?>
              <option value="<?= (int) $fo['id'] ?>"><?= str_repeat('　', (int) $fo['depth']) . e($fo['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row">
          <label>描述</label>
          <input name="description" id="fDesc" maxlength="255" placeholder="选填">
        </div>
        <div class="field-row">
          <label>排序</label>
          <input name="sort" id="fSort" type="number" value="0">
        </div>
        <div class="field-row">
          <label>前台可见</label>
          <select name="is_public" id="fPublic">
            <option value="1">公开</option>
            <option value="0">隐藏</option>
          </select>
        </div>
        <div class="field-row">
          <label>所需等级</label>
          <select name="level_id" id="fLevel">
            <option value="0">不限</option>
            <?php foreach ($levels as $lv): ?>
              <option value="<?= (int) $lv['id'] ?>"><?= e($lv['name']) ?>（权重 <?= (int) $lv['sort'] ?>）</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row">
          <label>可见等级</label>
          <select name="view_level_id" id="fViewLevel">
            <option value="0">不限（所有人可见）</option>
            <?php foreach ($levels as $lv): ?>
              <option value="<?= (int) $lv['id'] ?>"><?= e($lv['name']) ?>（权重 <?= (int) $lv['sort'] ?>）</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row">
          <label>仅登录可见</label>
          <select name="login_required" id="fLoginReq">
            <option value="0">否（游客也能看到）</option>
            <option value="1">是（仅登录用户可见）</option>
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

<script src="<?= asset('folder.js') ?>"></script>
<script>
/* 从仪表盘的「新建文件夹」跳过来时自动打开弹窗 */
(function () {
  var AUTO_OPEN_NEW_FOLDER = /[?&]new=1(&|$)/.test(location.search);
  if (!AUTO_OPEN_NEW_FOLDER) return;
  var btn = document.getElementById('newFolder');
  if (btn) setTimeout(function () { btn.click(); }, 300);
  if (history.replaceState) history.replaceState(null, '', location.pathname);
})();
</script>
