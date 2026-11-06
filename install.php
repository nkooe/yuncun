<?php
/**
 * 安装向导：四步完成，安装后自锁。
 * 本文件独立运行，不依赖 app/bootstrap.php（安装完成前配置尚不存在）。
 */
declare(strict_types=1);

@ini_set('display_errors', '1');
error_reporting(E_ALL);
session_start();

define('INST_ROOT', __DIR__);
define('INST_VERSION', '1.1.49');

require_once __DIR__ . '/app/Schema.php';

$lockFile   = __DIR__ . '/data/install.lock';
$configFile = __DIR__ . '/data/config.php';

// ---------- 已安装则拒绝 ----------
if (is_file($lockFile)) {
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><title>已安装</title>'
       . '<div style="font-family:-apple-system,\'PingFang SC\',sans-serif;text-align:center;padding:120px 24px">'
       . '<h2 style="color:#07C160">系统已安装</h2>'
       . '<p style="color:#888">如需重新安装，请删除 <code>data/install.lock</code> 后再访问本页。</p>'
       . '<p><a href="./" style="color:#07C160;text-decoration:none">进入首页 →</a></p></div>';
    exit;
}

// ---------- 环境检测 ----------
function env_checks(): array
{
    $checks = [];

    $phpOk = version_compare(PHP_VERSION, '8.0.0', '>=');
    $checks[] = [
        'name'  => 'PHP 版本 ≥ 8.0',
        'value' => PHP_VERSION,
        'ok'    => $phpOk,
        'hard'  => true,
        'hint'  => $phpOk ? '' : '当前版本过低，请升级 PHP 后重试',
    ];

    $exts = [
        'pdo_mysql' => [true,  '数据库连接（必需）'],
        'mbstring'  => [true,  '多字节字符串处理（必需）'],
        'json'      => [true,  'JSON 序列化（必需）'],
        'gd'        => [false, '图片缩略图（可选）'],
        'zip'       => [false, '压缩包处理（可选）'],
        'fileinfo'  => [false, '上传文件类型检测（推荐）'],
        'openssl'   => [false, '签名与加密（推荐）'],
    ];
    foreach ($exts as $ext => [$hard, $desc]) {
        $ok = extension_loaded($ext);
        $checks[] = [
            'name'  => "扩展 {$ext}",
            'value' => $ok ? '已加载' : '未加载',
            'ok'    => $ok || !$hard,
            'warn'  => !$ok && !$hard,
            'hard'  => $hard,
            'hint'  => $desc,
        ];
    }

    $dirs = ['data' => '配置与锁文件', 'runtime' => '缓存与临时分片', 'storage' => '文件存储'];
    foreach ($dirs as $dir => $desc) {
        $path = __DIR__ . '/' . $dir;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
        $ok = is_dir($path) && is_writable($path);
        $checks[] = [
            'name'  => "目录 {$dir}/ 可写",
            'value' => $ok ? '可写' : '不可写',
            'ok'    => $ok,
            'hard'  => true,
            'hint'  => $desc,
        ];
    }

    $uploadMax = ini_get('upload_max_filesize');
    $postMax   = ini_get('post_max_size');
    $checks[] = [
        'name'  => '单文件上传上限',
        'value' => $uploadMax . ' / POST ' . $postMax,
        'ok'    => true,
        'warn'  => (int) $uploadMax < 32,
        'hard'  => false,
        'hint'  => '本系统使用分片上传，可绕过此限制；建议调至 64M 以上作为后备',
    ];

    $checks[] = [
        'name'  => '已安装 PHP 扩展',
        'value' => (extension_loaded('pdo_sqlite') ? 'sqlite ' : '') . (extension_loaded('redis') ? 'redis' : ''),
        'ok'    => true,
        'hard'  => false,
        'hint'  => '非必需',
    ];

    return $checks;
}

function install_state(string $key, mixed $default = null): mixed
{
    return $_SESSION['_install'][$key] ?? $default;
}

function install_put(string $key, mixed $value): void
{
    $_SESSION['_install'][$key] = $value;
}

/** 探测站点子目录，例如 /pan */
function detect_base_path(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '/install.php';
    $dir = str_replace('\\', '/', dirname($script));
    return ($dir === '/' || $dir === '.' || $dir === '') ? '' : rtrim($dir, '/');
}

