/**
 * 取消上传实测（CDP）
 *
 * 验证两件事：
 *   1. 点取消后，进行中的分片是否立刻停
 *   2. 服务端临时分片目录是否被清理（有没有调 /api/upload/abort）
 */
const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const FILE = process.argv[2];
const ADMIN_PASS = process.argv[3] || '123456';

if (!FILE) {
  console.error('用法: node ui-cancel.mjs <文件路径> [admin密码]');
  process.exit(1);
}

(async () => {
  const list = await (await fetch(CDP + '/json')).json();
  const target = list.find(t => t.type === 'page');

  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(r => (ws.onopen = r));

  let id = 0;
  const pending = new Map();
  const netReqs = [];
  const netFails = [];

  ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
      pending.get(m.id)(m);
      pending.delete(m.id);
      return;
    }
    if (m.method === 'Network.requestWillBeSent') {
      const u = m.params.request.url;
      if (u.includes('/api/upload')) netReqs.push({ url: u, t: Date.now() });
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
    return r.result?.result?.value;
  };

  const sleep = ms => new Promise(r => setTimeout(r, ms));

  await send('Page.enable');
  await send('Runtime.enable');
  await send('DOM.enable');
  await send('Network.enable');

  // 登录
  console.log('\n[1] 登录');
  await send('Page.navigate', { url: SITE + '/login' });
  await sleep(2000);
  await ev(`(() => {
    const t = document.querySelector('input[name="_token"]');
    const u = document.querySelector('input[name="username"]');
    const p = document.querySelector('input[name="password"]');
    if (!t) return 'form-not-found';
    u.value = 'admin'; p.value = ${JSON.stringify(ADMIN_PASS)}; u.form.submit();
    return 'ok';
  })()`);
  await sleep(3000);

  // 打开文件管理
  console.log('\n[2] 打开 /admin/files');
  await send('Page.navigate', { url: SITE + '/admin/files' });
  await sleep(2500);

  // 选文件
  console.log('\n[3] 选择文件');
  const doc = await send('DOM.getDocument', { depth: -1 });
  const node = await send('DOM.querySelector', {
    nodeId: doc.result.root.nodeId,
    selector: 'input[type=file]',
  });
  await send('DOM.setFileInputFiles', {
    nodeId: node.result.nodeId,
    files: [FILE],
  });
  await ev(`(() => {
    const f = document.querySelector('input[type=file]');
    if (f && f.files && f.files.length) f.dispatchEvent(new Event('change', { bubbles: true }));
    return 'dispatched';
  })()`);

  // 等上传真正开始（出现进度且 > 0）
  console.log('\n[4] 等待上传开始');
  let started = false;
  for (let i = 0; i < 20; i++) {
    await sleep(500);
    const p = await ev(`(() => {
      const el = document.querySelector('.up-pct');
      return el ? el.textContent.trim() : null;
    })()`);
    if (p && /^\d+%$/.test(p) && p !== '0%') {
      console.log('  已开始，当前 ' + p);
      started = true;
      break;
    }
  }
  if (!started) {
    console.log('  ✗ 上传未开始，无法测取消');
    ws.close();
    process.exit(1);
  }

  // 点取消
  console.log('\n[5] 点击取消');
  const beforeCancel = Date.now();
  const cancelInfo = await ev(`(() => {
    const row = document.querySelector('.up-row');
    if (!row) return { err: 'no-row' };
    const btn = row.querySelector('.up-cancel, .up-x, button');
    if (!btn) return { err: 'no-cancel-button', html: row.innerHTML.slice(0, 200) };
    btn.click();
    return { clicked: true, label: btn.textContent.trim() };
  })()`);
  console.log('  ' + JSON.stringify(cancelInfo));

  // 观察取消后的表现
  console.log('\n[6] 取消后的状态（每 1.5 秒）');
  for (let i = 0; i < 8; i++) {
    await sleep(1500);
    const snap = await ev(`(() => {
      const row = document.querySelector('.up-row');
      const pct = document.querySelector('.up-pct');
      return {
        cls: row ? row.className : null,
        pct: pct ? pct.textContent.trim() : null,
        abort: row ? row.dataset.abort : null,
      };
    })()`);
    console.log(`  +${((Date.now() - beforeCancel) / 1000).toFixed(1)}s  ${JSON.stringify(snap)}`);
  }

  // 是否调了 abort 接口
  console.log('\n[7] 网络请求汇总');
  const abortCalls = netReqs.filter(r => r.url.includes('/abort'));
  const chunkCalls = netReqs.filter(r => r.url.includes('/chunk'));
  console.log('  chunk 请求数: ' + chunkCalls.length);
  console.log('  abort 请求数: ' + abortCalls.length +
    (abortCalls.length === 0 ? '   ⚠ 取消后没有通知服务端' : '   ✓ 已通知'));

  const aborts = netFails.filter(f => f.errorText === 'net::ERR_ABORTED');
  console.log('  被中断的连接: ' + aborts.length);

  ws.close();
  process.exit(0);
})();
