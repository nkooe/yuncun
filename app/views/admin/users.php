<?php
/**
 * 后台：用户管理
 * 变量：$users, $levels, $pager, $keyword
 */
$levelOptions = $levels ?? [];
?>
<div class="adm-toolbar">
  <form class="adm-filter" method="get" action="<?= url('/admin/users') ?>">
    <input type="search" name="q" value="<?= e($keyword) ?>" placeholder="搜索账号 / 昵称 / 邮箱">
    <button class="btn btn-ghost btn-sm" type="submit">搜索</button>
  </form>
  <div class="adm-toolbar-r">
    <span class="adm-meta">共 <?= (int) $pager['total'] ?> 个用户</span>
  </div>
</div>

<div class="adm-panel">
  <div class="table-wrap">
    <table class="adm-table">
      <thead>
        <tr>
          <th>用户</th>
          <th style="width:110px">等级</th>
          <th class="col-md" style="width:90px">已下载</th>
          <th class="col-md" style="width:100px">配额</th>
          <th style="width:70px">状态</th>
          <th class="col-md" style="width:132px">最近登录</th>
          <th style="width:80px;text-align:right">操作</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
            $initial = mb_strtoupper(mb_substr($u['nickname'] !== '' ? $u['nickname'] : $u['username'], 0, 1));
            $quota = (int) $u['download_quota'];
        ?>
          <tr data-id="<?= (int) $u['id'] ?>">
            <td>
              <div class="fcell">
                <?= avatar_html($u, 'xs') ?>
                <div class="fcell-main" style="flex-direction:column;align-items:flex-start;gap:1px">
                  <span class="fname"><?= e($u['nickname'] !== '' ? $u['nickname'] : $u['username']) ?></span>
                  <span class="dim" style="font-size:11.5px">@<?= e($u['username']) ?><?= $u['role'] === 'admin' ? ' · 管理员' : '' ?></span>
                </div>
              </div>
            </td>
            <td>
              <?php if ($u['level_name']): ?>
                <span class="level-badge" style="--lc:<?= e($u['level_color']) ?>"><?= e($u['level_name']) ?></span>
              <?php else: ?>
                <span class="dim">—</span>
              <?php endif; ?>
            </td>
            <td class="col-md mono"><?= (int) $u['dl_count'] ?></td>
            <td class="col-md mono">
              <?= $quota === 0 ? '<span class="dim">不限</span>' : (int) $u['download_used'] . ' / ' . $quota ?>
            </td>
            <td>
              <?= (int) $u['status'] === 1 ? '<span class="tag-ok">正常</span>' : '<span class="tag-off">禁用</span>' ?>
            </td>
            <td class="col-md dim nowrap"><?= e(time_human((string) $u['last_login_at'])) ?></td>
            <td class="ta-r">
              <button type="button" class="icon-btn js-edit-user"
                data-id="<?= (int) $u['id'] ?>"
                data-name="<?= e($u['nickname']) ?>"
                data-level="<?= (int) $u['level_id'] ?>"
                data-quota="<?= (int) $u['download_quota'] ?>"
                data-period="<?= e($u['quota_period']) ?>"
                data-status="<?= (int) $u['status'] ?>"
                title="编辑">
                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M13.5 5.5l4 4"/></svg>
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (empty($users)): ?>
    <div class="adm-empty"><p>没有匹配的用户</p></div>
  <?php endif; ?>
</div>

<?php if ($pager['pages'] > 1): ?>
  <nav class="pager">
    <?php $qs = $keyword !== '' ? ['q' => $keyword] : []; ?>
    <?php if ($pager['has_prev']): ?>
      <a class="pager-btn" href="?<?= e(http_build_query($qs + ['page' => $pager['page'] - 1])) ?>">上一页</a>
    <?php else: ?><span class="pager-btn disabled">上一页</span><?php endif; ?>
    <span class="pager-info"><?= (int) $pager['page'] ?> / <?= (int) $pager['pages'] ?></span>
    <?php if ($pager['has_next']): ?>
      <a class="pager-btn" href="?<?= e(http_build_query($qs + ['page' => $pager['page'] + 1])) ?>">下一页</a>
    <?php else: ?><span class="pager-btn disabled">下一页</span><?php endif; ?>
  </nav>
<?php endif; ?>

<!-- 编辑弹窗 -->
<div class="modal" id="userModal" hidden>
  <div class="modal-mask" data-close></div>
  <div class="modal-box">
    <div class="modal-head">
      <h3>编辑用户</h3>
      <button type="button" class="icon-btn" data-close>&times;</button>
    </div>
    <form id="userForm" method="post" action="<?= url('/admin/users/update') ?>">
      <?= Auth::csrfField() ?>
      <input type="hidden" name="id" id="uId">
      <div class="modal-body">
        <div class="field-row">
          <label>昵称</label>
          <input name="nickname" id="uName" maxlength="32">
        </div>
        <div class="field-row">
          <label>用户等级</label>
          <select name="level_id" id="uLevel">
            <option value="0">无等级</option>
            <?php foreach ($levelOptions as $lv): ?>
              <option value="<?= (int) $lv['id'] ?>"><?= e($lv['name']) ?>（权重 <?= (int) $lv['sort'] ?>）</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field-row">
          <label>下载配额</label>
          <input name="download_quota" id="uQuota" type="number" min="0" value="0">
          <small class="field-hint">0 表示不限制</small>
        </div>
        <div class="field-row">
          <label>配额周期</label>
          <select name="quota_period" id="uPeriod">
            <option value="total">总量（不重置）</option>
            <option value="daily">每天重置</option>
            <option value="monthly">每月重置</option>
          </select>
        </div>
        <div class="field-row">
          <label>账号状态</label>
          <select name="status" id="uStatus">
            <option value="1">正常</option>
            <option value="0">禁用</option>
          </select>
        </div>
        <div class="field-row">
          <label>重置密码</label>
          <input name="password" id="uPass" type="password" placeholder="留空则不修改" autocomplete="new-password">
          <small class="field-hint">至少 6 位，保存后立即生效</small>
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
  var modal = document.getElementById('userModal');
  var uId = document.getElementById('uId');
  var uName = document.getElementById('uName');
  var uLevel = document.getElementById('uLevel');
  var uQuota = document.getElementById('uQuota');
  var uPeriod = document.getElementById('uPeriod');
  var uStatus = document.getElementById('uStatus');
  var uPass = document.getElementById('uPass');

  document.addEventListener('click', function(e){
    if(!e.target.closest) return;
    var b = e.target.closest('.js-edit-user');
    if(b){
      e.preventDefault();
      uId.value = b.getAttribute('data-id');
      uName.value = b.getAttribute('data-name');
      uLevel.value = b.getAttribute('data-level');
      uQuota.value = b.getAttribute('data-quota');
      uPeriod.value = b.getAttribute('data-period');
      uStatus.value = b.getAttribute('data-status');
      uPass.value = '';
      modal.hidden = false;
      uName.focus();
      return;
    }
    if(e.target.closest('[data-close]')) modal.hidden = true;
  });

  document.addEventListener('keydown', function(e){ if(e.key==='Escape') modal.hidden=true; });
})();
</script>
