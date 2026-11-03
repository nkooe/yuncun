/**
 * 文件夹管理前端实测（CDP）
 *
 * 覆盖 folder.js 的三条路径：新建、编辑、删除。
 * 重点看 curl 测不到的东西：弹窗状态、字段填充、父级下拉的禁用、confirm 后的行为。
 */
const CDP = 'http://127.0.0.1:9333';
const SITE = 'https://cun.973700.xyz';
const ADMIN_PASS = process.argv[2] || '123456';

const STAMP = Date.now().toString(36).slice(-5);
const NEW_NAME = 'UI测试_' + STAMP;
const EDIT_NAME = 'UI改_' + STAMP;

(async () => {
  const list = await (await fetch(CDP + '/json')).json();
  const target = list.find(t => t.type === 'page');

  const ws = new WebSocket(target.webSocketDebuggerUrl);
  await new Promise(r => (ws.onopen = r));

  let id = 0;
  const pending = new Map();
  const pageErrors = [];
  const consoleErrors = [];
  const dialogSeen = [];

  ws.onmessage = e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) {
      pending.get(m.id)(m);
      pending.delete(m.id);
      return;
    }
    if (m.method === 'Runtime.exceptionThrown') pageErrors.push(m.params.exceptionDetails);
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
      consoleErrors.push(m.params.args.map(a => a.value ?? a.description).join(' '));
    }
    if (m.method === 'Page.javascriptDialogOpening') {
      dialogSeen.push(m.params.message);
      send('Page.handleJavaScriptDialog', { accept: true });
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
  await send('Network.enable');

  let pass = 0, fail = 0;
  const ok = m => { pass++; console.log('  \x1b[32m✓\x1b[0m ' + m); };
  const bad = (m, want, got) => { fail++; console.log(`  \x1b[31m✗\x1b[0m ${m}\n      期望 ${want}，实际 ${got}`); };

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

  console.log('\n[2] 打开 /admin/folders');
  await send('Page.navigate', { url: SITE + '/admin/folders' });
  await sleep(2500);

  const ready = await ev(`(() => ({
    modal: !!document.getElementById('folderModal'),
    form: !!document.getElementById('folderForm'),
    newBtn: !!document.getElementById('newFolder'),
    csrf: (document.querySelector('meta[name="csrf-token"]') || {}).content || '',
    base: (document.querySelector('meta[name="base-url"]') || {}).content || '',
    rows: document.querySelectorAll('.js-edit-folder').length,
  }))()`);
  console.log('  ' + JSON.stringify(ready));
  ready.modal && ready.form ? ok('弹窗与表单就绪') : bad('弹窗与表单', '存在', '缺失');
  ready.csrf ? ok('CSRF meta 可用') : bad('CSRF meta', '非空', '空');

  // ── 3. 新建：检查弹窗状态 ─────────────────────────────
  console.log('\n[3] 点「新建文件夹」');
  await ev(`document.getElementById('newFolder').click()`);
  await sleep(400);

  const newState = await ev(`(() => {
    const m = document.getElementById('folderModal');
    const idEl = document.getElementById('fId');
    const nameEl = document.getElementById('fName');
    const parent = document.getElementById('fParent');
    const pub = document.getElementById('fPublic');
    return {
      modalOpen: m ? !m.hidden : null,
      id: idEl ? idEl.value : null,
      nameEmpty: nameEl ? nameEl.value === '' : null,
      focused: document.activeElement === nameEl,
      parent: parent ? parent.value : null,
      pub: pub ? pub.value : null,
      disabledOpts: parent ? Array.from(parent.options).filter(o => o.disabled).length : -1,
    };
  })()`);
  console.log('  ' + JSON.stringify(newState));

  newState.modalOpen ? ok('弹窗已打开') : bad('弹窗', '打开', '关闭');
  newState.id === '0' ? ok('fId 归零') : bad('fId', '0', newState.id);
  newState.nameEmpty ? ok('名称已清空') : bad('名称', '空', '有残留');
  newState.focused ? ok('名称框自动聚焦') : bad('焦点', '名称框', '其他元素');
  newState.parent === '0' ? ok('上级默认顶级') : bad('上级', '0', newState.parent);
  newState.pub === '1' ? ok('公开默认开启') : bad('公开', '1', newState.pub);
  newState.disabledOpts === 0 ? ok('新建时无选项被禁用') : bad('禁用选项', 0, newState.disabledOpts);

  // ── 4. 新建：填写并提交 ───────────────────────────────
  console.log('\n[4] 提交新建');
  await ev(`(() => {
    document.getElementById('fName').value = ${JSON.stringify(NEW_NAME)};
    document.getElementById('folderForm').submit();
    return 'submitted';
  })()`);
  await sleep(2500);

  const afterCreate = await ev(`document.body.innerText.includes(${JSON.stringify(NEW_NAME)})`);
  afterCreate ? ok('新建的文件夹出现在列表') : bad('新建结果', '列表中出现', '未出现');

  // ── 5. 编辑：检查字段填充 ─────────────────────────────
  console.log('\n[5] 点编辑');
  const clicked = await ev(`(() => {
    const btns = Array.from(document.querySelectorAll('.js-edit-folder'));
    const b = btns.find(x => x.getAttribute('data-name') === ${JSON.stringify(NEW_NAME)});
    if (!b) return 'not-found';
    b.click();
    return 'clicked';
  })()`);
  console.log('  ' + clicked);
  await sleep(400);

  if (clicked === 'clicked') {
    const editState = await ev(`(() => {
      const m = document.getElementById('folderModal');
      const idEl = document.getElementById('fId');
      const nameEl = document.getElementById('fName');
      const parent = document.getElementById('fParent');
      const selfOpt = parent ? Array.from(parent.options).find(o => o.value === idEl.value) : null;
      return {
        modalOpen: m ? !m.hidden : null,
        id: idEl ? idEl.value : null,
        name: nameEl ? nameEl.value : null,
        title: (document.getElementById('folderModalTitle') || {}).textContent || '',
        selfDisabled: selfOpt ? selfOpt.disabled : null,
      };
    })()`);
    console.log('  ' + JSON.stringify(editState));

    editState.modalOpen ? ok('编辑弹窗打开') : bad('编辑弹窗', '打开', '关闭');
    editState.name === NEW_NAME ? ok('名称已填充') : bad('名称填充', NEW_NAME, editState.name);
    editState.title.includes('编辑') ? ok('标题切到「编辑」') : bad('标题', '含「编辑」', editState.title);
    editState.selfDisabled === true ? ok('父级下拉禁用了自身') : bad('自引用禁用', 'true', editState.selfDisabled);

    // 改名提交
    await ev(`(() => {
      document.getElementById('fName').value = ${JSON.stringify(EDIT_NAME)};
      document.getElementById('folderForm').submit();
      return 'submitted';
    })()`);
    await sleep(2500);

    const renamed = await ev(`document.body.innerText.includes(${JSON.stringify(EDIT_NAME)})`);
    renamed ? ok('改名生效') : bad('改名', '列表中出现新名', '未出现');
  }

  // ── 6. 再次新建：确认禁用状态被恢复 ───────────────────
  console.log('\n[6] 再点新建（检查上次编辑的禁用是否清理）');
  await ev(`document.getElementById('newFolder').click()`);
  await sleep(400);
  const reNew = await ev(`(() => {
    const parent = document.getElementById('fParent');
    return {
      id: document.getElementById('fId').value,
      disabled: parent ? Array.from(parent.options).filter(o => o.disabled).length : -1,
    };
  })()`);
  console.log('  ' + JSON.stringify(reNew));
  reNew.id === '0' ? ok('fId 再次归零') : bad('fId', '0', reNew.id);
  reNew.disabled === 0 ? ok('禁用状态已恢复') : bad('禁用选项', 0, reNew.disabled);

  await ev(`document.querySelector('#folderModal [data-close]')?.click()`);
  await sleep(300);

  // ── 7. 删除 ───────────────────────────────────────────
  console.log('\n[7] 删除');
  const delClicked = await ev(`(() => {
    const btns = Array.from(document.querySelectorAll('.js-del-folder'));
    const b = btns.find(x => x.getAttribute('data-name') === ${JSON.stringify(EDIT_NAME)});
    if (!b) return 'not-found';
    b.click();
    return 'clicked';
  })()`);
  console.log('  点击结果: ' + delClicked);
  await sleep(2500);

  console.log('  弹出的 confirm: ' + (dialogSeen.length ? JSON.stringify(dialogSeen[0]) : '(无)'));

  const gone = await ev(`!document.body.innerText.includes(${JSON.stringify(EDIT_NAME)})`);
  gone ? ok('文件夹已从列表消失') : bad('删除结果', '列表中没有', '仍存在');

  // ── 汇总 ──────────────────────────────────────────────
  console.log('\n[8] JS 错误');
  console.log('  页面异常: ' + pageErrors.length);
  pageErrors.slice(0, 3).forEach(e => console.log('    ' + e.text));
  console.log('  console.error: ' + consoleErrors.length);
  consoleErrors.slice(0, 3).forEach(e => console.log('    ' + e));

  console.log('\n' + '─'.repeat(60));
  console.log(`  通过 ${pass}  失败 ${fail}`);
  ws.close();
  process.exit(fail > 0 ? 1 : 0);
})();
