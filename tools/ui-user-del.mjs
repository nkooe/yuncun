/**
 * 删除用户前端实测（CDP）
 *
 * 验证：删除按钮存在性、confirm 文案、AJAX 调用、列表刷新、自己不能被删。
 */
const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const ADMIN_PASS = process.argv[2] || '123456';
const TARGET_USER = process.argv[3] || '';

(async () => {
  const list = await (await fetch(CDP + '/json')).json();
  const target = list.find(t => t.type === 'page');
  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(r => (ws.onopen = r));

  let id = 0;
  const pending = new Map();
  const dialogs = [];
  const reqs = [];
  const pageErrors = [];

  ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); return; }
    if (m.method === 'Page.javascriptDialogOpening') {
      dialogs.push(m.params.message);
      send('Page.handleJavaScriptDialog', { accept: true });
    }
    if (m.method === 'Network.requestWillBeSent' && m.params.request.url.includes('/admin/users/')) {
      reqs.push({ url: m.params.request.url, method: m.params.request.method });
    }
    if (m.method === 'Runtime.exceptionThrown') pageErrors.push(m.params.exceptionDetails);
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
  await send('Network.enable');

  // 登录（带重试：慢网络下表单可能还没渲染完）
  console.log('\n[1] 登录');
  // 先登出：浏览器可能残留着别的账号，已登录状态下访问 /login 会被重定向回首页
  await send('Page.navigate', { url: SITE + '/logout' });
  await sleep(1500);

  let loggedIn = false;
  for (let attempt = 1; attempt <= 3 && !loggedIn; attempt++) {
    await send('Page.navigate', { url: SITE + '/login' });
    await sleep(2500);
    const submitted = await ev(`(() => {
      const t = document.querySelector('input[name="_token"]');
      const u = document.querySelector('input[name="username"]');
      const p = document.querySelector('input[name="password"]');
      if (!t || !u || !p) return 'not-ready';
      u.value = 'admin'; p.value = ${JSON.stringify(ADMIN_PASS)}; u.form.submit();
      return 'ok';
    })()`);
    if (submitted !== 'ok') { console.log('  第' + attempt + '次表单未就绪，重试'); continue; }
    await sleep(3000);

    // 确认真的登录了：能拿到用户管理页的行
    await send('Page.navigate', { url: SITE + '/admin/users' });
    await sleep(2500);
    const n = await ev(`document.querySelectorAll('tr[data-id]').length`);
    if (n > 0) { loggedIn = true; console.log('  登录成功，用户行数 ' + n); }
    else console.log('  第' + attempt + '次未见用户行，重试');
  }
  if (!loggedIn) { console.log('  登录失败，终止'); process.exit(1); }

  // 打开用户管理
  console.log('\n[2] /admin/users');
  await send('Page.navigate', { url: SITE + '/admin/users' });
  await sleep(2500);

  const stat = await ev(`(() => {
    const rows = document.querySelectorAll('tr[data-id]');
    const delBtns = document.querySelectorAll('.js-del-user');
    const editBtns = document.querySelectorAll('.js-edit-user');
    let adminHasDel = false;
    rows.forEach(r => {
      const name = (r.querySelector('[data-label="用户"]') || {}).innerText || '';
      if (/admin/.test(name) && r.querySelector('.js-del-user')) adminHasDel = true;
    });
    return { rows: rows.length, del: delBtns.length, edit: editBtns.length, adminHasDel };
  })()`);
  console.log('  ' + JSON.stringify(stat));

  let pass = 0, fail = 0;
  const ok = m => { pass++; console.log('  \x1b[32m✓\x1b[0m ' + m); };
  const bad = (m, want, got) => { fail++; console.log(`  \x1b[31m✗\x1b[0m ${m}\n      期望 ${want}，实际 ${got}`); };

  stat.del > 0 ? ok(`删除按钮出现 ${stat.del} 个`) : bad('删除按钮', '>0', 0);
  stat.del === stat.rows - 1 ? ok('admin 自己那行没有删除按钮') : bad('按钮数', `行数-1（${stat.rows - 1}）`, stat.del);
  stat.adminHasDel ? bad('admin 行不应有删除按钮', '无', '有') : ok('admin 行确实没有');

  // 使用外部创建好的测试用户
  const uname = TARGET_USER;
  if (!uname) { console.log('  未提供目标用户'); process.exit(1); }
  console.log('  目标用户: ' + uname);

  // 刷新用户列表
  await send('Page.navigate', { url: SITE + '/admin/users?q=' + uname });
  await sleep(2500);

  const found = await ev(`(() => {
    const rows = document.querySelectorAll('tr[data-id]');
    for (const r of rows) {
      if (r.innerText.includes(${JSON.stringify(uname)})) {
        const b = r.querySelector('.js-del-user');
        return b ? { found: true, id: b.getAttribute('data-id'), name: b.getAttribute('data-name') } : { found: true, noBtn: true };
      }
    }
    return { found: false };
  })()`);
  console.log('  ' + JSON.stringify(found));

  if (found.found && found.id) {
    ok('测试用户出现在列表且有删除按钮');

    console.log('\n[4] 点击删除（confirm 自动接受）');
    await ev(`(() => {
      const rows = document.querySelectorAll('tr[data-id]');
      for (const r of rows) {
        if (r.innerText.includes(${JSON.stringify(uname)})) {
          r.querySelector('.js-del-user').click();
          return 'clicked';
        }
      }
      return 'not-found';
    })()`);
    await sleep(3000);

    console.log('  confirm 内容: ' + (dialogs.length ? JSON.stringify(dialogs[0]) : '(无)'));
    dialogs.length ? ok('弹出了确认框') : bad('confirm', '弹出', '未弹出');
    if (dialogs.length) {
      /永久移除/.test(dialogs[0]) ? ok('文案说明了不可恢复') : bad('文案', '含「永久移除」', dialogs[0]);
      /转给当前管理员/.test(dialogs[0]) ? ok('文案说明了文件归属转移') : bad('文案', '含「转给当前管理员」', dialogs[0]);
    }

    const after = await ev(`!document.body.innerText.includes(${JSON.stringify(uname)})`);
    after ? ok('删除后列表中消失') : bad('删除结果', '消失', '仍存在');

    console.log('\n  网络请求: ' + JSON.stringify(reqs.filter(r => r.url.includes('/delete'))));
  } else {
    bad('测试用户', '出现在列表', JSON.stringify(found));
  }

  console.log('\n[5] JS 错误: ' + pageErrors.length);
  pageErrors.slice(0, 3).forEach(e => console.log('    ' + e.text));

  console.log('\n' + '─'.repeat(56));
  console.log(`  通过 ${pass}  失败 ${fail}`);
  ws.close();
  process.exit(fail > 0 ? 1 : 0);
})();
