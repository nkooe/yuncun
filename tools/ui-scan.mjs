/**
 * 多宽度布局扫描（CDP）
 *
 * 一次性把「页面 × 宽度」全部跑一遍，输出异常矩阵。
 *   node tools/ui-scan.mjs
 *   node tools/ui-scan.mjs 360,414,768
 */
import fs from 'node:fs';

const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const ADMIN_PASS = '123456';

const WIDTHS = (process.argv[2] || '360,414,476,600,768,1024,1280')
  .split(',').map(w => parseInt(w.trim(), 10)).filter(Boolean);

const PAGES = [
  '/',
  '/admin',
  '/admin/logs',
  '/admin/trash',
  '/admin/files',
  '/admin/users',
  '/admin/shares',
  '/admin/folders',
  '/admin/settings',
];

(async () => {
  const list = await (await fetch(CDP + '/json')).json();
  const target = list.find(t => t.type === 'page');
  if (!target) { console.error('没有可用的页面目标'); process.exit(1); }

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

  // 登录一次
  await send('Page.navigate', { url: SITE + '/logout' });
  await sleep(1200);
  await send('Page.navigate', { url: SITE + '/login' });
  await sleep(2200);
  const ok = await ev(`(() => {
    const t = document.querySelector('input[name="_token"]');
    const u = document.querySelector('input[name="username"]');
    const p = document.querySelector('input[name="password"]');
    if (!t || !u || !p) return false;
    u.value = 'admin'; p.value = ${JSON.stringify(ADMIN_PASS)}; u.form.submit();
    return true;
  })()`);
  if (!ok) { console.error('登录表单未就绪'); process.exit(1); }
  await sleep(2800);
  console.log('已登录\n');

  const probe = `(() => {
    const r = { overflow: document.documentElement.scrollWidth - window.innerWidth };
    const tabs = Array.from(document.querySelectorAll('.adm-tab'));
    r.tabWraps = tabs.filter(t => {
      const range = document.createRange();
      range.selectNodeContents(t);
      const rects = Array.from(range.getClientRects()).filter(x => x.height > 1);
      const tops = rects.map(x => x.top).sort((a, b) => a - b);
      const rows = [];
      for (const tp of tops) if (!rows.length || tp - rows[rows.length - 1] > 10) rows.push(tp);
      return rows.length > 1;
    }).map(t => t.textContent.trim().replace(/\\s+/g, ' '));

    // 表头与数据列数是否一致（只对有多少就比多少的表格）
    r.colMismatch = [];
    document.querySelectorAll('.adm-table').forEach((tbl, ti) => {
      const ths = tbl.querySelectorAll('thead tr:last-child th');
      const tr = tbl.querySelector('tbody tr');
      if (!ths.length || !tr) return;
      const visTh = Array.from(ths).filter(t => getComputedStyle(t).display !== 'none').length;
      const visTd = Array.from(tr.children).filter(t => getComputedStyle(t).display !== 'none').length;
      if (visTh !== visTd) {
        r.colMismatch.push({ table: ti, th: visTh, td: visTd });
      }
    });

    // 元素是否被容器横向裁掉
    r.clipped = [];
    document.querySelectorAll('.adm-table, .table-wrap, .adm-panel').forEach(el => {
      if (el.scrollWidth <= el.clientWidth + 2 || el.clientWidth <= 0) return;
      // overflow-x 为 auto/scroll 时，内容超宽是「可以滚动」，不是缺陷。
      // 只有 hidden / clip 才是真的把内容裁掉、用户看不到。
      const ox = getComputedStyle(el).overflowX;
      if (ox === 'auto' || ox === 'scroll') {
        r.scrollable = r.scrollable || [];
        r.scrollable.push({ cls: el.className.split(' ')[0], over: el.scrollWidth - el.clientWidth });
        return;
      }
      r.clipped.push({
        cls: el.className.split(' ')[0],
        scroll: el.scrollWidth,
        client: el.clientWidth,
        overflowX: ox,
      });
    });

    // 按钮文字是否溢出
    r.btnClipped = Array.from(document.querySelectorAll('.btn, .pager-btn, .adm-tab'))
      .filter(b => b.scrollWidth > Math.ceil(b.getBoundingClientRect().width) + 1)
      .map(b => b.textContent.trim().replace(/\\s+/g, ' ').slice(0, 12));
    return r;
  })()`;

  const rows = [];
  for (const w of WIDTHS) {
    await send('Emulation.setDeviceMetricsOverride', {
      width: w, height: 900, deviceScaleFactor: 1, mobile: w < 768,
    });
    for (const p of PAGES) {
      await send('Page.navigate', { url: SITE + p });
      await sleep(p === '/' ? 1600 : 2200);
      const r = await ev(probe);
      const issues = [];
      if (r.overflow > 2) issues.push('横向溢出+' + r.overflow);
      if (r.tabWraps.length) issues.push('Tab折行:' + r.tabWraps.join('|'));
      if (r.colMismatch.length) {
        issues.push('列数不符:' + r.colMismatch.map(c => `th${c.th}/td${c.td}`).join(','));
      }
      if (r.clipped.length) {
        issues.push('被裁:' + r.clipped.map(c => `${c.cls}(${c.scroll}>${c.client})`).join(','));
      }
      if (r.btnClipped.length) issues.push('按钮溢出:' + r.btnClipped.join('|'));
      rows.push({ w, p, issues });
      process.stdout.write(issues.length ? '\x1b[31m✗\x1b[0m' : '\x1b[32m·\x1b[0m');
    }
    process.stdout.write(' ' + w + '\n');
  }

  console.log('\n' + '═'.repeat(70));
  const bad = rows.filter(r => r.issues.length);
  if (!bad.length) {
    console.log('  全部 ' + rows.length + ' 组合均正常');
  } else {
    console.log(`  异常 ${bad.length} / ${rows.length} 组\n`);
    let lastW = null;
    for (const r of bad) {
      if (r.w !== lastW) { console.log(`\n  ── ${r.w}px ──`); lastW = r.w; }
      console.log(`    ${r.p.padEnd(18)} ${r.issues.join('  ')}`);
    }
  }
  console.log('');

  ws.close();
  process.exit(0);
})();
