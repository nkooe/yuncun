import fs from 'node:fs';

/**
 * 窄屏排版检查（CDP）
 *
 * 在指定宽度下打开页面，测量关键元素的换行情况并截图。
 *   node tools/ui-layout.mjs <路径> <宽度> [输出png]
 */
const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const PATH = process.argv[2] || '/admin/logs';
const WIDTH = parseInt(process.argv[3] || '476', 10);
const OUT = process.argv[4] || '';
const ADMIN_PASS = '123456';

(async () => {
  const list = await (await fetch(CDP + '/json')).json();
  const target = list.find(t => t.type === 'page');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(r => (ws.onopen = r));

  let id = 0;
  const pending = new Map();
  ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
  };
  const send = (method, params = {}) => new Promise(r => {
    const i = ++id; pending.set(i, r);
    ws.send(JSON.stringify({ id: i, method, params }));
  });
  const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true, awaitPromise: true });
    return r.result?.result?.value;
  };
  const sleep = ms => new Promise(r => setTimeout(r, ms));

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', {
    width: WIDTH, height: 900, deviceScaleFactor: 2, mobile: true,
  });

  // 登录
  await send('Page.navigate', { url: SITE + '/logout' });
  await sleep(1200);
  await send('Page.navigate', { url: SITE + '/login' });
  await sleep(2200);
  await ev(`(() => {
    const t = document.querySelector('input[name="_token"]');
    const u = document.querySelector('input[name="username"]');
    const p = document.querySelector('input[name="password"]');
    if (!t || !u || !p) return 'not-ready';
    u.value = 'admin'; p.value = ${JSON.stringify(ADMIN_PASS)}; u.form.submit();
    return 'ok';
  })()`);
  await sleep(2800);

  await send('Page.navigate', { url: SITE + PATH });
  await sleep(2500);

  const report = await ev(`(() => {
    const out = { width: window.innerWidth, scrollW: document.documentElement.scrollWidth };
    const tabs = Array.from(document.querySelectorAll('.adm-tab'));
    out.tabs = tabs.map(t => {
      const r = t.getBoundingClientRect();
      // 换行判定：量文字本身的 Range 矩形。纵向出现多个 top 就是折行了。
      // 之前用 height / lineHeight，lineHeight 为 normal 时 parseFloat 得
      // NaN，fallback 的固定值会把正常的单行误判成两行。
      const range = document.createRange();
      range.selectNodeContents(t);
      const rects = Array.from(range.getClientRects()).filter(x => x.height > 1);
      // 同一行内文字与「计数徽章」是不同 inline 元素，baseline 对齐后
      // top 会差几像素，不能直接按 top 去重。差值小于半个行高算同一行。
      const tops = rects.map(x => x.top).sort((a, b) => a - b);
      const rowTops = [];
      for (const tp of tops) {
        if (!rowTops.length || tp - rowTops[rowTops.length - 1] > 10) rowTops.push(tp);
      }
      return {
        text: t.textContent.trim().replace(/\\s+/g, ' '),
        w: Math.round(r.width), h: Math.round(r.height),
        lines: Math.max(1, rowTops.length),
        wraps: rowTops.length > 1,
      };
    });
    const btns = Array.from(document.querySelectorAll('.log-clean .btn-sm, .log-clean select'));
    out.controls = btns.map(b => {
      const r = b.getBoundingClientRect();
      return {
        tag: b.tagName.toLowerCase(),
        text: (b.value || b.textContent).trim().slice(0, 14),
        w: Math.round(r.width), h: Math.round(r.height),
        scrollW: b.scrollWidth,
        clipped: b.scrollWidth > Math.ceil(r.width) + 1,
      };
    });
    const toolbar = document.querySelector('.log-clean');
    if (toolbar) {
      const r = toolbar.getBoundingClientRect();
      out.toolbar = { w: Math.round(r.width), h: Math.round(r.height) };
    }
    return out;
  })()`);

  console.log('\n路径 ' + PATH + ' @ ' + WIDTH + 'px');
  console.log('  视口宽 ' + report.width + '，文档滚动宽 ' + report.scrollW +
    (report.scrollW > report.width + 1 ? '  \x1b[31m← 出现横向滚动\x1b[0m' : '  \x1b[32m✓ 无横向溢出\x1b[0m'));
  console.log('\n  Tab：');
  (report.tabs || []).forEach(t => {
    console.log(`    ${t.wraps ? '\x1b[31m✗\x1b[0m' : '\x1b[32m✓\x1b[0m'} "${t.text}"  ${t.w}×${t.h}  ${t.lines} 行`);
  });
  console.log('\n  清理控件：');
  (report.controls || []).forEach(c => {
    console.log(`    ${c.clipped ? '\x1b[31m✗\x1b[0m' : '\x1b[32m✓\x1b[0m'} ${c.tag} "${c.text}"  宽 ${c.w}  内容宽 ${c.scrollW}`);
  });
  if (report.toolbar) console.log('\n  工具条高 ' + report.toolbar.h + 'px');

  if (OUT) {
    const shot = await send('Page.captureScreenshot', { format: 'png', captureBeyondViewport: true });
    fs.writeFileSync(OUT, Buffer.from(shot.result.data, 'base64'));
    console.log('\n  截图: ' + OUT);
  }

  ws.close();
  process.exit(0);
})();
