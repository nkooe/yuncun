/**
 * UI 全量审查截图
 * 遍历所有页面 × PC/移动双视口
 * 用法：node tools/audit.mjs
 */
import fs from 'node:fs';
import path from 'node:path';

const CDP  = process.env.CDP_PORT || '9333';
const BASE = `http://127.0.0.1:${CDP}`;
const SITE = process.env.SITE_URL || 'http://127.0.0.1:8088';
const OUT  = process.env.AUDIT_DIR || path.resolve('.shots/audit');
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || 'Admin@12345';

let ws = null, msgId = 0;
const pending = new Map();
const listeners = [];

function send(method, params = {}) {
  const id = ++msgId;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject });
    setTimeout(() => { if (pending.has(id)) { pending.delete(id); reject(new Error('timeout: ' + method)); } }, 30000);
  });
}
function waitEvent(name, timeout = 20000) {
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('event timeout: ' + name)), timeout);
    listeners.push({ name, fn: (p) => { clearTimeout(t); resolve(p); } });
  });
}

const PAGES = [
  ['01-home',        '/'],
  ['01b-categories', '/categories'],
  ['01c-folder',     '/folder/1'],
  ['02-login',       '/login'],
  ['03-register',    '/register'],
  ['04-share-code',  '/s/abc123def456'],
  ['05-404',         '/no-such-page-xyz'],
  ['06-user',        '/user'],
  ['07-profile',     '/user/profile'],
  ['08-admin',       '/admin'],
  ['09-files',       '/admin/files'],
  ['10-folders',     '/admin/folders'],
  ['11-users',       '/admin/users'],
  ['12-levels',      '/admin/levels'],
  ['13-shares',      '/admin/shares'],
  ['14-trash',       '/admin/trash'],
  ['15-logs',        '/admin/logs'],
  ['16-settings',    '/admin/settings'],
];
const PUBLIC = new Set(['01-home', '02-login', '03-register', '04-share-code', '05-404']);

async function main() {
  const targets = await (await fetch(BASE + '/json')).json();
  const page = targets.find((t) => t.type === 'page');
  if (!page) throw new Error('no page target');

  ws = new WebSocket(page.webSocketDebuggerUrl);
  await new Promise((res, rej) => { ws.onopen = res; ws.onerror = rej; });
  ws.onmessage = (ev) => {
    const msg = JSON.parse(ev.data);
    if (msg.id && pending.has(msg.id)) {
      const { resolve, reject } = pending.get(msg.id);
      pending.delete(msg.id);
      msg.error ? reject(new Error(JSON.stringify(msg.error))) : resolve(msg.result);
      return;
    }
    if (msg.method) {
      for (let i = listeners.length - 1; i >= 0; i--) {
        if (listeners[i].name === msg.method) listeners.splice(i, 1)[0].fn(msg.params);
      }
    }
  };

  await send('Page.enable');
  await send('Runtime.enable');
  fs.mkdirSync(OUT, { recursive: true });

  async function viewport(w, h, mobile) {
    await send('Emulation.setDeviceMetricsOverride', {
      width: w, height: h, deviceScaleFactor: mobile ? 2 : 1, mobile,
    });
  }

  async function goto(url, wait = 800) {
    const loaded = waitEvent('Page.loadEventFired').catch(() => null);
    await send('Page.navigate', { url });
    await loaded;
    await new Promise((r) => setTimeout(r, wait));
  }

  async function shot(name) {
    const { data } = await send('Page.captureScreenshot', { format: 'png' });
    const f = path.join(OUT, name + '.png');
    fs.writeFileSync(f, Buffer.from(data, 'base64'));
    console.log('  ', name, fs.statSync(f).size);
  }

  // ---- 检查溢出（横向滚动）----
  async function probeOverflow() {
    const r = await send('Runtime.evaluate', {
      expression: `(() => {
        const de = document.documentElement;
        const over = de.scrollWidth > de.clientWidth + 1;
        let worst = null;
        if (over) {
          const all = document.querySelectorAll('body *');
          for (const el of all) {
            const rect = el.getBoundingClientRect();
            if (rect.right > de.clientWidth + 2 && rect.width > 0) {
              const style = getComputedStyle(el);
              if (style.position === 'fixed') continue;
              worst = { tag: el.tagName, cls: (el.className || '').toString().slice(0, 60), right: Math.round(rect.right), w: Math.round(rect.width) };
              break;
            }
          }
        }
        return JSON.stringify({ sw: de.scrollWidth, cw: de.clientWidth, over, worst });
      })()`,
      returnByValue: true,
    }).catch(() => null);
    try { return JSON.parse(r.result.value); } catch { return null; }
  }

  async function login() {
    await goto(SITE + '/login', 400);
    const has = await send('Runtime.evaluate', {
      expression: "!!document.querySelector('form.auth-card')", returnByValue: true,
    }).then((r) => r.result.value).catch(() => false);
    if (!has) return;
    await send('Runtime.evaluate', {
      expression: `
        document.getElementById('username').value = ${JSON.stringify(USER)};
        document.getElementById('password').value = ${JSON.stringify(PASS)};
        document.querySelector('form.auth-card').submit();
      `,
    });
    await waitEvent('Page.loadEventFired').catch(() => null);
    await new Promise((r) => setTimeout(r, 1200));
  }

  const issues = [];
  const MODE = process.env.AUDIT_MODE || 'all'; // all | pc | mobile

  // ============ PC 1440 ============
  if (MODE !== 'mobile') {
  console.log('\n=== PC 1440 ===');
  await viewport(1440, 900, false);

  for (const [name, url] of PAGES) {
    if (!PUBLIC.has(name)) continue;
    await goto(SITE + url);
    const ov = await probeOverflow();
    if (ov && ov.over) issues.push({ mode: 'PC', page: name, ...ov });
    await shot('pc-' + name);
  }

  await login();
  for (const [name, url] of PAGES) {
    if (PUBLIC.has(name)) continue;
    await goto(SITE + url);
    const ov = await probeOverflow();
    if (ov && ov.over) issues.push({ mode: 'PC', page: name, ...ov });
    await shot('pc-' + name);
  }
  }

  // ============ 移动 390 ============
  if (MODE !== 'pc') {
  console.log('\n=== Mobile 390 ===');
  await viewport(390, 844, true);
  if (MODE === 'mobile') await login();

  for (const [name, url] of PAGES) {
    await goto(SITE + url);
    const ov = await probeOverflow();
    if (ov && ov.over) issues.push({ mode: 'M', page: name, ...ov });
    await shot('mb-' + name);
  }
  }

  console.log('\n=== 横向溢出问题 ===');
  if (!issues.length) {
    console.log('  无');
  } else {
    for (const i of issues) {
      console.log(`  [${i.mode}] ${i.page}  scrollWidth=${i.sw} clientWidth=${i.cw}  ${i.worst ? i.worst.tag + '.' + i.worst.cls + ' right=' + i.worst.right + ' w=' + i.worst.w : ''}`);
    }
  }

  ws.close();
  process.exit(0);
}

main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
