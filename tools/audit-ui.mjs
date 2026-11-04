import fs from 'node:fs';

/**
 * 全站可交互元素审计
 *
 * 动机：appearance: none 这类全局样式重置可能让控件「占着位置但看不见」。
 * 之前修过一次复选框消失，这类问题不该靠肉眼发现。
 *
 * 用法：node tools/audit-ui.mjs
 * 前置：Chrome 以 --remote-debugging-port=9333 启动，线上 admin 账号可用
 *
 * 判定「应该可见」时只看可见区域内的元素：祖先链上有 display:none /
 * visibility:hidden / hidden 属性的一律跳过，否则弹窗里的控件会被大量误报。
 */
const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const PASS = '123456';

const PAGES = [
  '/', '/login', '/register', '/categories', '/search',
  '/user', '/user/downloads', '/user/logins', '/user/profile',
  '/admin', '/admin/files', '/admin/folders', '/admin/users',
  '/admin/levels', '/admin/trash', '/admin/logs', '/admin/shares',
  '/admin/settings',
];

const list = await (await fetch(CDP + '/json')).json();
const t = list.find(x => x.type === 'page');
const ws = new WebSocket(t.webSocketDebuggerUrl);
await new Promise(r => (ws.onopen = r));

let id = 0;
const pend = new Map();
ws.onmessage = e => {
  const m = JSON.parse(e.data);
  if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); }
};
const send = (method, params = {}) => new Promise(r => {
  const i = ++id; pend.set(i, r);
  ws.send(JSON.stringify({ id: i, method, params }));
});
const ev = async x => {
  const r = await send('Runtime.evaluate', { expression: x, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) return { __exc: r.result.exceptionDetails.text };
  return r.result?.result?.value;
};
const sleep = ms => new Promise(r => setTimeout(r, ms));

await send('Page.enable');
await send('Runtime.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1200, deviceScaleFactor: 1, mobile: false });

await send('Page.navigate', { url: SITE + '/logout' });
await sleep(1000);
await send('Page.navigate', { url: SITE + '/login' });
await sleep(2200);
await ev(`(()=>{const a=document.querySelector('input[name="_token"]');if(!a)return 0;
  document.querySelector('input[name="username"]').value='admin';
  document.querySelector('input[name="password"]').value=${JSON.stringify(PASS)};
  document.querySelector('form').submit();return 1;})()`);
await sleep(2800);

const probe = `(() => {
  const issues = [];
  let checked = 0;

  function reallyVisible(el) {
    if (el.hasAttribute('hidden')) return false;
    let n = el;
    while (n && n !== document.documentElement) {
      const cs = getComputedStyle(n);
      if (cs.display === 'none' || cs.visibility === 'hidden') return false;
      if (n.hasAttribute && n.hasAttribute('hidden')) return false;
      n = n.parentElement;
    }
    if (el.type === 'hidden') return false;
    if (el.tagName === 'INPUT' && el.type === 'file' && el.offsetParent === null) return false;
    return true;
  }

  function note(kind, el, extra) {
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    issues.push({
      kind, tag: el.tagName.toLowerCase(), type: el.type || '',
      name: el.name || el.id || '', cls: (el.className || '').toString().slice(0, 36),
      text: (el.textContent || '').trim().replace(/\\s+/g, ' ').slice(0, 26),
      w: Math.round(r.width), h: Math.round(r.height),
      appearance: cs.appearance || cs.webkitAppearance, ...extra
    });
  }

  document.querySelectorAll('input, select, textarea').forEach(el => {
    if (!reallyVisible(el)) return;
    checked++;
    const cs = getComputedStyle(el);
    const r = el.getBoundingClientRect();
    const type = (el.type || '').toLowerCase();
    // 开关的隐藏 input：opacity 0 且尺寸为 0，是有意为之
    if (cs.opacity === '0' && r.width <= 1 && r.height <= 1) return;
    if (r.width <= 1 || r.height <= 1) { note('尺寸为 0', el); return; }
    if (type === 'checkbox' || type === 'radio') {
      const ap = (cs.appearance || cs.webkitAppearance || '').toLowerCase();
      if (ap === 'none') {
        const custom = !!el.closest('.switch-row')
          || getComputedStyle(el, '::before').content !== 'none'
          || getComputedStyle(el, '::after').content !== 'none';
        if (!custom) note('复选框无外观', el, { appearance: ap });
      }
    }
  });

  document.querySelectorAll('button, input[type="submit"]').forEach(el => {
    if (!reallyVisible(el)) return;
    const r = el.getBoundingClientRect();
    const cs = getComputedStyle(el);
    if (r.width <= 1 || r.height <= 1) note('按钮尺寸为 0', el);
    else if (parseFloat(cs.opacity) < 0.1) note('按钮几乎透明', el);
  });

  document.querySelectorAll('a[href]').forEach(el => {
    if (!reallyVisible(el)) return;
    const r = el.getBoundingClientRect();
    if (r.width <= 1 || r.height <= 1) note('链接尺寸为 0', el, { href: el.getAttribute('href') });
  });

  document.querySelectorAll('img').forEach(el => {
    if (!reallyVisible(el)) return;
    if (!el.complete || el.naturalWidth === 0) note('图片未加载', el);
  });

  document.querySelectorAll('.adm-table').forEach((tbl, ti) => {
    const ths = tbl.querySelectorAll('thead tr:last-child th');
    const tr = tbl.querySelector('tbody tr');
    if (!ths.length || !tr) return;
    const visTh = Array.from(ths).filter(x => getComputedStyle(x).display !== 'none').length;
    const visTd = Array.from(tr.children).filter(x => getComputedStyle(x).display !== 'none').length;
    if (visTh !== visTd) issues.push({ kind: '表头与数据列数不符', table: ti, th: visTh, td: visTd });
  });

  const ov = document.documentElement.scrollWidth - window.innerWidth;
  if (ov > 2) issues.push({ kind: '横向溢出', px: ov });

  return { issues, checked };
})()`;

const report = [];
for (const p of PAGES) {
  await send('Page.navigate', { url: SITE + p });
  await sleep(p.startsWith('/admin') ? 2000 : 1600);
  const r = await ev(probe);
  if (r && r.__exc) { report.push({ page: p, error: r.__exc }); continue; }
  report.push({ page: p, issues: r.issues, checked: r.checked });
}

console.log('\n' + '='.repeat(74));
let total = 0;
for (const r of report) {
  if (r.error) { console.log(`\n${r.page}\n  x 探针异常: ${r.error}`); continue; }
  total += r.issues.length;
  if (!r.issues.length) { console.log(`\n${r.page}  ok  [${r.checked} 控件]`); continue; }
  console.log(`\n${r.page}  [${r.checked} 控件]`);
  r.issues.forEach(i => {
    if (i.kind === '表头与数据列数不符') console.log(`  x ${i.kind}: th=${i.th} td=${i.td}`);
    else if (i.kind === '横向溢出') console.log(`  x ${i.kind}: ${i.px}px`);
    else console.log(`  x ${i.kind}: <${i.tag}${i.type ? ' type=' + i.type : ''}${i.name ? ' name=' + i.name : ''}> ${i.w}x${i.h} ap=${i.appearance} "${i.text}"`);
  });
}
console.log('\n' + '='.repeat(74));
console.log(`合计 ${total} 处，覆盖 ${report.length} 个页面`);
ws.close();
process.exit(0);