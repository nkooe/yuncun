<?php
/**
 * 500 错误页（独立渲染，不依赖布局，避免二次崩溃）
 * 变量：$msg 可选
 */
$msg = $msg ?? '服务器出了点问题，请稍后再试';
$siteName = '云盘';
try {
    if (function_exists('setting')) {
        $siteName = (string) setting('site_name', '云盘');
    }
} catch (Throwable) {
}
?><!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>服务异常 · <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
<style>
  * { box-sizing: border-box; }
  body {
    margin: 0; min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    background: #EFF2F5;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Microsoft YaHei", sans-serif;
    color: #1D2129;
    padding: 24px;
  }
  .box { max-width: 420px; text-align: center; }
  .code { font-size: 56px; font-weight: 300; color: #C9CDD4; letter-spacing: 2px; }
  .title { margin: 10px 0 8px; font-size: 17px; font-weight: 500; }
  .sub { margin: 0 0 24px; font-size: 13.5px; color: #86909C; line-height: 1.7; }
  .btn {
    display: inline-block; padding: 9px 22px;
    border-radius: 8px; background: #07C160; color: #fff;
    font-size: 13.5px; text-decoration: none;
  }
  .btn:active { background: #05A852; }
</style>
</head>
<body>
  <div class="box">
    <div class="code">500</div>
    <p class="title">服务暂时不可用</p>
    <p class="sub"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></p>
    <a class="btn" href="/">返回首页</a>
  </div>
</body>
</html>
