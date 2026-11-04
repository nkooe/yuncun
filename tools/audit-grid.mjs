/**
 * 视觉对齐网格检测（CDP）
 *
 * 动机：上一轮发现复选框列偏了 4px，成因是两处 padding 不一致
 * （12px vs 16px）。这类问题肉眼能感觉「没对齐」但说不清在哪，
 * 靠猜方向找不到。
 *
 * 做法：把每个页面里「主内容块」的左边缘 x 收集起来，看它们是否
 * 收敛到少量几个值。同一列内容出现互不相同的 x，就是 padding
 * 或 margin 没统一。
 *
 * 判定：同一页面内，主内容块的左边缘种类数应 <= 2（容器内缩一级 +
 * 卡片内再缩一级）。超过就报出来。
 */
import fs from 'node:fs';

const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const PASS = '123456';

const PAGES = [
  '/', '/categories', '/search?q=a', '/user', '/user/downloads', '/user/logins',
  '/admin', '/admin/files', '/admin/folders', '/admin/users',
  '/admin/levels', '/admin/trash', '/admin/logs', '/admin/shares', '/admin/settings',
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
await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });

// 登录
await send('Page.navigate', { url: SITE + '/logout' });
await sleep(900);
await send('Page.navigate', { url: SITE + '/login' });
await sleep(2100);
await ev(`(()=>{const a=document.querySelector('input[name="_token"]');if(!a)return 0;
  document.querySelector('input[name="username"]').value='admin';
  document.querySelector('input[name="password"]').value=${JSON.stringify(PASS)};
  document.querySelector('form').submit();return 1;})()`);
await sleep(2700);

const probe = `(() => {
  function visible(el) {
    if (!el || el.hasAttribute('hidden')) return false;
    let n = el;
    while (n && n !== document.documentElement) {
      const cs = getComputedStyle(n);
      if (cs.display === 'none' || cs.visibility === 'hidden') return false;
      n = n.parentElement;
    }
    const r = el.getBoundingClientRect();
    return r.width > 2 && r.height > 2;
  }

  // 主内容块：面板、工具条、表格容器、卡片、区块
  const SEL = [
    '.adm-panel', '.adm-toolbar', '.adm-table-bar', '.table-wrap',
    '.panel', '.card', '.section', '.section-head', '.cell-group',
    '.up-zone', '.resume-box', '.adm-empty', '.adm-filter',
    '.quick-grid', '.pc-nav', '.folder-scroll', '.list', '.item'
  ].join(',');

  const buckets = {};
  document.querySelectorAll(SEL).forEach(el => {
    if (!visible(el)) return;
    const x = Math.round(el.getBoundingClientRect().x);
    const key = el.className.toString().split(' ')[0] || el.tagName.toLowerCase();
    (buckets[x] = buckets[x] || []).push(key);
  });

  const xs = Object.keys(buckets).map(Number).sort((a, b) => a - b);
  // 只保留出现次数 >= 2 的 x，孤立值多半是浮层或侧栏
  const main = xs.filter(x => buckets[x].length >= 2);

  return {
    allXs: xs,
    mainXs: main,
    detail: main.map(x => ({ x, n: buckets[x].length, sample: [...new Set(buckets[x])].slice(0, 4) })),
    kinds: main.length
  };
})()`;

const report = [];
for (const p of PAGES) {
  await send('Page.navigate', { url: SITE + p });
  await sleep(p.startsWith('/admin') ? 2000 : 1700);
  const r = await ev(probe);
  if (r && r.__exc) { report.push({ page: p, error: r.__exc }); continue; }
  report.push({ page: p, ...r });
}

console.log('\n' + '='.repeat(76));
let bad = 0;
for (const r of report) {
  if (r.error) { console.log(`\n${r.page}\n  x 探针异常: ${r.error}`); continue; }
  const over = r.kinds > 2;
  if (over) bad++;
  console.log(`\n${r.page}   左边缘种类 ${r.kinds} 种${over ? '  ← 偏多' : ''}`);
  r.detail.forEach(d => {
    console.log(`    x=${String(d.x).padStart(4)}  ${String(d.n).padStart(2)} 个块   ${d.sample.join(', ')}`);
  });
  const others = r.allXs.filter(x => !r.mainXs.includes(x));
  if (others.length) console.log(`    （另有孤立值 ${others.join(', ')}）`);
}
console.log('\n' + '='.repeat(76));
console.log(`左边缘种类 > 2 的页面: ${bad} 个`);

ws.close();
process.exit(0);
