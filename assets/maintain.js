/* 维护页：磁盘清理（无依赖） */
(function () {
  'use strict';

  function meta(name) {
    var el = document.querySelector('meta[name="' + name + '"]');
    return el ? el.getAttribute('content') : '';
  }

  var BASE = meta('base-url') || '';

  function toast(msg, type) {
    if (window.wxToast) { window.wxToast(msg, type); return; }
    console.log(msg);
  }

  function csrf() {
    var m = document.querySelector('meta[name="csrf-token"]');
    if (m) return m.content;
    var i = document.querySelector('input[name="_token"]');
    return i ? i.value : '';
  }

  function hours() {
    var s = document.querySelector('.adm-filter select[name="hours"]');
    return s ? s.value : '24';
  }

  async function call(act) {
    var fd = new FormData();
    fd.append('_token', csrf());
    fd.append('hours', hours());

    var r = await fetch(BASE + '/admin/maintain/' + act, {
      method: 'POST',
      headers: {
        'X-CSRF-Token': csrf(),
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: fd,
      credentials: 'same-origin'
    });

    var j;
    try { j = await r.json(); } catch (e) { throw new Error('服务器响应异常（HTTP ' + r.status + '）'); }
    if (!j.ok) throw new Error(j.msg || '操作失败');
    return j;
  }

  function busy(flag) {
    document.querySelectorAll('.js-clean, #cleanAll').forEach(function (b) {
      if (flag) {
        b.dataset.was = b.disabled ? '1' : '';
        b.disabled = true;
      } else {
        b.disabled = b.dataset.was === '1';
      }
    });
  }

  // 单项清理
  document.addEventListener('click', async function (e) {
    var btn = e.target.closest ? e.target.closest('.js-clean') : null;
    if (!btn || btn.disabled) return;

    var act = btn.getAttribute('data-act');
    var label = btn.closest('tr') ? btn.closest('tr').querySelector('b').textContent : act;

    if (act === 'orphans') {
      if (!confirm('孤立文件会被永久删除，无法恢复。\n确定清理「' + label + '」？')) return;
    }

    busy(true);
    try {
      var j = await call(act);
      toast(j.msg, 'ok');
      setTimeout(function () { location.reload(); }, 700);
    } catch (err) {
      toast(err.message, 'err');
      busy(false);
    }
  });

  // 全部清理
  var all = document.getElementById('cleanAll');
  if (all) {
    all.addEventListener('click', async function () {
      if (!confirm('将清理：过期分片、孤立文件、过期回收站、过期日志、孤儿下载记录。\n其中孤立文件会被永久删除，无法恢复。\n\n确定继续？')) return;

      busy(true);
      try {
        var j = await call('all');
        toast(j.msg, 'ok');
        setTimeout(function () { location.reload(); }, 900);
      } catch (err) {
        toast(err.message, 'err');
        busy(false);
      }
    });
  }
})();
