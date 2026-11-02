/**
 * CDP 截图脚本
 * 用法：node tools/shot.mjs
 * 环境变量：CDP_PORT SITE_URL SHOT_DIR ADMIN_USER ADMIN_PASS
 */
import fs from 'node:fs';
import path from 'node:path';

const CDP  = process.env.CDP_PORT || '9333';
const BASE = `http://127.0.0.1:${CDP}`;
const SITE = process.env.SITE_URL || 'http://127.0.0.1:8088';
const OUT  = process.env.SHOT_DIR || path.resolve('.shots');
const USER = process.env.ADMIN_USER || 'admin';
const PASS = process.env.ADMIN_PASS || 'Admin@12345';
const PAGES = (process.env.SHOT_PAGES || 'm-front,m-login,m-admin,m-files,m-folders,m-settings,m-user,m-front-logged').split(',');

let ws = null;
let msgId = 0;
const pending = new Map();
const listeners = [];

function send(method, params = {}) {
  const id = ++msgId;
  ws.send(JSON.stringify({ id, method, params }));
  return new Promise((resolve, reject) => {
    pending.set(id, { resolve, reject });
    setTimeout(() => {
      if (pending.has(id)) { pending.delete(id); reject(new Error('timeout: ' + method)); }
    }, 30000);
  });
}

function waitEvent(name, timeout = 20000) {
  return new Promise((resolve, reject) => {
    const t = setTimeout(() => reject(new Error('event timeout: ' + name)), timeout);
    listeners.push({ name, fn: (p) => { clearTimeout(t); resolve(p); } });
  });
}

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
        if (listeners[i].name === msg.method) {
          const { fn } = listeners.splice(i, 1)[0];
          fn(msg.params);
        }
      }
    }
  };

  await send('Page.enable');
  await send('Runtime.enable');
  const VW = Number(process.env.SHOT_W || 390);
  const VH = Number(process.env.SHOT_H || 844);
  await send('Emulation.setDeviceMetricsOverride', {
    width: VW,
    height: VH,
    deviceScaleFactor: 2,
    mobile: process.env.SHOT_MOBILE !== '0',
  });

  async function goto(url, wait = 900) {
    const loaded = waitEvent('Page.loadEventFired').catch(() => null);
    await send('Page.navigate', { url });
    await loaded;
    await new Promise((r) => setTimeout(r, wait));
  }

  async function shot(name, h) {
    const args = { format: 'png' };
    if (h) {
      args.clip = { x: 0, y: 0, width: VW, height: h, scale: 1 };
      args.captureBeyondViewport = true;
    }
    const { data } = await send('Page.captureScreenshot', args);
    const file = path.join(OUT, name + '.png');
    fs.writeFileSync(file, Buffer.from(data, 'base64'));
    console.log('shot:', name, fs.statSync(file).size, 'bytes');
  }

  fs.mkdirSync(OUT, { recursive: true });
  const want = (n) => PAGES.includes(n);

  if (want('m-front')) { await goto(SITE + '/'); await shot('m-front'); }
  if (want('m-login')) { await goto(SITE + '/login'); await shot('m-login'); }

  // 登录（先确保停留在登录页；已登录时 /login 会跳转，跳过填写）
  await goto(SITE + '/login', 500);
  const hasForm = await send('Runtime.evaluate', {
    expression: "!!document.querySelector('form.auth-card')",
    returnByValue: true,
  }).then((r) => r.result && r.result.value).catch(() => false);

  if (hasForm) {
    await send('Runtime.evaluate', {
      expression: `
        document.getElementById('username').value = ${JSON.stringify(USER)};
        document.getElementById('password').value = ${JSON.stringify(PASS)};
        document.querySelector('form.auth-card').submit();
      `,
    });
    await waitEvent('Page.loadEventFired').catch(() => null);
    await new Promise((r) => setTimeout(r, 1400));
  }

  if (want('m-admin'))   { await goto(SITE + '/admin'); await shot('m-admin'); }
  if (want('m-files'))   { await goto(SITE + '/admin/files'); await shot('m-files', 900); }
  if (want('m-folders')) { await goto(SITE + '/admin/folders'); await shot('m-folders'); }
  if (want('m-settings')){ await goto(SITE + '/admin/settings'); await shot('m-settings'); }
  if (want('m-user'))    { await goto(SITE + '/user'); await shot('m-user'); }
  if (want('m-profile')) { await goto(SITE + '/user/profile'); await shot('m-profile'); }
  if (want('m-front-logged')) { await goto(SITE + '/'); await shot('m-front-logged'); }

  console.log('done');
  ws.close();
  process.exit(0);
}

main().catch((e) => { console.error('FAILED:', e.message); process.exit(1); });
