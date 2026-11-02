/* 文件夹管理页：新建 / 编辑 / 删除（无依赖） */
(function () {
  'use strict';

  var modal   = document.getElementById('folderModal');
  var titleEl = document.getElementById('folderModalTitle');
  var form    = document.getElementById('folderForm');
  if (!modal || !form) return;

  var fId     = document.getElementById('fId');
  var fName   = document.getElementById('fName');
  var fParent = document.getElementById('fParent');
  var fDesc   = document.getElementById('fDesc');
  var fSort   = document.getElementById('fSort');
  var fPublic = document.getElementById('fPublic');
  var fLevel  = document.getElementById('fLevel');
  var fViewLevel = document.getElementById('fViewLevel');
  var fLoginReq = document.getElementById('fLoginReq');

  var meta = function (n) {
    var el = document.querySelector('meta[name="' + n + '"]');
    return el ? el.getAttribute('content') : '';
  };
  var CSRF = meta('csrf-token');
  var BASE = meta('base-url') || '';

  function toast(msg, type) {
    if (window.wxToast) return window.wxToast(msg, type);
    console.log(msg);
  }

  function openModal(mode, data) {
    if (mode === 'edit' && data) {
      titleEl.textContent = '编辑文件夹';
      fId.value     = data.id;
      fName.value   = data.name;
      fParent.value = data.parent;
      fDesc.value   = data.desc || '';
      fSort.value   = data.sort;
      fPublic.value = data.public;
      if (fLevel) fLevel.value = data.level || '0';
      if (fViewLevel) fViewLevel.value = data.viewLevel || '0';
      if (fLoginReq) fLoginReq.value = data.loginReq || '0';
      // 禁止把自己设为自己的父级（后端也会校验）
      Array.prototype.forEach.call(fParent.options, function (o) {
        o.disabled = (o.value === String(data.id));
      });
    } else {
      titleEl.textContent = '新建文件夹';
      fId.value = '0';
      form.reset();
      fParent.value = '0';
      fSort.value = '0';
      fPublic.value = '1';
      if (fLevel) fLevel.value = '0';
      if (fViewLevel) fViewLevel.value = '0';
      if (fLoginReq) fLoginReq.value = '0';
      Array.prototype.forEach.call(fParent.options, function (o) { o.disabled = false; });
    }
    modal.hidden = false;
    fName.focus();
    fName.select();
  }

  function closeModal() { modal.hidden = true; }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;

    if (e.target.closest('#newFolder') || e.target.closest('#newFolder2')) {
      e.preventDefault();
      openModal('new');
      return;
    }

    var editBtn = e.target.closest('.js-edit-folder');
    if (editBtn) {
      e.preventDefault();
      openModal('edit', {
        id:     editBtn.getAttribute('data-id'),
        name:   editBtn.getAttribute('data-name'),
        parent: editBtn.getAttribute('data-parent'),
        sort:   editBtn.getAttribute('data-sort'),
        public: editBtn.getAttribute('data-public'),
        level:  editBtn.getAttribute('data-level'),
        viewLevel: editBtn.getAttribute('data-view-level'),
        loginReq:  editBtn.getAttribute('data-login-req'),
        desc:   editBtn.getAttribute('data-desc')
      });
      return;
    }

    if (e.target.closest('[data-close]')) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
  });

  /* 删除 */
  document.addEventListener('click', async function (e) {
    var btn = e.target.closest ? e.target.closest('.js-del-folder') : null;
    if (!btn) return;

    var name = btn.getAttribute('data-name') || '';
    if (!confirm('删除「' + name + '」？\n目录内的文件会一并移入回收站。')) return;

    var fd = new FormData();
    fd.append('id', btn.getAttribute('data-id'));

    try {
      var r = await fetch(BASE + '/admin/folders/delete', {
        method: 'POST',
        headers: {
          'X-CSRF-Token': CSRF,
          'X-Requested-With': 'XMLHttpRequest'
        },
        body: fd
      });
      var j = await r.json();
      if (!j.ok) throw new Error(j.msg || '删除失败');
      toast(j.msg || '已删除');
      setTimeout(function () { location.reload(); }, 500);
    } catch (err) {
      toast(err.message, 'error');
    }
  });
})();
