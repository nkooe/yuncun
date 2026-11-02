<?php
/**
 * 后台：分享链接管理
 * 变量：$rows, $pager
 */
?>
<div class="adm-toolbar">
  <div class="adm-toolbar-r" style="margin-left:0">
    <span class="adm-meta">共 <?= (int) $pager['total'] ?> 个分享 · 在「资源管理」中点分享按钮生成</span>
  </div>
</div>

<?php if (empty($rows)): ?>
  <div class="adm-panel">
    <div class="adm-empty">
      <p>还没有生成任何分享链接</p>
      <a class="btn btn-primary btn-sm" href="<?= url('/admin/files') ?>">去资源管理</a>
    </div>
  </div>
<?php else: ?>
  <div class="adm-panel">
    <div class="table-wrap">
      <table class="adm-table">
        <thead>
          <tr>
            <th>文件</th>
            <th class="col-md" style="width:120px">提取码</th>
            <th class="col-md" style="width:96px">已下载</th>
            <th class="col-md" style="width:130px">有效期</th>
            <th style="width:98px">状态</th>
            <th style="width:172px;text-align:right">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r):
              $expired = !empty($r['expires_at']) && strtotime((string) $r['expires_at']) < time();
              $max = (int) $r['max_downloads'];
              $used = (int) $r['downloads'];
              $usedUp = $max > 0 && $used >= $max;
              $dead = (int) $r['status'] !== 1 || $expired || $usedUp;
              $url = url('/s/' . $r['token']);
          ?>
            <tr>
              <td>
                <div class="fcell">
                  <?php [$badge, $color] = file_badge((string) ($r['ext'] ?? '')); ?>
                  <span class="fbadge fbadge-sm" style="--c:<?= e($color) ?>"><?= e($badge) ?></span>
                  <span class="fname"><?= e($r['file_name'] ?: '文件已删除') ?></span>
                </div>
              </td>
              <td class="col-md mono"><?= $r['code'] !== '' ? e($r['code']) : '<span class="dim">无</span>' ?></td>
              <td class="col-md mono"><?= $used ?><?= $max > 0 ? ' / ' . $max : '' ?></td>
              <td class="col-md dim nowrap">
                <?= $r['expires_at'] ? e(date('Y-m-d', strtotime((string) $r['expires_at']))) : '永久' ?>
              </td>
              <td>
                <?php if ((int) $r['status'] !== 1): ?>
                  <span class="tag-off">已取消</span>
                <?php elseif ($expired): ?>
                  <span class="tag-off">已过期</span>
                <?php elseif ($usedUp): ?>
                  <span class="tag-off">次数用尽</span>
                <?php else: ?>
                  <span class="tag-ok">有效</span>
                <?php endif; ?>
              </td>
              <td class="ta-r">
                <button type="button" class="btn-link js-copy" data-url="<?= e($url) ?>">复制</button>
                <a class="btn-link" href="<?= e($url) ?>" target="_blank" rel="noopener">打开</a>
                <?php if ((int) $r['status'] === 1): ?>
                  <button type="button" class="btn-link danger js-revoke" data-id="<?= (int) $r['id'] ?>">取消</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

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

<script>
(function(){
  var meta = function(n){ var e=document.querySelector('meta[name="'+n+'"]'); return e?e.getAttribute('content'):''; };
  var CSRF = meta('csrf-token'), BASE = meta('base-url')||'';
  function toast(m,t){ if(window.wxToast) return window.wxToast(m,t); }

  document.addEventListener('click', async function(e){
    if(!e.target.closest) return;
    var c = e.target.closest('.js-copy');
    if(c){
      var url = c.getAttribute('data-url');
      try {
        await navigator.clipboard.writeText(url);
        toast('链接已复制');
      } catch(err) {
        window.prompt('手动复制链接：', url);
      }
      return;
    }
    var r = e.target.closest('.js-revoke');
    if(r){
      if(!confirm('取消该分享链接？取消后链接立即失效。')) return;
      var fd = new FormData(); fd.append('id', r.getAttribute('data-id'));
      try{
        var resp = await fetch(BASE+'/admin/shares/revoke', {method:'POST', headers:{'X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:fd});
        var j = await resp.json();
        if(!j.ok) throw new Error(j.msg);
        toast(j.msg); setTimeout(function(){location.reload();},500);
      }catch(err){ toast(err.message,'error'); }
    }
  });
})();
</script>
