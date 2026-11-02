/* 云盘 · 交互脚本（无依赖，原生 ES5+） */
(function () {
  'use strict';

  /* ---------- Toast ---------- */
  function dismissToast(el) {
    if (!el || el.classList.contains('leaving')) return;
    el.classList.add('leaving');
    setTimeout(function () { el.remove(); }, 220);
  }

  function initToasts() {
    var host = document.getElementById('toastHost');
    if (!host) return;
    var toasts = host.querySelectorAll('.toast');
    Array.prototype.forEach.call(toasts, function (t) {
      setTimeout(function () { dismissToast(t); }, 2600);
      t.addEventListener('click', function () { dismissToast(t); });
    });
  }

  /* 供页面脚本调用的轻量提示 */
  function toast(msg, type) {
    var host = document.getElementById('toastHost');
    if (!host) return;
    var el = document.createElement('div');
    el.className = 'toast' + (type === 'error' ? ' toast-err' : '');
    el.textContent = msg;
    host.appendChild(el);
    setTimeout(function () { dismissToast(el); }, 2600);
  }
  window.wxToast = toast;

  /* ---------- 密码可见切换 ---------- */
  function initPasswordToggles() {
    document.addEventListener('click', function (e) {
      var btn = e.target.closest ? e.target.closest('.pwd-toggle') : null;
      if (!btn) return;
      e.preventDefault();
      var input = document.getElementById(btn.getAttribute('data-target'));
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.classList.toggle('on', show);
      btn.setAttribute('aria-label', show ? '隐藏密码' : '显示密码');
    });
  }

  /* ---------- 后台侧边栏 ---------- */
  function initAdminDrawer() {
    var side = document.getElementById('admSide');
    var burger = document.getElementById('admBurger');
    var backdrop = document.getElementById('admBackdrop');
    if (!side || !burger) return;

    function close() {
      side.classList.remove('open');
      if (backdrop) backdrop.classList.remove('show');
    }

    burger.addEventListener('click', function () {
      side.classList.toggle('open');
      if (backdrop) backdrop.classList.toggle('show');
    });
    if (backdrop) backdrop.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') close();
    });
  }

  /* ---------- 尚未开放的入口 ---------- */
  function initPlaceholders() {
    document.addEventListener('click', function (e) {
      var el = e.target.closest ? e.target.closest('.js-soon') : null;
      if (!el) return;
      e.preventDefault();
      toast('该功能将在后续阶段开放');
    });
  }

  /* ---------- 表单防重复提交 ---------- */
  function initSubmitGuard() {
    document.addEventListener('submit', function (e) {
      var form = e.target;
      if (!form || form.dataset.submitted === '1') {
        if (form && form.dataset.submitted === '1') e.preventDefault();
        return;
      }
      form.dataset.submitted = '1';
      var btn = form.querySelector('button[type="submit"]');
      if (btn) {
        btn.disabled = true;
        btn.dataset.original = btn.textContent;
        btn.textContent = '处理中…';
      }
      // 15 秒后恢复，避免网络异常时按钮永久禁用
      setTimeout(function () {
        if (form.dataset.submitted === '1') {
          form.dataset.submitted = '0';
          if (btn && btn.dataset.original) {
            btn.disabled = false;
            btn.textContent = btn.dataset.original;
          }
        }
      }, 15000);
    });
  }

  /* ---------- 拖拽上传占位（P2 接入真实分片） ---------- */
  function initDropZone() {
    var zone = document.getElementById('dropZone');
    if (!zone) return;
    ['dragenter', 'dragover'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        zone.classList.add('dragging');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      zone.addEventListener(ev, function (e) {
        e.preventDefault();
        zone.classList.remove('dragging');
      });
    });
    zone.addEventListener('drop', function () {
      toast('分片上传将在下一阶段开放');
    });
  }

  function boot() {
    initToasts();
    initPasswordToggles();
    initAdminDrawer();
    initPlaceholders();
    initSubmitGuard();
    initDropZone();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();

/* ==========================================================================
   导航栏用户菜单
   ========================================================================== */
(function () {
  var wrap = document.getElementById('navUser');
  if (!wrap) return;

  var btn = wrap.querySelector('.nav-user-btn');
  var menu = wrap.querySelector('.nav-user-menu');
  if (!btn || !menu) return;

  function close() {
    wrap.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }
  function toggle(e) {
    e.stopPropagation();
    var open = wrap.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  btn.addEventListener('click', toggle);

  // 点菜单外部关闭
  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) close();
  });

  // Esc 关闭
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });

  // 点菜单里的链接后关闭
  menu.addEventListener('click', function (e) {
    if (e.target.closest('a')) close();
  });
})();

/* ==========================================================================
   后台顶栏用户菜单
   ========================================================================== */
(function () {
  var wrap = document.getElementById('admUserMenu');
  if (!wrap) return;

  var btn = wrap.querySelector('.adm-user-btn');
  var menu = wrap.querySelector('.adm-user-dropdown');
  if (!btn || !menu) return;

  function close() {
    wrap.classList.remove('open');
    btn.setAttribute('aria-expanded', 'false');
  }

  btn.addEventListener('click', function (e) {
    e.stopPropagation();
    var open = wrap.classList.toggle('open');
    btn.setAttribute('aria-expanded', open ? 'true' : 'false');
  });

  document.addEventListener('click', function (e) {
    if (!wrap.contains(e.target)) close();
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') close();
  });

  menu.addEventListener('click', function (e) {
    if (e.target.closest('a')) close();
  });
})();
