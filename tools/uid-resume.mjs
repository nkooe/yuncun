/**
 * 验证「未完成的上传」区域能否正确渲染（CDP）
 *
 * 要点：localStorage 是浏览器隔离的，CDP 用的是独立 profile，
 * 拿不到用户自己浏览器里的记录，所以这里手动注入来还原现场。
 */
import fs from 'node:fs';

const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';

const list = await (await fetch(CDP + '/json')).json();
const t = list.find(x => x.type === 'page');
const ws = new WebSocket(t.webSocketDebuggerUrl);
await new Promise(r => (ws.onopen = r));

let id = 0;
const pend = new Map();
const errs = [];
ws.onmessage = e => {
  const m = JSON.parse(e.data);
  if (m.id && pend.has(m.id)) { pend.get(m.id)(m); pend.delete(m.id); return; }
  if (m.method === 'Runtime.exceptionThrown') errs.push(m.params.exceptionDetails.text);
};
const send = (method, params = {}) => new Promise(r => {
  const i = ++id; pend.set(i, r);
  ws.send(JSON.stringify({ id: i, method, params }));
});
const ev = async x => {
  const r = await send('Runtime.evaluate', { expression: x, returnByValue: true, awaitPromise: true });
  return r.result?.result?.value;
};
const sleep = ms => new Promise(r => setTimeout(r, ms));

await send('Page.enable');
await send('Runtime.enable');
await send('Emulation.setDeviceMetricsOverride', { width: 1400, height: 900, deviceScaleFactor: 1, mobile: false });

// 登录
await send('Page.navigate', { url: SITE + '/logout' });
await sleep(1000);
await send('Page.navigate', { url: SITE + '/login' });
await sleep(2200);
await ev(`(()=>{const a=document.querySelector('input[name="_token"]');const u=document.querySelector('input[name="username"]');const p=document.querySelector('input[name="password"]');if(!a||!u||!p)return 0;u.value='admin';p.value='123456';u.form.submit();return 1;})()`);
await sleep(2800);

// 先拿服务端的 pending 列表
const pending = await ev(`(async () => {
  const r = await fetch('/api/upload/pending', {
    method: 'POST',
    headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]').content, 'X-Requested-With': 'XMLHttpRequest' }
  });
  return await r.json();
})()`);

console.log('\n服务端 pending:');
(pending.items || []).forEach(it => {
  console.log(`  ${it.name}  ${it.received}/${it.chunks} 片 (${it.upload_id.slice(0, 10)}…)`);
});

if (!pending.items || !pending.items.length) {
  console.log('  （没有待续传任务，无法验证渲染）');
  ws.close();
  process.exit(0);
}

// 注入 localStorage 记录（模拟用户切走前留下的）
const injected = await ev(`(() => {
  const items = ${JSON.stringify(pending.items)};
  const arr = items.map(it => ({
    upload_id: it.upload_id,
    name: it.name,
    size: it.size,
    folder_id: it.folder_id,
    at: Date.now()
  }));
  localStorage.setItem('wxpan_uploads', JSON.stringify(arr));
  return arr.length;
})()`);
console.log(`\n已注入 ${injected} 条本地记录`);

// 重新加载资源页
await send('Page.navigate', { url: SITE + '/admin/files' });
await sleep(2600);

const result = await ev(`(() => {
  const box = document.getElementById('resumeBox');
  if (!box) return { found: false };
  const items = box.querySelectorAll('.resume-item');
  return {
    found: true,
    hidden: box.hidden,
    count: items.length,
    title: (box.querySelector('.resume-title') || {}).textContent || '',
    first: items.length ? {
      name: (items[0].querySelector('b') || {}).textContent || '',
      meta: (items[0].querySelector('small') || {}).textContent || '',
      btn: (items[0].querySelector('.js-resume') || {}).textContent || ''
    } : null,
    boxHeight: Math.round(box.getBoundingClientRect().height)
  };
})()`);

console.log('\n页面渲染结果:');
if (!result.found) {
  console.log('  ✗ 页面上没有 #resumeBox 元素');
} else {
  console.log(`  resumeBox 存在: ${result.hidden ? '✗ 仍隐藏' : '✓ 已显示'}`);
  console.log(`  条目数: ${result.count}`);
  console.log(`  标题: ${result.title}`);
  if (result.first) {
    console.log(`  第一条: ${result.first.name}`);
    console.log(`           ${result.first.meta}`);
    console.log(`           按钮: ${result.first.btn}`);
  }
  console.log(`  区域高度: ${result.boxHeight}px`);
}
console.log(`  JS 异常: ${errs.length}`);

// 截图
const shot = await send('Page.captureScreenshot', { format: 'png' });
const out = process.env.TEMP + '\\resume-check.png';
fs.writeFileSync(out, Buffer.from(shot.result.data, 'base64'));
console.log(`\n截图: ${out}`);

ws.close();
process.exit(0);
