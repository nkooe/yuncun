<?php
/**
 * 后台：资源管理
 * 变量：$files, $folders, $folderTree, $pager, $totals, $curFolder, $keyword, $freeSpace, $maxUpload
 */
$tree = $folderTree ?? [];
$curFolder = (int) ($curFolder ?? 0);
$keyword = $keyword ?? '';
?>
<div class="adm-toolbar">
  <button type="button" class="btn btn-primary btn-sm" id="pickFiles">
    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
    上传文件
  </button>
  <input type="file" id="fileInput" multiple hidden>

  <?php /* 未完成的上传：页面切走后回来还能接着传，需要重选同一个文件 */ ?>
  <input type="file" id="resumeInput" hidden>

  <?php /* 上传目标：与下方「筛选」解耦，这样筛选「全部文件夹」时也能指定落到哪个目录 */ ?>
  <select id="uploadFolder" class="up-folder" title="上传到哪个文件夹">
    <option value="0">上传到根目录</option>
    <?php foreach ($tree as $fo): ?>
      <option value="<?= (int) $fo['id'] ?>">
        <?= str_repeat('　', (int) $fo['depth']) . e($fo['name']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <form class="adm-filter" method="get" action="<?= url('/admin/files') ?>">
    <select name="folder_id" onchange="this.form.submit()">
      <option value="0">全部文件夹</option>
      <?php foreach ($tree as $fo): ?>
        <option value="<?= (int) $fo['id'] ?>" <?= $curFolder === (int) $fo['id'] ? 'selected' : '' ?>>
          <?= str_repeat('　', (int) $fo['depth']) . e($fo['name']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= e($keyword) ?>" placeholder="搜索文件名">
    <button class="btn btn-ghost btn-sm" type="submit">筛选</button>
  </form>

  <div class="adm-toolbar-r">
    <span class="adm-meta">共 <?= (int) $totals['count'] ?> 个 · <?= e(bytes_human((int) $totals['size'])) ?></span>
    <span class="adm-meta <?= $freeSpace > 0 && $freeSpace < 5368709120 ? 'warn' : '' ?>">
      剩余空间 <?= e(bytes_human($freeSpace)) ?>
    </span>
  </div>
</div>

<div class="dropzone" id="dropZone">
  <svg viewBox="0 0 24 24" width="30" height="30" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
    <path d="M12 16V4"/><path d="M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 001 1h14a1 1 0 001-1v-3"/>
  </svg>
  <p>把文件拖到这里，或 <button type="button" class="link-btn" id="dropPick">点击选择</button></p>
  <small>单文件上限 <?= e(bytes_human((int) $maxUpload)) ?> · 自动分片上传，相同内容秒级去重</small>
</div>

<div class="upload-panel" id="uploadPanel" hidden>
  <div class="upload-head">
    <b>上传队列</b>
    <button type="button" class="link-btn" id="clearDone">清除已完成</button>
  </div>
  <div class="upload-list" id="uploadList"></div>
</div>

<?php if (empty($files)): ?>
  <div class="resume-box" id="resumeBox" hidden></div>

  <div class="adm-panel">
    <div class="adm-empty">
      <p><?= $keyword !== '' ? '没有匹配的文件' : '还没有上传任何资源' ?></p>
      <button type="button" class="btn btn-primary btn-sm js-pick">上传第一个文件</button>
    </div>
  </div>
<?php else: ?>
<form id="batchForm" method="post" action="<?= url('/admin/files/batch-delete') ?>">
  <?= Auth::csrfField() ?>
  <div class="adm-panel">
    <div class="adm-table-bar">
      <label class="chk-label"><input type="checkbox" id="checkAll"> 全选</label>
      <button type="button" class="btn btn-danger-ghost btn-sm" id="batchDelete" disabled>移入回收站</button>
      <span class="batch-move">
        <select id="batchFolder" class="up-folder" title="移动到哪个文件夹">
          <option value="0">移动到根目录</option>
          <?php foreach ($tree as $fo): ?>
            <option value="<?= (int) $fo['id'] ?>">
              <?= str_repeat('　', (int) $fo['depth']) . e($fo['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <button type="button" class="btn btn-ghost btn-sm" id="batchMove" disabled>移动</button>
      </span>
    </div>
    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th style="width:38px"></th>
            <th>文件名</th>
            <th class="col-md" style="width:96px">大小</th>
            <th class="col-md" style="width:128px">文件夹</th>
            <th class="col-md" style="width:92px">等级</th>
            <th class="col-md" style="width:76px">下载</th>
            <th class="col-md" style="width:148px">上传时间</th>
            <th style="width:124px;text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($files as $f): [$badge, $color] = file_badge((string) $f['ext']); ?>
            <tr data-id="<?= (int) $f['id'] ?>">
              <td><input type="checkbox" name="ids[]" value="<?= (int) $f['id'] ?>" class="row-chk"></td>
              <td>
                <div class="fcell">
                  <span class="fbadge fbadge-sm" style="--c:<?= e($color) ?>"><?= e($badge) ?></span>
                  <div class="fcell-main">
                    <span class="fname" title="<?= e($f['name']) ?>"><?= e($f['name']) ?></span>
                    <?php if ((int) $f['is_public'] !== 1): ?>
                      <span class="tag-dot">不公开</span>
                    <?php endif; ?>
                  </div>
                </div>
              </td>
              <td class="col-md mono"><?= e(bytes_human((int) $f['size'])) ?></td>
              <td class="col-md"><?= $f['folder_name'] ? e($f['folder_name']) : '<span class="dim">未分类</span>' ?></td>
              <td class="col-md">
                <?php $lv = level_by_id((int) ($f['level_id'] ?? 0)); ?>
                <?= $lv !== null
                    ? '<span class="level-badge" style="--lc:' . e($lv['color']) . '">' . e($lv['name']) . '</span>'
                    : '<span class="dim">不限</span>' ?>
              </td>
              <td class="col-md mono"><?= (int) $f['downloads'] ?></td>
              <td class="col-md dim nowrap"><?= e(time_human((string) $f['created_at'])) ?></td>
              <td class="ta-r">
                <button type="button" class="icon-btn js-edit"
                  data-id="<?= (int) $f['id'] ?>"
                  data-name="<?= e($f['name']) ?>"
                  data-folder="<?= (int) $f['folder_id'] ?>"
                  data-public="<?= (int) $f['is_public'] ?>"
                  data-level="<?= (int) ($f['level_id'] ?? 0) ?>"
                  data-view-level="<?= (int) ($f['view_level_id'] ?? 0) ?>"
                  data-login-req="<?= (int) ($f['login_required'] ?? 0) ?>"
                  title="编辑">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M13.5 5.5l4 4"/></svg>
                </button>
                <button type="button" class="icon-btn js-share"
                  data-id="<?= (int) $f['id'] ?>"
                  data-name="<?= e($f['name']) ?>"
                  title="分享">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 01-1 1H5a1 1 0 01-1-1V7a1 1 0 011-1h5"/></svg>
                </button>
                <button type="button" class="icon-btn danger js-del"
                  data-id="<?= (int) $f['id'] ?>"
                  data-name="<?= e($f['name']) ?>"
                  title="删除">
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 6.5h15"/><path d="M9 6.5V4.8a1 1 0 011-1h4a1 1 0 011 1v1.7"/><path d="M6.5 6.5l.8 13a1 1 0 001 .9h7.4a1 1 0 001-.9l.8-13"/></svg>
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</form>

<?php if ($pager['pages'] > 1): ?>
  <nav class="pager">
    <?php $qs = array_filter(['folder_id' => $curFolder ?: null, 'q' => $keyword ?: null]); ?>
    <?php if ($pager['has_prev']): ?>
      <a class="pager-btn" href="?<?= e(http_build_query($qs + ['page' => $pager['page'] - 1])) ?>">上一页</a>
    <?php else: ?><span class="pager-btn disabled">上一页</span><?php endif; ?>
    <span class="pager-info"><?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?></span>
    <?php if ($pager['has_next']): ?>
      <a class="pager-btn" href="?<?= e(http_build_query($qs + ['page' => $pager['page'] + 1])) ?>">下一页</a>
    <?php else: ?><span class="pager-btn disabled">下一页</span><?php endif; ?>
  </nav>
<?php endif; ?>
<?php endif; ?>

<!-- 编辑弹窗 -->
<div class="modal" id="editModal" hidden>
  <div class="modal-mask" data-close></div>
  <div class="modal-box">
    <div class="modal-head">
      <h3>编辑文件</h3>
      <button type="button" class="icon-btn" data-close>&times;</button>
    </div>
    <form id="editForm" method="post" action="<?= url('/admin/files/update') ?>">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="id" id="editId">
      <div class="modal-body">
        <div class="field-row">
          <label>显示名称</label>
          <input name="name" id="editName" maxlength="255" required>
        </div>
        <div class="field-row">
          <label>所属文件夹</label>
          <select name="folder_id" id="editFolder">
            <option value="0">未分类</option>
            <?php foreach ($tree as $fo): ?>
              <option value="<?= (int) $fo['id'] ?>"><?= str_repeat('　', (int) $fo['depth']) . e($fo['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row">
          <label>前台可见</label>
          <select name="is_public" id="editPublic">
            <option value="1">公开</option>
            <option value="0">隐藏</option>
          </select>
        </div>
        <div class="field-row">
          <label>所需等级</label>
          <select name="level_id" id="editLevel">
            <option value="0">不限（所有人可下载）</option>
            <?php foreach ($levels as $lv): ?>
              <option value="<?= (int) $lv['id'] ?>"><?= e($lv['name']) ?>（权重 <?= (int) $lv['sort'] ?>）</option>
            <?php endforeach; ?>
          </select>
          <small class="field-hint">决定<b>能否下载</b>：等级权重 ≥ 该值才允许下载。设为「不限」时任何人拿到链接都能下载。</small>
        </div>
        <div class="field-row">
          <label>可见等级</label>
          <select name="view_level_id" id="editViewLevel">
            <option value="0">不限（所有人可见）</option>
            <?php foreach ($levels as $lv): ?>
              <option value="<?= (int) $lv['id'] ?>"><?= e($lv['name']) ?>（权重 <?= (int) $lv['sort'] ?>）</option>
            <?php endforeach; ?>
          </select>
          <small class="field-hint">只决定<b>列表里是否显示</b>，不限制下载。需要真正拦住下载，请同时设置上面的「所需等级」。</small>
        </div>
        <div class="field-row">
          <label>仅登录可见</label>
          <select name="login_required" id="editLoginReq">
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

<!-- 删除确认弹窗 -->
<div class="modal" id="delModal" hidden>
  <div class="modal-mask" data-close></div>
  <div class="modal-box modal-sm">
    <div class="modal-head">
      <h3 id="delTitle">删除文件</h3>
      <button type="button" class="icon-btn" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <p class="del-msg" id="delMsg"></p>
      <label class="del-opt">
        <input type="checkbox" id="delHard">
        <span>
          <b>不保留回收站，直接永久删除</b>
          <small>勾选后物理文件一并删除，无法恢复</small>
        </span>
      </label>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost btn-sm" data-close>取消</button>
      <button type="button" class="btn btn-danger btn-sm" id="delConfirm">确认删除</button>
    </div>
  </div>
</div>

<!-- 分享弹窗 -->
<div class="modal" id="shareModal" hidden>
  <div class="modal-mask" data-close></div>
  <div class="modal-box modal-sm">
    <div class="modal-head">
      <h3>生成分享链接</h3>
      <button type="button" class="icon-btn" data-close>&times;</button>
    </div>
    <div class="modal-body">
      <p class="del-msg" id="shareFileName"></p>

      <div class="field-row">
        <label>提取码</label>
        <input id="shareCodeInput" maxlength="16" placeholder="留空自动生成 4 位">
      </div>
      <div class="field-row">
        <label>有效期（天）</label>
        <input id="shareDays" type="number" min="0" value="7" placeholder="0 表示永久">
      </div>
      <div class="field-row">
        <label>下载次数上限</label>
        <input id="shareMax" type="number" min="0" value="0" placeholder="0 表示不限">
      </div>

      <div class="share-result" id="shareResult" hidden>
        <label>分享链接</label>
        <input id="shareUrl" readonly>
        <p>提取码：<b id="shareCodeOut"></b></p>
        <button type="button" class="btn btn-ghost btn-sm" id="shareCopy">复制链接</button>
      </div>
    </div>
    <div class="modal-foot">
      <button type="button" class="btn btn-ghost btn-sm" data-close>关闭</button>
      <button type="button" class="btn btn-primary btn-sm" id="shareCreate">生成链接</button>
    </div>
  </div>
</div>

<script src="<?= asset('upload.js') ?>"></script>
<script>
/* 从仪表盘的「上传资源」跳过来时自动打开文件选择 */
(function () {
  var AUTO_OPEN_UPLOAD = /[?&]upload=1(&|$)/.test(location.search);
  if (!AUTO_OPEN_UPLOAD) return;
  var btn = document.getElementById('pickFiles');
  if (btn) setTimeout(function () { btn.click(); }, 300);
  // 用过就清掉参数，避免刷新反复弹窗
  if (history.replaceState) history.replaceState(null, '', location.pathname);
})();
</script>
