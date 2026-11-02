<?php
/**
 * 后台系统设置
 * 变量：$settings
 */
$v = static fn(string $k, mixed $d = '') => $settings[$k] ?? $d;
?>
<form method="post" action="<?= url('/admin/settings') ?>" class="adm-form">
  <?= Auth::csrfField() ?>

  <section class="adm-panel">
    <header class="adm-panel-h"><h2>下载权限</h2></header>
    <div class="adm-fields">
      <label class="switch-row">
        <div class="switch-txt">
          <b>开启登录下载</b>
          <small>开启后游客只能浏览资源列表，下载按钮将被隐藏，且服务端拒绝未登录的下载请求</small>
        </div>
        <input type="checkbox" name="require_login_download" value="1" <?= (int) $v('require_login_download', 0) === 1 ? 'checked' : '' ?>>
        <span class="switch"></span>
      </label>

      <label class="switch-row">
        <div class="switch-txt">
          <b>游客可浏览</b>
          <small>关闭后未登录访问首页将直接跳转到登录页</small>
        </div>
        <input type="checkbox" name="guest_visible" value="1" <?= (int) $v('guest_visible', 1) === 1 ? 'checked' : '' ?>>
        <span class="switch"></span>
      </label>

      <label class="switch-row">
        <div class="switch-txt">
          <b>开放注册</b>
          <small>关闭后仅管理员可创建账号</small>
        </div>
        <input type="checkbox" name="allow_register" value="1" <?= (int) $v('allow_register', 1) === 1 ? 'checked' : '' ?>>
        <span class="switch"></span>
      </label>

      <label class="switch-row">
        <div class="switch-txt">
          <b>启用分享链接</b>
          <small>允许生成带提取码的分享地址</small>
        </div>
        <input type="checkbox" name="share_enabled" value="1" <?= (int) $v('share_enabled', 1) === 1 ? 'checked' : '' ?>>
        <span class="switch"></span>
      </label>

      <label class="switch-row">
        <div class="switch-txt">
          <b>等级不足时隐藏</b>
          <small>开启后，等级不够的资源直接不出现在前台列表；关闭则显示但锁定下载</small>
        </div>
        <input type="checkbox" name="hide_locked" value="1" <?= (int) $v('hide_locked', 0) === 1 ? 'checked' : '' ?>>
        <span class="switch"></span>
      </label>

      <label class="switch-row">
        <div class="switch-txt">
          <b>维护模式</b>
          <small>开启后前台显示维护页，管理员仍可访问后台</small>
        </div>
        <input type="checkbox" name="maintenance_mode" value="1" <?= (int) $v('maintenance_mode', 0) === 1 ? 'checked' : '' ?>>
        <span class="switch"></span>
      </label>
    </div>
  </section>

  <section class="adm-panel">
    <header class="adm-panel-h"><h2>基础信息</h2></header>
    <div class="adm-fields">
      <div class="field-row">
        <label>站点名称</label>
        <input name="site_name" value="<?= e($v('site_name')) ?>" maxlength="32">
      </div>
      <div class="field-row">
        <label>站点描述</label>
        <input name="site_desc" value="<?= e($v('site_desc')) ?>" maxlength="120">
      </div>
      <div class="field-row">
        <label>站点 URL</label>
        <input name="site_url" value="<?= e($v('site_url')) ?>" placeholder="https://pan.example.com">
      </div>
      <div class="field-row">
        <label>备案号</label>
        <input name="icp" value="<?= e($v('icp')) ?>" placeholder="蜀ICP备xxxxxxxx号">
      </div>
      <div class="field-row">
        <label>主题色</label>
        <input name="theme_color" value="<?= e($v('theme_color', '#07C160')) ?>" placeholder="#07C160">
      </div>
    </div>
  </section>

  <section class="adm-panel">
    <header class="adm-panel-h"><h2>下载配额</h2></header>
    <div class="adm-fields">
      <div class="field-row">
        <label>新用户下载次数</label>
        <input name="default_download_quota" type="number" min="0" value="<?= (int) $v('default_download_quota', 0) ?>">
        <small class="field-hint">0 表示不限制</small>
      </div>
      <div class="field-row">
        <label>配额周期</label>
        <select name="default_quota_period">
          <?php foreach (['total' => '总量（不重置）', 'daily' => '每天重置', 'monthly' => '每月重置'] as $k => $label): ?>
            <option value="<?= $k ?>" <?= $v('default_quota_period', 'total') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-row">
        <label>登录失败上限</label>
        <input name="login_fail_limit" type="number" min="0" value="<?= (int) $v('login_fail_limit', 5) ?>">
        <small class="field-hint">连续失败达到次数后锁定 15 分钟，0 表示不限制</small>
      </div>
      <div class="field-row">
        <label>回收站保留天数</label>
        <input name="recycle_days" type="number" min="0" value="<?= (int) $v('recycle_days', 30) ?>">
      </div>
      <div class="field-row">
        <label>日志保留天数</label>
        <input name="log_keep_days" type="number" min="0" value="<?= (int) $v('log_keep_days', 30) ?>">
        <small class="field-hint">下载 / 登录 / 操作日志超过该天数自动清理，0 表示永久保留</small>
      </div>
      <div class="field-row">
        <label>登录锁定时长(分钟)</label>
        <input name="login_lock_minutes" type="number" min="1" max="1440" value="<?= (int) $v('login_lock_minutes', 60) ?>">
        <small class="field-hint">连续登录失败达到上限后，该账号与来源 IP 在此时间内无法登录</small>
      </div>
      <div class="field-row">
        <label>磁盘水位上限(%)</label>
        <input name="disk_guard_percent" type="number" min="0" max="100" value="<?= (int) $v('disk_guard_percent', 92) ?>">
        <small class="field-hint">磁盘使用率超过该值自动暂停上传，避免写满导致数据库损坏；0 表示不限制</small>
      </div>
    </div>
  </section>

  <section class="adm-panel">
    <header class="adm-panel-h"><h2>列表与上传</h2></header>
    <div class="adm-fields">
      <div class="field-row">
        <label>每页条数</label>
        <input name="page_size" type="number" min="5" max="100" value="<?= (int) $v('page_size', 20) ?>">
      </div>
      <div class="field-row">
        <label>默认排序</label>
        <select name="sort_default">
          <?php foreach (['new' => '最新上传', 'hot' => '下载最多', 'name' => '文件名'] as $k => $label): ?>
            <option value="<?= $k ?>" <?= $v('sort_default', 'new') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field-row">
        <label>禁止上传的扩展名</label>
        <textarea name="ext_blacklist" rows="2"><?= e($v('ext_blacklist')) ?></textarea>
        <small class="field-hint">逗号分隔，不区分大小写</small>
      </div>
    </div>
  </section>

  <div class="adm-actions">
    <button class="btn btn-primary" type="submit">保存设置</button>
  </div>
</form>