// ---------- 处理提交 ----------
$errors  = [];
$notice  = '';
$step    = (int) ($_POST['step'] ?? $_GET['step'] ?? 1);
$step    = max(1, min(5, $step));
$doInstall = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // 第 1 步：环境确认（无输入，仅复核后放行）
    if ($step === 1) {
        $pre = env_checks();
        $blocked = false;
        foreach ($pre as $c) {
            if (($c['hard'] ?? false) && !$c['ok']) {
                $blocked = true;
            }
        }
        if ($blocked) {
            $errors[] = '必需环境条件未满足，请修复后重试';
            $step = 1;
        } else {
            $_SESSION['_install_step'] = 2;
            header('Location: install.php?step=2');
            exit;
        }
    }

    // 第 2 步：数据库
    if ($step === 2) {
        $db = [
            'host'   => trim((string) ($_POST['db_host'] ?? '127.0.0.1')),
            'port'   => (int) ($_POST['db_port'] ?? 3306),
            'name'   => trim((string) ($_POST['db_name'] ?? '')),
            'user'   => trim((string) ($_POST['db_user'] ?? '')),
            'pass'   => (string) ($_POST['db_pass'] ?? ''),
            'prefix' => trim((string) ($_POST['db_prefix'] ?? 'wx_')),
        ];

        if ($db['host'] === '') {
            $errors[] = '数据库地址不能为空';
        }
        if ($db['port'] < 1 || $db['port'] > 65535) {
            $errors[] = '端口号不合法';
        }
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db['name'])) {
            $errors[] = '数据库名只能包含字母、数字和下划线';
        }
        if ($db['user'] === '') {
            $errors[] = '数据库用户名不能为空';
        }
        if (!preg_match('/^[A-Za-z0-9_]{0,16}$/', $db['prefix'])) {
            $errors[] = '表前缀只能包含字母、数字和下划线（可不填）';
        }
        // 公网部署安全底线：root + 空口令 直接拒绝
        if (strtolower($db['user']) === 'root' && $db['pass'] === '' && !in_array($db['host'], ['127.0.0.1', 'localhost', '::1'], true)) {
            $errors[] = '检测到 root 空口令连接远程数据库，存在严重安全风险，已拒绝。请为数据库设置强口令，或改用专用账号。';
        }

        if (!$errors) {
            try {
                $pdo = new PDO(
                    "mysql:host={$db['host']};port={$db['port']};charset=utf8mb4",
                    $db['user'],
                    $db['pass'],
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
                );
                $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();

                // 库不存在则创建
                $exists = $pdo->query('SHOW DATABASES LIKE ' . $pdo->quote($db['name']))->fetch();
                if (!$exists) {
                    $pdo->exec("CREATE DATABASE `{$db['name']}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci");
                }

                install_put('db', $db);
                install_put('db_version', $ver);
                $_SESSION['_install_step'] = 3;
                header('Location: install.php?step=3');
                exit;
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'Access denied') !== false) {
                    $errors[] = '数据库账号或密码错误（' . htmlspecialchars($msg, ENT_QUOTES) . '）';
                } elseif (stripos($msg, 'Connection refused') !== false || stripos($msg, 'No such host') !== false) {
                    $errors[] = '无法连接到数据库服务器，请检查地址与端口';
                } else {
                    $errors[] = '数据库连接失败：' . htmlspecialchars($msg, ENT_QUOTES);
                }
            }
        }
        $step = 2;
    }

    // 第 3 步：站点与管理员
    if ($step === 3) {
        $site = [
            'name' => trim((string) ($_POST['site_name'] ?? '云盘')),
            'url'  => trim((string) ($_POST['site_url'] ?? '')),
        ];
        $admin = [
            'username' => trim((string) ($_POST['admin_user'] ?? '')),
            'password' => (string) ($_POST['admin_pass'] ?? ''),
            'confirm'  => (string) ($_POST['admin_pass2'] ?? ''),
            'email'    => trim((string) ($_POST['admin_email'] ?? '')),
        ];

        if ($site['name'] === '' || mb_strlen($site['name']) > 32) {
            $errors[] = '站点名称不能为空，且不超过 32 字';
        }
        if (!preg_match('/^[A-Za-z0-9_]{3,32}$/', $admin['username'])) {
            $errors[] = '管理员账号需为 3–32 位字母、数字或下划线';
        }
        if (strlen($admin['password']) < 6) {
            $errors[] = '管理员密码至少 6 位';
        }
        if ($admin['password'] !== $admin['confirm']) {
            $errors[] = '两次输入的密码不一致';
        }
        if ($admin['email'] !== '' && !filter_var($admin['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = '管理员邮箱格式不正确';
        }
        if ($site['url'] !== '' && !filter_var($site['url'], FILTER_VALIDATE_URL)) {
            $errors[] = '站点 URL 格式不正确（需含 http:// 或 https://）';
        }

        if (!$errors) {
            install_put('site', $site);
            install_put('admin', $admin);
            $_SESSION['_install_step'] = 4;
            header('Location: install.php?step=4');
            exit;
        }
        $step = 3;
    }

    // 第 4 步：执行安装
    if ($step === 4) {
        $doInstall = true;
    }
}

// ---------- 执行安装 ----------
$installLog = [];
if ($doInstall) {
    $db    = install_state('db');
    $site  = install_state('site');
    $admin = install_state('admin');

    if (!is_array($db) || !is_array($site) || !is_array($admin)) {
        $errors[] = '安装信息已丢失，请从第一步重新开始';
        $step = 1;
    } else {
        try {
            // 1. 连接
            $pdo = new PDO(
                "mysql:host={$db['host']};port={$db['port']};dbname={$db['name']};charset=utf8mb4",
                $db['user'],
                $db['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            $installLog[] = ['连接数据库', true, $db['name']];

            // 2. 建表
            $tables = Schema::ensure($pdo, $db['prefix']);
            $installLog[] = ['创建数据表', true, count($tables) . ' 张'];

            // 3. 管理员（幂等：已存在则仅提升为管理员）
            $st = $pdo->prepare("SELECT id FROM `{$db['prefix']}users` WHERE `username` = ? LIMIT 1");
            $st->execute([$admin['username']]);
            $existId = $st->fetchColumn();
            $now = date('Y-m-d H:i:s');
            $hash = password_hash($admin['password'], PASSWORD_DEFAULT);

            if ($existId) {
                $pdo->prepare("UPDATE `{$db['prefix']}users` SET `password_hash`=?, `role`='admin', `status`=1, `updated_at`=? WHERE `id`=?")
                    ->execute([$hash, $now, $existId]);
                $installLog[] = ['管理员账号', true, $admin['username'] . '（已存在，密码已重置）'];
            } else {
                $pdo->prepare(
                    "INSERT INTO `{$db['prefix']}users`
                     (`username`,`email`,`password_hash`,`nickname`,`role`,`status`,`created_at`,`updated_at`)
                     VALUES (?,?,?,?, 'admin', 1, ?, ?)"
                )->execute([
                    $admin['username'],
                    $admin['email'] !== '' ? $admin['email'] : null,
                    $hash,
                    $admin['username'],
                    $now,
                    $now,
                ]);
                $installLog[] = ['管理员账号', true, $admin['username']];
            }

            // 4. 默认设置
            $settings = Schema::defaultSettings();
            $settings['site_name'] = $site['name'];
            $settings['site_url']  = $site['url'];
            $ins = $pdo->prepare(
                "INSERT INTO `{$db['prefix']}settings` (`k`,`v`) VALUES (?,?)
                 ON DUPLICATE KEY UPDATE `v` = VALUES(`v`)"
            );
            foreach ($settings as $k => $v) {
                $ins->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
            }
            $installLog[] = ['写入默认设置', true, count($settings) . ' 项'];

            // 4.5 默认等级
            // 这段此前缺失：建表之后直接把等级表留空，后台「等级管理」是空白页，
            // 用户也无法被分配到任何等级。用 INSERT IGNORE 的等价写法保证幂等——
            // 已经存在同名等级时不重复插入。
            $lvIns = $pdo->prepare(
                "INSERT INTO `{$db['prefix']}levels`
                 (`name`,`sort`,`color`,`description`,`is_default`,`created_at`)
                 SELECT ?,?,?,?,?,? FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM `{$db['prefix']}levels` WHERE `name` = ?
                 )"
            );
            $lvCount = 0;
            foreach (Schema::defaultLevels() as $lv) {
                $lvIns->execute([
                    $lv['name'], $lv['sort'], $lv['color'], $lv['description'],
                    $lv['is_default'], $now, $lv['name'],
                ]);
                $lvCount += $lvIns->rowCount();
            }
            $totalLv = (int) $pdo->query("SELECT COUNT(*) FROM `{$db['prefix']}levels`")->fetchColumn();
            $installLog[] = ['初始化等级', true, $totalLv . ' 个（本次新增 ' . $lvCount . '）'];

            // 5. 目录与防护文件
            $dirs = [
                'data', 'runtime', 'runtime/chunks', 'runtime/logs', 'runtime/cache',
                'storage', 'storage/files',
            ];
            foreach ($dirs as $d) {
                $p = __DIR__ . '/' . $d;
                if (!is_dir($p)) {
                    @mkdir($p, 0755, true);
                }
            }
            $installLog[] = ['创建运行目录', true, count($dirs) . ' 个'];

            // 禁止直连的目录
            $deny = "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                  . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n";
            foreach (['data', 'runtime', 'storage'] as $d) {
                @file_put_contents(__DIR__ . '/' . $d . '/.htaccess', $deny);
                if (!is_file(__DIR__ . '/' . $d . '/index.html')) {
                    @file_put_contents(__DIR__ . '/' . $d . '/index.html', '');
                }
            }
            // PHP 防御：即使被直连也无法执行
            @file_put_contents(__DIR__ . '/storage/index.php', "<?php http_response_code(403); exit('Forbidden');\n");
            @file_put_contents(__DIR__ . '/runtime/index.php', "<?php http_response_code(403); exit('Forbidden');\n");
            $installLog[] = ['写入目录防护', true, '.htaccess / index.html'];

            // 6. 生成配置
            $appKey = bin2hex(random_bytes(32));
            $basePath = detect_base_path();
            $config = [
                'db' => [
                    'host'    => $db['host'],
                    'port'    => $db['port'],
                    'name'    => $db['name'],
                    'user'    => $db['user'],
                    'pass'    => $db['pass'],
                    'prefix'  => $db['prefix'],
                    'charset' => 'utf8mb4',
                ],
                'site' => [
                    'name'         => $site['name'],
                    'url'          => $site['url'],
                    'installed_at' => $now,
                    'version'      => INST_VERSION,
                ],
                'app' => [
                    'key'       => $appKey,
                    'debug'     => false,
                    'base_path' => $basePath,
                    'timezone'  => 'Asia/Shanghai',
                ],
            ];
            $body = "<?php\n"
                  . "// 由安装向导生成于 {$now}\n"
                  . "if (!defined('APP_ROOT')) { http_response_code(403); exit('Access denied'); }\n"
                  . "return " . var_export($config, true) . ";\n";
            $tmp = $configFile . '.tmp';
            if (@file_put_contents($tmp, $body) === false) {
                throw new RuntimeException('无法写入 data/config.php，请检查目录权限');
            }
            @chmod($tmp, 0640);
            if (!@rename($tmp, $configFile)) {
                throw new RuntimeException('配置文件落盘失败');
            }
            $installLog[] = ['生成配置文件', true, 'data/config.php'];

            // 7. 锁
            @file_put_contents($lockFile, date('c') . ' v' . INST_VERSION . PHP_EOL);
            $installLog[] = ['写入安装锁', true, 'data/install.lock'];

            unset($_SESSION['_install'], $_SESSION['_install_step']);
            $step = 5;
        } catch (Throwable $e) {
            $errors[] = '安装失败：' . $e->getMessage();
            $installLog[] = ['安装中断', false, $e->getMessage()];
            $step = 4;
        }
    }
}

$checks = env_checks();
$hardFail = false;
foreach ($checks as $c) {
    if (($c['hard'] ?? false) && !$c['ok']) {
        $hardFail = true;
    }
}
$dbSaved    = install_state('db');
$siteSaved  = install_state('site');
$adminSaved = install_state('admin');

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>安装向导 · 云盘</title>
<style>
:root{
  --green:#07C160; --green-d:#06AD56; --bg:#EDEDED; --card:#fff;
  --text:#191919; --sub:#888; --line:#E5E5E5;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
body{
  margin:0;background:var(--bg);color:var(--text);
  font:16px/1.6 -apple-system,BlinkMacSystemFont,"PingFang SC","Microsoft YaHei",sans-serif;
  padding:24px 16px 60px;
}
.wrap{max-width:640px;margin:0 auto}
.brand{text-align:center;margin-bottom:24px}
.brand .logo{
  width:64px;height:64px;border-radius:16px;background:var(--green);
  display:inline-flex;align-items:center;justify-content:center;
  color:#fff;font-size:30px;font-weight:600;margin-bottom:10px;
  box-shadow:0 4px 16px rgba(7,193,96,.25);
}
.brand h1{font-size:20px;margin:0 0 4px;font-weight:600}
.brand p{margin:0;color:var(--sub);font-size:13px}

.steps{display:flex;justify-content:center;gap:0;margin-bottom:20px}
.steps .s{display:flex;align-items:center;color:#bbb;font-size:13px}
.steps .s i{
  width:22px;height:22px;border-radius:50%;background:#D8D8D8;color:#fff;
  display:inline-flex;align-items:center;justify-content:center;
  font-size:12px;font-style:normal;margin-right:6px
}
.steps .s.on{color:var(--green)}
.steps .s.on i{background:var(--green)}
.steps .s.done i{background:var(--green)}
.steps .bar{width:24px;height:1px;background:#D8D8D8;margin:0 8px}

.card{background:var(--card);border-radius:12px;padding:4px 0;margin-bottom:16px;overflow:hidden}
.card .item{
  display:flex;align-items:center;justify-content:space-between;
  padding:14px 18px;border-bottom:1px solid var(--line);font-size:15px
}
.card .item:last-child{border-bottom:0}
.card .item .n{color:var(--text);flex:1;padding-right:12px}
.card .item .v{color:var(--sub);font-size:13px;text-align:right}
.ok{color:var(--green)}
.bad{color:#E64340}
.warn{color:#F5A623}
.hint{display:block;color:var(--sub);font-size:12px;margin-top:2px;line-height:1.4}

.field{padding:14px 18px;border-bottom:1px solid var(--line)}
.field:last-child{border-bottom:0}
.field label{display:block;font-size:14px;color:var(--sub);margin-bottom:6px}
.field input{
  width:100%;border:0;outline:0;background:transparent;
  font:16px/1.4 inherit;color:var(--text);padding:2px 0
}
.field input::placeholder{color:#C4C4C4}
.row{display:flex;gap:12px}
.row .field{flex:1;border-bottom:0;padding-right:0}

.btn{
  display:block;width:100%;border:0;border-radius:8px;
  background:var(--green);color:#fff;font:500 17px/48px inherit;
  text-align:center;cursor:pointer;text-decoration:none;
  transition:background .15s
}
.btn:active{background:var(--green-d)}
.btn.ghost{background:#fff;color:var(--green);border:1px solid var(--green);line-height:46px}
.btn[disabled]{background:#C9C9C9;cursor:not-allowed}
.btn+.btn{margin-top:12px}

.alert{border-radius:10px;padding:12px 16px;font-size:14px;margin-bottom:16px;line-height:1.5}
.alert.err{background:#FEF0F0;color:#E64340}
.alert.info{background:#E8F7EE;color:#06AD56}
.alert ul{margin:0;padding-left:18px}
.alert li+li{margin-top:4px}

.guide{background:#fff;border-radius:12px;padding:16px 18px;font-size:13px;color:var(--sub);line-height:1.8}
.guide code{background:#F2F2F2;padding:2px 6px;border-radius:4px;color:#333;font-size:12px}
.guide b{color:var(--text);font-weight:500}
.tip{text-align:center;color:var(--sub);font-size:12px;margin-top:18px;line-height:1.7}
.mono{font-family:ui-monospace,Consolas,monospace}
</style>
</head>
<body>
<div class="wrap">

  <div class="brand">
    <div class="logo">盘</div>
    <h1>云盘</h1>
    <p>v<?= h(INST_VERSION) ?> 安装向导</p>
  </div>

  <div class="steps">
    <?php foreach (['环境', '数据库', '账号', '完成'] as $i => $label):
        $n = $i + 1;
        $cls = $n < $step ? 'done' : ($n === $step ? 'on' : '');
        if ($step === 5) { $cls = 'done'; }
    ?>
      <?php if ($i > 0): ?><span class="bar"></span><?php endif; ?>
      <span class="s <?= $cls ?>"><i><?= $n < $step ? '✓' : $n ?></i><?= $label ?></span>
    <?php endforeach; ?>
  </div>

  <?php if ($errors): ?>
    <div class="alert err">
      <ul><?php foreach ($errors as $er): ?><li><?= $er ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($step === 1): ?>

    <div class="card">
      <?php foreach ($checks as $c):
          $cls = 'v';
          $mark = '';
          if (!$c['ok']) { $cls .= ' bad'; $mark = ' ✕'; }
          elseif (!empty($c['warn'])) { $cls .= ' warn'; $mark = ' !'; }
          else { $cls .= ' ok'; $mark = ' ✓'; }
      ?>
      <div class="item">
        <span class="n"><?= h($c['name']) ?>
          <?php if (!empty($c['hint'])): ?><span class="hint"><?= h($c['hint']) ?></span><?php endif; ?>
        </span>
        <span class="<?= $cls ?>"><?= h($c['value']) ?><?= $mark ?></span>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if ($hardFail): ?>
      <div class="alert err">存在未满足的必需条件，请修复后刷新本页。</div>
      <a href="install.php?step=1" class="btn ghost">重新检测</a>
    <?php else: ?>
      <form method="post" action="install.php?step=1">
        <input type="hidden" name="step" value="1">
        <button class="btn" type="submit">环境通过，下一步</button>
      </form>
    <?php endif; ?>

  <?php elseif ($step === 2): ?>

    <form method="post" action="install.php?step=2" autocomplete="off">
      <input type="hidden" name="step" value="2">
      <div class="card">
        <div class="row">
          <div class="field" style="flex:2">
            <label>数据库地址</label>
            <input name="db_host" value="<?= h($dbSaved['host'] ?? '127.0.0.1') ?>" placeholder="127.0.0.1" required>
          </div>
          <div class="field" style="flex:1">
            <label>端口</label>
            <input name="db_port" value="<?= h($dbSaved['port'] ?? 3306) ?>" placeholder="3306" required>
          </div>
        </div>
      </div>
      <div class="card">
        <div class="field">
          <label>数据库名</label>
          <input name="db_name" value="<?= h($dbSaved['name'] ?? 'cloud_pan') ?>" placeholder="cloud_pan" required>
          <span class="hint">不存在将自动创建（utf8mb4）</span>
        </div>
        <div class="field">
          <label>用户名</label>
          <input name="db_user" value="<?= h($dbSaved['user'] ?? 'root') ?>" required>
        </div>
        <div class="field">
          <label>密码</label>
          <input name="db_pass" type="password" value="<?= h($dbSaved['pass'] ?? '') ?>" placeholder="留空表示无密码">
        </div>
        <div class="field">
          <label>表前缀</label>
          <input name="db_prefix" value="<?= h($dbSaved['prefix'] ?? 'wx_') ?>" placeholder="wx_">
          <span class="hint">同一数据库部署多个站点时用于隔离</span>
        </div>
      </div>
      <button class="btn" type="submit">测试连接并继续</button>
    </form>

  <?php elseif ($step === 3): ?>

    <form method="post" action="install.php?step=3" autocomplete="off">
      <input type="hidden" name="step" value="3">
      <div class="alert info">数据库连接正常<?= install_state('db_version') ? '：' . h(install_state('db_version')) : '' ?></div>

      <div class="card">
        <div class="field">
          <label>站点名称</label>
          <input name="site_name" value="<?= h($siteSaved['name'] ?? '云盘') ?>" maxlength="32" required>
        </div>
        <div class="field">
          <label>站点 URL（可选）</label>
          <input name="site_url" value="<?= h($siteSaved['url'] ?? '') ?>" placeholder="https://pan.example.com">
          <span class="hint">留空则按当前访问地址自动识别</span>
        </div>
      </div>

      <div class="card">
        <div class="field">
          <label>管理员账号</label>
          <input name="admin_user" value="<?= h($adminSaved['username'] ?? '') ?>" placeholder="3–32 位字母、数字、下划线" required>
        </div>
        <div class="field">
          <label>管理员密码</label>
          <input name="admin_pass" type="password" placeholder="至少 6 位" minlength="6" required>
        </div>
        <div class="field">
          <label>确认密码</label>
          <input name="admin_pass2" type="password" placeholder="再输入一次" required>
        </div>
        <div class="field">
          <label>邮箱（可选）</label>
          <input name="admin_email" type="email" value="<?= h($adminSaved['email'] ?? '') ?>" placeholder="admin@example.com">
        </div>
      </div>

      <button class="btn" type="submit">下一步</button>
    </form>

  <?php elseif ($step === 4): ?>

    <div class="card">
      <div class="item"><span class="n">数据库</span><span class="v"><?= h(($dbSaved['host'] ?? '') . ':' . ($dbSaved['port'] ?? '') . '/' . ($dbSaved['name'] ?? '')) ?></span></div>
      <div class="item"><span class="n">表前缀</span><span class="v mono"><?= h($dbSaved['prefix'] ?? '') ?></span></div>
      <div class="item"><span class="n">站点名称</span><span class="v"><?= h($siteSaved['name'] ?? '') ?></span></div>
      <div class="item"><span class="n">管理员</span><span class="v"><?= h($adminSaved['username'] ?? '') ?></span></div>
      <div class="item"><span class="n">访问路径</span><span class="v mono"><?= h(detect_base_path() === '' ? '/' : detect_base_path() . '/') ?></span></div>
    </div>

    <?php if ($installLog): ?>
      <div class="card">
        <?php foreach ($installLog as [$name, $ok, $detail]): ?>
        <div class="item">
          <span class="n"><?= h($name) ?></span>
          <span class="v <?= $ok ? 'ok' : 'bad' ?>"><?= $ok ? '✓' : '✕' ?> <?= h($detail) ?></span>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="alert info">
      点击下方按钮开始写入配置。安装过程不会删除数据库中已有的同名表。
    </div>

    <form method="post" action="install.php?step=4">
      <input type="hidden" name="step" value="4">
      <button class="btn" type="submit">开始安装</button>
    </form>

  <?php elseif ($step === 5): ?>

    <div class="alert info" style="font-size:16px;text-align:center;padding:20px">
      <strong>安装完成</strong><br>
      <span style="font-size:13px;opacity:.85">系统已就绪，可以开始使用</span>
    </div>

    <div class="card">
      <div class="item"><span class="n">前台首页</span><a class="v ok" href="./" style="text-decoration:none"><?= h(detect_base_path() === '' ? '/' : detect_base_path() . '/') ?> →</a></div>
      <div class="item"><span class="n">后台管理</span><a class="v ok" href="./admin" style="text-decoration:none">/admin →</a></div>
      <div class="item"><span class="n">用户中心</span><a class="v ok" href="./user" style="text-decoration:none">/user →</a></div>
    </div>

    <div class="guide">
      <b>必读安全提示</b><br>
      1. 请立即删除或用服务器配置封禁 <code>install.php</code>，否则存在被重新安装的风险。<br>
      2. 若使用 Nginx，请把 <code>/storage/</code>、<code>/data/</code>、<code>/runtime/</code> 配置为禁止访问（.htaccess 对 Nginx 无效）。<br>
      3. 项目根目录的 <code>nginx.conf.example</code> 提供了可直接粘贴的配置片段。<br>
      4. 生产环境请启用 HTTPS，并将 <code>data/</code> 目录权限收紧至 0750。
    </div>

    <div class="tip">安装锁已写入 data/install.lock，重复访问本页将被拒绝。</div>

  <?php endif; ?>

  <div class="tip">云盘 v<?= h(INST_VERSION) ?> · PHP <?= h(PHP_VERSION) ?></div>
</div>
</body>
</html>
