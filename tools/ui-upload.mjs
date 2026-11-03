/**
 * 前端上传流程实测（CDP）
 *
 * 真实操作 admin 页面的 upload.js：登录 → 选文件 → 观察进度 → 校验结果。
 * 目的是验证 curl 测不到的东西：进度条刷新、JS 异常、取消行为。
 */
const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const FILE = process.argv[2];
const ADMIN_PASS = process.argv[3] || '123456';

if (!FILE) {
  console.error('用法: node ui-upload.mjs <文件路径> [admin密码]');
  process.exit(1);
}

(async () => {
  const list = await (await fetch(CDP + '/json')).json();
  let target = list.find(t => t.type === 'page');
  if (!target) {
    target = await (await fetch(CDP + '/json/new?about:blank')).json();
  }

  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(r => (ws.onopen = r));

  let id = 0;
  const pending = new Map();
  const consoleErrors = [];
  const pageErrors = [];
  const netFails = [];

  ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
      pending.get(m.id)(m);
      pending.delete(m.id);
      return;
    }
    if (m.method === 'Runtime.exceptionThrown') {
      pageErrors.push(m.params.exceptionDetails);
    }
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
      consoleErrors.push(m.params.args.map(a => a.value ?? a.description).join(' '));
    }
    if (m.method === 'Network.loadingFailed') {
      netFails.push(m.params);
    }
  };

  const send = (method, params = {}) =>
    new Promise(r => {
      const i = ++id;
      pending.set(i, r);
      ws.send(JSON.stringify({ id: i, method, params }));
    });

  const ev = async expr => {
    const r = await send('Runtime.evaluate', {
      expression: expr,
      returnByValue: true,
      awaitPromise: true,
    });
    if (r.result?.exceptionDetails) {
      return { __err: r.result.exceptionDetails.text };
    }
    return r.result?.result?.value;
  };

  const sleep = ms => new Promise(r => setTimeout(r, ms));

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');
  await send('Network.enable');

  // ── 登录 ──────────────────────────────────────────────
  console.log('\n[1] 登录');
  await send('Page.navigate', { url: SITE + '/login' });
  await sleep(2000);

  const loginResult = await ev(`(async () => {
    const tok = document.querySelector('input[name="_token"]');
    const u = document.querySelector('input[name="username"]');
    const p = document.querySelector('input[name="password"]');
    if (!tok || !u || !p) return 'form-not-found';
    u.value = 'admin';
    p.value = ${JSON.stringify(ADMIN_PASS)};
    u.form.submit();
    return 'submitting';
  })()`);
  console.log('  ' + loginResult);
  await sleep(3000);

  const whoami = await ev(`document.querySelector('.adm-sidebar, .adm-wrap, #batchForm') ? 'logged-in' : 'not-logged-in'`);
  console.log('  状态: ' + whoami);

  // ── 进入文件管理页 ────────────────────────────────────
  console.log('\n[2] 打开 /admin/files');
  await send('Page.navigate', { url: SITE + '/admin/files' });
  await sleep(2500);

  const hasUpload = await ev(`(() => {
    const f = document.querySelector('input[type=file]');
    return f ? { found: true, multiple: f.multiple, accept: f.accept || '(none)' } : { found: false };
  })()`);
  console.log('  上传控件: ' + JSON.stringify(hasUpload));
  if (!hasUpload.found) {
    console.log('  ✗ 页面上没有 file input，无法继续');
    ws.close();
    process.exit(1);
  }

  // ── 注入进度采样器 ────────────────────────────────────
  console.log('\n[3] 注入进度采样器');
  await ev(`(() => {
    sessionStorage.removeItem('__prog');
    window.__progTimer = setInterval(() => {
      const pct = document.querySelector('.up-pct');
      const bar = document.querySelector('.up-bar i');
      if (!pct) return;
      let arr = [];
      try { arr = JSON.parse(sessionStorage.getItem('__prog') || '[]'); } catch (e) {}
      const last = arr.length ? arr[arr.length - 1].text : '';
      const cur = pct.textContent.trim();
      if (cur !== last) {
        arr.push({ t: Date.now(), text: cur, width: bar ? bar.style.width : '' });
        sessionStorage.setItem('__prog', JSON.stringify(arr));
      }
    }, 100);
    return 'sampler-on';
  })()`);

  // ── 选文件并触发 change ───────────────────────────────
  console.log('\n[4] 选择文件');
  const doc = await send('DOM.getDocument', { depth: -1 });
  const node = await send('DOM.querySelector', {
    nodeId: doc.result.root.nodeId,
    selector: 'input[type=file]',
  });

  if (!node.result?.nodeId) {
    console.log('  ✗ 找不到 file input 节点');
    ws.close();
    process.exit(1);
  }

  await send('DOM.setFileInputFiles', {
    nodeId: node.result.nodeId,
    files: [FILE],
  });
  console.log('  已注入文件路径');

  // 某些实现依赖 change 事件，手动派发一次确保触发
  await ev(`(() => {
    const f = document.querySelector('input[type=file]');
    if (f && f.files && f.files.length) {
      f.dispatchEvent(new Event('change', { bubbles: true }));
      return 'change-dispatched, files=' + f.files.length;
    }
    return 'no-files';
  })()`).then(v => console.log('  ' + v));

  // ── 采样 40 秒 ────────────────────────────────────────
  console.log('\n[5] 观察进度（每 2 秒打印一次）');
  const t0 = Date.now();
  let last = '';
  for (let i = 0; i < 60; i++) {
    await sleep(2000);
    const snap = await ev(`(() => {
      const pct = document.querySelector('.up-pct');
      const size = document.querySelector('.up-size');
      const row = document.querySelector('.up-row');
      return {
        pct: pct ? pct.textContent.trim() : null,
        size: size ? size.textContent.trim() : null,
        cls: row ? row.className : null,
        alive: !!pct,
      };
    })()`);
    const line = JSON.stringify(snap);
    const el = ((Date.now() - t0) / 1000).toFixed(0);
    if (line !== last) {
      console.log(`  +${el}s  ${line}`);
      last = line;
    } else {
      console.log(`  +${el}s  (无变化) ${snap.pct}`);
    }
    if (!snap.alive) break;
    if (snap.cls && /is-ok|is-err/.test(snap.cls)) {
      console.log('  上传结束');
      break;
    }
  }

  // ── 进度采样序列 ──────────────────────────────────────
  const prog = await ev(`(() => { try { return JSON.parse(sessionStorage.getItem('__prog') || '[]'); } catch (e) { return []; } })()`);
  console.log('\n[6] 进度采样点数量: ' + (prog?.length ?? 0));
  if (prog && prog.length) {
    const uniq = [];
    for (const p of prog) {
      if (!uniq.length || uniq[uniq.length - 1].text !== p.text) uniq.push(p);
    }
    console.log('  变化序列:');
    uniq.slice(0, 30).forEach(p => console.log(`    ${p.text.padEnd(8)} width=${p.width}`));
    if (uniq.length > 30) console.log(`    ... 共 ${uniq.length} 个变化点`);

    const t = uniq.map(p => p.t);
    const gaps = [];
    for (let i = 1; i < t.length; i++) gaps.push(t[i] - t[i - 1]);
    if (gaps.length) {
      console.log(`  最大停顿间隔: ${Math.max(...gaps)} ms`);
    }
  }

  // ── 最终状态 ──────────────────────────────────────────
  const final = await ev(`(() => {
    const row = document.querySelector('.up-row');
    const pct = document.querySelector('.up-pct');
    const size = document.querySelector('.up-size');
    return {
      cls: row ? row.className : null,
      pct: pct ? pct.textContent.trim() : null,
      size: size ? size.textContent.trim() : null,
    };
  })()`);
  console.log('\n[7] 最终状态: ' + JSON.stringify(final));

  // ── 错误汇总 ──────────────────────────────────────────
  console.log('\n[8] JS 错误');
  console.log('  页面异常: ' + pageErrors.length);
  pageErrors.slice(0, 3).forEach(e => console.log('    ' + e.text));
  console.log('  console.error: ' + consoleErrors.length);
  consoleErrors.slice(0, 3).forEach(e => console.log('    ' + e));
  const realFails = netFails.filter(f => !/ERR_ABORTED/.test(f.errorText));
  console.log('  网络失败(排除主动中断): ' + realFails.length);
  realFails.slice(0, 3).forEach(f => console.log('    ' + f.errorText));

  // 清理
  await ev(`clearInterval(window.__progTimer)`);
  ws.close();
  process.exit(0);
})();
