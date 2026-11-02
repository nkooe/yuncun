const CDP = 'http://127.0.0.1:9333';
const SITE = process.env.SITE || 'http://127.0.0.1:8088';

(async () => {
  const list = await (await fetch(CDP + '/json')).json();
  const t = list.find(x => x.type === 'page');
  const ws = new WebSocket(t.webSocketDebuggerUrl);
  await new Promise(r => ws.onopen = r);
  let id = 0; const p = new Map();
  ws.onmessage = e => { const m = JSON.parse(e.data); if (m.id && p.has(m.id)) { p.get(m.id)(m); p.delete(m.id); } };
  const send = (method, params = {}) => new Promise(r => { const i = ++id; p.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
  const ev = async expr => {
    const r = await send('Runtime.evaluate', { expression: expr, returnByValue: true });
    return r.result?.result?.value;
  };

  await send('Page.enable');
  await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false });
  await send('Page.navigate', { url: SITE + '/' });
  await new Promise(r => setTimeout(r, 4500));

  console.log('viewport :', await ev('window.innerWidth + " x " + window.innerHeight'));
  console.log('css loaded:', await ev('getComputedStyle(document.querySelector(".navbar")).position'));

  const out = await ev(`(() => {
    const r = el => { if(!el) return null; const b = el.getBoundingClientRect(); return {l:Math.round(b.left), r:Math.round(b.right), w:Math.round(b.width), h:Math.round(b.height)}; };
    const btn = document.querySelector('.cell-btn');
    let btnInfo = null;
    if (btn) {
      const b = r(btn);
      const rg = document.createRange(); rg.selectNodeContents(btn);
      const tb = rg.getBoundingClientRect();
      const cs = getComputedStyle(btn);
      btnInfo = { box: b, text: {t: Math.round(tb.top), h: Math.round(tb.height), center: Math.round(tb.top + tb.height/2)}, boxCenter: Math.round(b.h/2 + (b.t||0)), display: cs.display, align: cs.alignItems, lineHeight: cs.lineHeight };
    }
    return JSON.stringify({
      nav: r(document.querySelector('.navbar')),
      inner: r(document.querySelector('.navbar-inner')),
      logo: r(document.querySelector('.nav-logo')),
      body: r(document.querySelector('.page-body')),
      h2: r(document.querySelector('.hero-text h2')),
      cell: r(document.querySelector('.cell-group')),
      btn: btnInfo
    });
  })()`);

  const j = JSON.parse(out);
  console.log('\n-- 宽度对齐 --');
  console.log('nav   ', JSON.stringify(j.nav));
  console.log('inner ', JSON.stringify(j.inner));
  console.log('logo  ', JSON.stringify(j.logo));
  console.log('body  ', JSON.stringify(j.body));
  console.log('h2    ', JSON.stringify(j.h2));
  console.log('cell  ', JSON.stringify(j.cell));
  console.log('\n-- 按钮 --');
  console.log(JSON.stringify(j.btn, null, 1));

  ws.close(); process.exit(0);
})();
