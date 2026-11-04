/* 资源管理页：分片上传 + 列表操作（无依赖） */
(function () {
  'use strict';

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

  async function api(path, data) {
    var r = await fetch(BASE + path, {
      method: 'POST',
      headers: {
        'X-CSRF-Token': CSRF,
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: data
    });
    var j;
    try {
      j = await r.json();
    } catch (e) {
      throw new Error('服务器响应异常（HTTP ' + r.status + '）');
    }
    if (!j.ok) throw new Error(j.msg || '请求失败');
    return j;
  }

  /**
   * 带上传进度的 POST。fetch 拿不到上传进度，只能用 XHR。
   * onProgress 收到 0–1 的比例。
   */
  function apiWithProgress(path, data, onProgress, onXhr) {
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      if (typeof onXhr === 'function') onXhr(xhr);
      xhr.open('POST', BASE + path);
      xhr.setRequestHeader('X-CSRF-Token', CSRF);
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      if (onProgress && xhr.upload) {
        xhr.upload.onprogress = function (e) {
          if (e.lengthComputable && e.total > 0) {
            onProgress(Math.min(1, e.loaded / e.total));
          }
        };
      }

      xhr.onload = function () {
        var j;
        try {
          j = JSON.parse(xhr.responseText);
        } catch (err) {
          reject(new Error('服务器响应异常（HTTP ' + xhr.status + '）'));
          return;
        }
        if (!j.ok) reject(new Error(j.msg || '请求失败'));
        else resolve(j);
      };
      xhr.onerror = function () { reject(new Error('网络错误')); };
      xhr.ontimeout = function () { reject(new Error('上传超时')); };
      xhr.onabort = function () { reject(new Error('已取消')); };
      xhr.send(data);
    });
  }

  function fmtSize(n) {
    if (!n) return '0 B';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = Math.floor(Math.log(n) / Math.log(1024));
    i = Math.min(i, u.length - 1);
    var v = n / Math.pow(1024, i);
    return (i === 0 ? v : v.toFixed(1)) + ' ' + u[i];
  }

  /* ---------------- 上传队列 ---------------- */

  var panel = document.getElementById('uploadPanel');
  var listEl = document.getElementById('uploadList');
  var zone = document.getElementById('dropZone');
  var fileInput = document.getElementById('fileInput');

  if (!panel || !listEl || !zone || !fileInput) return;

  // 上传目标：优先取「上传到」下拉，没有则退回筛选下拉的值。
  // 之前只读筛选下拉，导致筛选「全部文件夹」时上传一律落到根目录，
  // 想在子目录里上传只能先把筛选切过去。
  function targetFolder() {
    var up = document.getElementById('uploadFolder');
    if (up) return parseInt(up.value, 10) || 0;
    var sel = document.querySelector('.adm-filter select[name="folder_id"]');
    return sel ? parseInt(sel.value, 10) || 0 : 0;
  }

  /* ---------------- 上传任务的本地记录 ---------------- */
  // 上传是纯前端 XHR，页面一离开就断，进度只活在内存里。
  // 这里把 upload_id 和文件指纹存到 localStorage，回到页面时
  // 就能查服务端还剩哪些分片没传，让用户接着传。
  var LS_KEY = 'wxpan_uploads';

  function lsRead() {
    try { return JSON.parse(localStorage.getItem(LS_KEY) || '[]') || []; }
    catch (e) { return []; }
  }
  function lsWrite(arr) {
    try { localStorage.setItem(LS_KEY, JSON.stringify(arr.slice(0, 20))); } catch (e) {}
  }
  function rememberUpload(uploadId, file) {
    var arr = lsRead().filter(function (x) { return x.upload_id !== uploadId; });
    arr.unshift({
      upload_id: uploadId,
      name: file.name,
      size: file.size,
      folder_id: targetFolder(),
      at: Date.now()
    });
    lsWrite(arr);
  }
  function forgetUpload(uploadId) {
    lsWrite(lsRead().filter(function (x) { return x.upload_id !== uploadId; }));
  }

  var pending = 0;
  var failed = 0;
  var cancelledCount = 0;

  function createRow(file) {
    var row = document.createElement('div');
    row.className = 'up-row';
    row.innerHTML =
      '<div class="up-main">' +
        '<div class="up-name" title=""></div>' +
        '<div class="up-bar"><i></i></div>' +
      '</div>' +
      '<div class="up-meta"><span class="up-pct">0%</span><span class="up-size"></span></div>' +
      '<button type="button" class="up-close" title="移除">&times;</button>';

    row.querySelector('.up-name').textContent = file.name;
    row.querySelector('.up-name').title = file.name;
    row.querySelector('.up-size').textContent = fmtSize(file.size);

    var close = row.querySelector('.up-close');
    close.addEventListener('click', function () {
      if (row.dataset.done === '1' || row.dataset.done === '0') {
        row.remove();
        return;
      }
      row.dataset.abort = '1';
      row.classList.add('is-err');
      row.querySelector('.up-pct').textContent = '已取消';
      close.textContent = '×';
    });

    return row;
  }

  function setProgress(row, ratio) {
    var pct = Math.min(100, Math.round(ratio * 100));
    row.querySelector('.up-bar i').style.width = pct + '%';
    row.querySelector('.up-pct').textContent = pct + '%';
  }

  async function uploadOne(file, row, resume) {
    var init = null;
    try {
      return await uploadOneInner(file, row, function (v) { init = v; }, resume);
    } catch (e) {
      // 取消了就把服务端临时分片收掉，否则要等 24 小时的 GC 才清
      if (!resume && init && init.upload_id && row.dataset.abort === '1') {
        var abFd = new FormData();
        abFd.append('upload_id', init.upload_id);
        api('/api/upload/abort', abFd).catch(function () {});
      }
      throw e;
    }
  }

  async function uploadOneInner(file, row, onInit, resume) {
    var size = file.size;
    var guessChunk = 2 * 1024 * 1024;
    var guessChunks = Math.max(1, Math.ceil(size / guessChunk));

    row.querySelector('.up-pct').textContent = resume ? '续传中' : '准备中';

    var init;
    if (resume) {
      // 续传：沿用服务端已有的 upload_id，不新建任务目录
      init = { upload_id: resume.upload_id, chunk_size: resume.chunk_size, chunks: resume.chunks };
    } else {
      var initFd = new FormData();
      initFd.append('name', file.name);
      initFd.append('size', String(size));
      initFd.append('chunks', String(guessChunks));
      initFd.append('folder_id', String(targetFolder()));
      init = await api('/api/upload/init', initFd);
    }
    if (typeof onInit === 'function') onInit(init);

    if (!resume) rememberUpload(init.upload_id, file);
    row._uploadId = init.upload_id;

    var chunkSize = init.chunk_size;
    var total = init.chunks;
    var cursor = 0;
    var concurrency = Math.min(3, total);

    // 每个分片实际占多少字节：最后一片通常不满，按分片数平均会让
    // 进度条与实际传输量脱节（32MB+8MB 的文件，第一片传完就已走了 80%）。
    var weights = [];
    var sizeSum = 0;
    for (var wi = 0; wi < total; wi++) {
      var ws = wi * chunkSize;
      var w = Math.min(size, ws + chunkSize) - ws;
      weights.push(w);
      sizeSum += w;
    }

    // 每个分片各自的完成度 0–1，聚合后按字节加权得到总进度
    var parts = new Array(total).fill(0);

    // 续传：服务端已有的分片直接算 100%，worker 也会跳过
    var haveSet = {};
    if (resume && resume.received && resume.received.length) {
      resume.received.forEach(function (idx) {
        if (idx >= 0 && idx < total) { parts[idx] = 1; haveSet[idx] = 1; }
      });
    }

    // 进行中的请求：取消时要真的掐断，否则已经在传的分片会一直跑完。
    // 32MB 分片要传二十多秒，3 个 worker 并行时点取消等于什么都没做。
    var inflight = [];
    function killInflight() {
      inflight.forEach(function (x) { try { x.abort(); } catch (e) {} });
      inflight = [];
    }
    var watcher = setInterval(function () {
      if (row.dataset.abort === '1') {
        killInflight();
      }
    }, 300);
    function refresh() {
      var done = 0;
      for (var k = 0; k < total; k++) done += weights[k] * parts[k];
      setProgress(row, sizeSum > 0 ? done / sizeSum : 0);
    }

    refresh();   // 续传时先按已传分片刷一次，避免从 0% 慢慢爬

    async function worker() {
      while (cursor < total) {
        if (row.dataset.abort === '1') throw new Error('已取消');

        var i = cursor++;

        // 续传时这些分片服务端已经有了，直接跳过
        if (haveSet[i]) { refresh(); continue; }

        var start = i * chunkSize;
        var end = Math.min(size, start + chunkSize);
        var blob = file.slice(start, end);

        var fd = new FormData();
        fd.append('upload_id', init.upload_id);
        fd.append('index', String(i));
        fd.append('file', blob, 'blob');

        var retry = 0;
        while (true) {
          try {
            // 传的过程中持续回报，否则 32MB 分片要传二十多秒，
            // 这段时间进度条完全静止，看起来像卡死。
            await apiWithProgress('/api/upload/chunk', fd, function (r) {
              parts[i] = r;
              refresh();
            }, function (xhr) {
              inflight.push(xhr);
              xhr.addEventListener('loadend', function () {
                var p = inflight.indexOf(xhr);
                if (p >= 0) inflight.splice(p, 1);
              });
            });
            parts[i] = 1;
            refresh();
            break;
          } catch (e) {
            // 取消不是网络失败：直接抛出，不能进重试。
            // 否则 abort 掉的请求会被当成瞬时错误重发，
            // 每个 worker 各重试两次，进度条跟着反复归零又涨回去。
            if (row.dataset.abort === '1' || /已取消/.test(e.message || '')) {
              throw e;
            }
            if (++retry > 2) throw e;
            parts[i] = 0;
            refresh();
            await new Promise(function (r) { setTimeout(r, 600 * retry); });
          }
        }
      }
    }

    try {
      await Promise.all(Array.from({ length: concurrency }, worker));
    } finally {
      clearInterval(watcher);
      killInflight();
    }

    // 走到这里说明所有分片都传完了。若期间取消过，前面 worker 会抛异常，
    // 不会执行到这里；这里再兜一次底，避免取消后仍然 finish 入库。
    if (row.dataset.abort === '1') throw new Error('已取消');

    var finFd = new FormData();
    finFd.append('upload_id', init.upload_id);
    return await api('/api/upload/finish', finFd);
  }

  async function handleFiles(files) {
    if (!files || !files.length) return;

    panel.hidden = false;

    for (var k = 0; k < files.length; k++) {
      (function (file) {
        var row = createRow(file);
        listEl.appendChild(row);
        pending++;

        uploadOne(file, row)
          .then(function (res) {
            row.dataset.done = '1';
            row.classList.add('is-ok');
            row.querySelector('.up-bar i').style.width = '100%';
            row.querySelector('.up-pct').textContent = res.deduped ? '秒传' : '完成';
            row.querySelector('.up-size').textContent = fmtSize(res.size);
            if (res.deduped) row.classList.add('is-dedup');
            if (row._uploadId) forgetUpload(row._uploadId);
          })
          .catch(function (err) {
            var cancelled = /已取消/.test(err.message || '');
            row.dataset.done = '0';
            row.classList.add(cancelled ? 'is-cancel' : 'is-err');
            row.querySelector('.up-pct').textContent = cancelled ? '已取消' : '失败';
            row.querySelector('.up-size').textContent = cancelled ? '' : err.message;
            if (cancelled) { cancelledCount++; } else { failed++; }
          })
          .finally(function () {
            pending--;
            if (pending === 0) {
              if (failed > 0) {
                toast(failed + ' 个文件上传失败', 'error');
              } else if (cancelledCount > 0) {
                // 全部被取消时不要假装成功，也不要自动刷新覆盖掉取消记录
                toast(cancelledCount + ' 个上传已取消');
              } else {
                toast('全部上传完成，正在刷新列表…');
                setTimeout(function () { location.reload(); }, 900);
              }
            }
          });
      })(files[k]);
    }
  }

  /* 选择入口 */
  var pickBtn = document.getElementById('pickFiles');
  var dropPick = document.getElementById('dropPick');

  function openPicker() { fileInput.click(); }

  if (pickBtn) pickBtn.addEventListener('click', openPicker);
  if (dropPick) dropPick.addEventListener('click', openPicker);
  document.querySelectorAll('.js-pick').forEach(function (b) {
    b.addEventListener('click', openPicker);
  });

  fileInput.addEventListener('change', function () {
    handleFiles(fileInput.files);
    fileInput.value = '';
  });

  /* ---------------- 未完成的上传 ---------------- */
  // 页面加载时问服务端还剩哪些任务，配合本地记录展示。
  // 用户点「继续」需要重选同一个文件——浏览器不允许脚本重建 File 对象，
  // 所以这里校验文件名与大小，一致才允许接上，避免传错数据。
  var resumeBox = document.getElementById('resumeBox');
  var resumeFile = document.getElementById('resumeInput');
  var resumeTarget = null;

  function renderPending(items) {
    if (!resumeBox) return;
    if (!items || !items.length) { resumeBox.hidden = true; return; }

    resumeBox.innerHTML = '<div class="resume-title">有 ' + items.length + ' 个未完成的上传</div>' +
      items.map(function (it) {
        var pct = it.chunks > 0 ? Math.round(it.received / it.chunks * 100) : 0;
        var full = pct >= 100;
        return '<div class="resume-item" data-id="' + it.upload_id + '"' +
          ' data-name="' + encodeURIComponent(it.name) + '"' +
          ' data-size="' + it.size + '"' +
          ' data-chunks="' + it.chunks + '">' +
          '<div class="resume-main"><b>' + escapeHtml(it.name) + '</b>' +
          '<small>' + fmtSize(it.size) + ' · ' + it.received + '/' + it.chunks + ' 片 (' + pct + '%)' +
          (full ? '，只差合并' : '') + '</small></div>' +
          '<button type="button" class="btn btn-primary btn-sm js-resume">' +
          (full ? '完成上传' : '继续上传') + '</button>' +
          '<button type="button" class="resume-x" title="丢弃这个任务">&times;</button>' +
          '</div>';
      }).join('');
    resumeBox.hidden = false;
  }

  function escapeHtml(t) {
    return String(t).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function loadPending() {
    api('/api/upload/pending').then(function (res) {
      if (!res || !res.ok) return;
      var local = lsRead();
      // 只展示本地记录里还有的任务，避免列出别处传了一半又放弃的垃圾
      var byId = {};
      local.forEach(function (x) { byId[x.upload_id] = 1; });
      var items = (res.items || []).filter(function (it) { return byId[it.upload_id]; });
      renderPending(items);
    }).catch(function () {});
  }

  if (resumeBox) {
    resumeBox.addEventListener('click', function (e) {
      var item = e.target.closest ? e.target.closest('.resume-item') : null;
      if (!item) return;
      var id = item.dataset.id;

      // 丢弃
      if (e.target.closest('.resume-x')) {
        forgetUpload(id);
        var fd = new FormData();
        fd.append('upload_id', id);
        api('/api/upload/abort', fd).catch(function () {});
        item.remove();
        if (!resumeBox.querySelector('.resume-item')) resumeBox.hidden = true;
        return;
      }

      // 继续 / 完成
      if (e.target.closest('.js-resume')) {
        resumeTarget = {
          upload_id: id,
          name: decodeURIComponent(item.dataset.name),
          size: parseInt(item.dataset.size, 10),
          chunks: parseInt(item.dataset.chunks, 10)
        };
        if (resumeFile) resumeFile.click();
      }
    });
  }

  if (resumeFile) {
    resumeFile.addEventListener('change', function () {
      var f = resumeFile.files && resumeFile.files[0];
      resumeFile.value = '';
      if (!f || !resumeTarget) return;

      // 必须是同一个文件：名字与大小都得对上，否则分片拼出来是坏的
      if (f.name !== resumeTarget.name || f.size !== resumeTarget.size) {
        if (window.wxToast) window.wxToast('文件不匹配：需要「' + resumeTarget.name + '」(' + fmtSize(resumeTarget.size) + ')', 'err');
        return;
      }

      var target = resumeTarget;
      resumeTarget = null;

      panel.hidden = false;
      var row = createRow(f);
      listEl.appendChild(row);
      pending++;

      api('/api/upload/resume', (function () { var d = new FormData(); d.append('upload_id', target.upload_id); return d; })())
        .then(function (info) {
          if (!info || !info.ok) throw new Error((info && info.msg) || '任务已失效');
          row._uploadId = info.upload_id;
          return uploadOne(f, row, info);
        })
        .then(function (res) {
          row.dataset.done = '1';
          row.classList.add('is-ok');
          row.querySelector('.up-bar i').style.width = '100%';
          row.querySelector('.up-pct').textContent = res.deduped ? '秒传' : '完成';
          row.querySelector('.up-size').textContent = fmtSize(res.size);
          forgetUpload(target.upload_id);
          loadPending();
        })
        .catch(function (err) {
          row.dataset.done = '0';
          row.classList.add('is-err');
          row.querySelector('.up-pct').textContent = '失败';
          if (window.wxToast) window.wxToast('续传失败：' + err.message, 'err');
        });
    });
  }

  loadPending();

  /* 拖拽 */
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
  zone.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
      handleFiles(e.dataTransfer.files);
    }
  });

  /* 清空已完成 */
  var clearBtn = document.getElementById('clearDone');
  if (clearBtn) {
    clearBtn.addEventListener('click', function () {
      listEl.querySelectorAll('.up-row').forEach(function (r) {
        if (r.dataset.done === '1' || r.dataset.done === '0') r.remove();
      });
      if (!listEl.children.length) panel.hidden = true;
    });
  }

  /* ---------------- 列表操作 ---------------- */

  var checkAll = document.getElementById('checkAll');
  var batchBtn = document.getElementById('batchDelete');
  var batchForm = document.getElementById('batchForm');

  function selectedIds() {
    return Array.prototype.map.call(
      document.querySelectorAll('.row-chk:checked'),
      function (c) { return parseInt(c.value, 10); }
    ).filter(Boolean);
  }

  function syncBatchBtn() {
    if (!batchBtn) return;
    var n = selectedIds().length;
    if (batchBtn) batchBtn.disabled = n === 0;
    if (moveBtn) moveBtn.disabled = n === 0;
  }

  if (checkAll) {
    checkAll.addEventListener('change', function () {
      document.querySelectorAll('.row-chk').forEach(function (c) {
        c.checked = checkAll.checked;
      });
      syncBatchBtn();
    });
  }

  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('row-chk')) {
      var all = document.querySelectorAll('.row-chk');
      var checked = document.querySelectorAll('.row-chk:checked');
      if (checkAll) checkAll.checked = all.length > 0 && all.length === checked.length;
      syncBatchBtn();
    }
  });

  if (batchBtn) {
    batchBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (!ids.length) return;
      openDelModal('batch', ids, '');
    });
  }

  /* 批量移动到文件夹 */
  var moveBtn = document.getElementById('batchMove');
  var batchFolder = document.getElementById('batchFolder');

  function syncBatchButtons() {
    var n = selectedIds().length;
    if (batchBtn) batchBtn.disabled = n === 0;
    if (moveBtn) moveBtn.disabled = n === 0;
  }

  if (moveBtn && batchFolder) {
    moveBtn.addEventListener('click', function () {
      var ids = selectedIds();
      if (!ids.length) return;
      var fid = parseInt(batchFolder.value, 10) || 0;
      var dest = batchFolder.options[batchFolder.selectedIndex].textContent.trim();

      if (!confirm('把选中的 ' + ids.length + ' 个文件移动到「' + dest + '」？')) return;

      moveBtn.disabled = true;
      var fd = new FormData();
      fd.append('_token', (document.querySelector('input[name="_token"]') || {}).value || '');
      ids.forEach(function (id) { fd.append('ids[]', id); });
      fd.append('folder_id', String(fid));

      fetch('/admin/files/batch-move', {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
      })
        .then(function (r) { return r.json(); })
        .then(function (res) {
          if (window.wxToast) window.wxToast(res.msg, res.ok ? 'ok' : 'err');
          if (res.ok) setTimeout(function () { location.reload(); }, 600);
          else moveBtn.disabled = false;
        })
        .catch(function (e) {
          if (window.wxToast) window.wxToast('移动失败：' + e.message, 'err');
          moveBtn.disabled = false;
        });
    });
  }

  /* 单删 / 批量删除：统一走确认弹窗（可选永久删除） */
  var delModal = document.getElementById('delModal');
  var delTitle = document.getElementById('delTitle');
  var delMsg = document.getElementById('delMsg');
  var delHard = document.getElementById('delHard');
  var delConfirm = document.getElementById('delConfirm');
  var delState = { mode: 'single', ids: [] };

  function openDelModal(mode, ids, label) {
    if (!delModal) return false;
    delState.mode = mode;
    delState.ids = ids;
    delTitle.textContent = mode === 'batch' ? '批量删除' : '删除文件';
    delMsg.textContent = mode === 'batch'
      ? '将把选中的 ' + ids.length + ' 个文件移入回收站。'
      : '「' + label + '」将被移入回收站。';
    delHard.checked = false;
    delModal.hidden = false;
    return true;
  }

  function closeDelModal() {
    if (delModal) delModal.hidden = true;
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    if (e.target.closest('[data-close]')) closeDelModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeDelModal();
  });

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.js-del') : null;
    if (!btn) return;
    e.preventDefault();
    openDelModal('single', [parseInt(btn.getAttribute('data-id'), 10)], btn.getAttribute('data-name') || '');
  });

  if (delConfirm) {
    delConfirm.addEventListener('click', async function () {
      var hard = delHard && delHard.checked ? '1' : '0';
      var fd = new FormData();
      var url;

      if (delState.mode === 'batch') {
        fd.append('ids', delState.ids.join(','));
        url = '/admin/files/batch-delete';
      } else {
        fd.append('id', String(delState.ids[0]));
        url = '/admin/files/delete';
      }
      fd.append('hard', hard);

      delConfirm.disabled = true;
      try {
        var r = await api(url, fd);
        toast(r.msg || '已删除');
        closeDelModal();
        setTimeout(function () { location.reload(); }, 600);
      } catch (err) {
        toast(err.message, 'error');
        delConfirm.disabled = false;
      }
    });
  }

  /* 编辑弹窗 */
  var modal = document.getElementById('editModal');
  var editId = document.getElementById('editId');
  var editName = document.getElementById('editName');
  var editFolder = document.getElementById('editFolder');
  var editPublic = document.getElementById('editPublic');
  var editLevel = document.getElementById('editLevel');
  var editViewLevel = document.getElementById('editViewLevel');
  var editLoginReq = document.getElementById('editLoginReq');

  function closeModal() { if (modal) modal.hidden = true; }

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.js-edit') : null;
    if (btn && modal) {
      editId.value = btn.getAttribute('data-id');
      editName.value = btn.getAttribute('data-name');
      editFolder.value = btn.getAttribute('data-folder');
      editPublic.value = btn.getAttribute('data-public');
      if (editLevel) editLevel.value = btn.getAttribute('data-level') || '0';
      if (editViewLevel) editViewLevel.value = btn.getAttribute('data-view-level') || '0';
      if (editLoginReq) editLoginReq.value = btn.getAttribute('data-login-req') || '0';
      modal.hidden = false;
      editName.focus();
      return;
    }
    if (e.target.closest && e.target.closest('[data-close]')) {
      closeModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeModal();
  });

  /* ---------------- 分享 ---------------- */

  var shareModal = document.getElementById('shareModal');
  var shareFileId = null;
  var shareResult = document.getElementById('shareResult');

  function closeShare() {
    if (shareModal) shareModal.hidden = true;
    if (shareResult) shareResult.hidden = true;
  }

  document.addEventListener('click', function (e) {
    if (!e.target.closest) return;
    var b = e.target.closest('.js-share');
    if (b && shareModal) {
      e.preventDefault();
      shareFileId = b.getAttribute('data-id');
      document.getElementById('shareFileName').textContent = '「' + (b.getAttribute('data-name') || '') + '」';
      document.getElementById('shareCodeInput').value = '';
      document.getElementById('shareDays').value = '7';
      document.getElementById('shareMax').value = '0';
      if (shareResult) shareResult.hidden = true;
      shareModal.hidden = false;
      return;
    }
    if (e.target.closest('[data-close]')) closeShare();
  });

  var shareCreate = document.getElementById('shareCreate');
  if (shareCreate) {
    shareCreate.addEventListener('click', async function () {
      if (!shareFileId) return;
      var fd = new FormData();
      fd.append('file_id', shareFileId);
      fd.append('code', document.getElementById('shareCodeInput').value.trim());
      fd.append('days', document.getElementById('shareDays').value || '0');
      fd.append('max_downloads', document.getElementById('shareMax').value || '0');

      shareCreate.disabled = true;
      try {
        var r = await api('/admin/shares/create', fd);
        document.getElementById('shareUrl').value = r.url;
        document.getElementById('shareCodeOut').textContent = r.code || '无';
        shareResult.hidden = false;
        toast('分享链接已生成');
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        shareCreate.disabled = false;
      }
    });
  }

  var shareCopy = document.getElementById('shareCopy');
  if (shareCopy) {
    shareCopy.addEventListener('click', async function () {
      var url = document.getElementById('shareUrl').value;
      try {
        await navigator.clipboard.writeText(url);
        toast('链接已复制');
      } catch (e) {
        window.prompt('手动复制链接：', url);
      }
    });
  }
})();
