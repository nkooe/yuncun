<?php
/**
 * 部署自检
 *
 * 每次部署后跑一次，检查那些「代码没问题、但环境有问题」的项。
 *
 * 存在的理由：v1.1.18 之后测 40MB 上传时发现 storage/files 下 57 个
 * 子目录属主是 root:root —— git archive 解压时用 root 创建，www 用户
 * 无法写入，所有落到这些目录的文件上传都会失败，报「文件入库失败」。
 * php -l 和 HTTP 冒烟都看不出来，只有真传大文件才暴露。
 *
 *   php tools/preflight.php              # 检查
 *   php tools/preflight.php --fix        # 检查并修复
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
$fix  = in_array('--fix', $argv, true);

$pass = 0;
$fail = 0;
$warn = 0;

function ok(string $m): void
{
    global $pass;
    $pass++;
    printf("  \033[32m✓\033[0m %s\n", $m);
}

function bad(string $m, string $hint = ''): void
{
    global $fail;
    $fail++;
    printf("  \033[31m✗\033[0m %s%s\n", $m, $hint !== '' ? "  → {$hint}" : '');
}

function warn(string $m, string $hint = ''): void
{
    global $warn;
    $warn++;
    printf("  \033[33m!\033[0m %s%s\n", $m, $hint !== '' ? "  → {$hint}" : '');
}

echo "\n部署自检 · {$root}\n";
echo str_repeat('─', 62), "\n";

// ── 1. 目录属主（最容易在部署后坏掉的一项）────────────────────
echo "\n[目录属主]\n";
$webUser = 'www';
$needOwn = ['storage', 'storage/files', 'runtime', 'runtime/chunks', 'runtime/cache', 'runtime/logs'];

foreach ($needOwn as $rel) {
    $p = $root . '/' . $rel;
    if (!is_dir($p)) {
        warn("{$rel} 不存在", '首次上传时会自动创建');
        continue;
    }
    $owner = function_exists('posix_getpwuid')
        ? (posix_getpwuid(fileowner($p))['name'] ?? (string) fileowner($p))
        : (string) fileowner($p);
    if ($owner === $webUser) {
        ok("{$rel} 属主 {$owner}");
    } else {
        bad("{$rel} 属主 {$owner}（应为 {$webUser}）", $fix ? '' : 'php tools/preflight.php --fix');
    }
}

// 深层目录抽样：storage/files 的分桶目录常被 git archive 建成 root
if (is_dir($root . '/storage/files')) {
    $bad = 0;
    $sample = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/storage/files', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $f) {
        if (!$f->isDir()) {
            continue;
        }
        $owner = function_exists('posix_getpwuid')
            ? (posix_getpwuid($f->getOwner())['name'] ?? (string) $f->getOwner())
            : (string) $f->getOwner();
        if ($owner !== $webUser) {
            $bad++;
            if (count($sample) < 3) {
                $sample[] = str_replace($root . '/', '', $f->getPathname());
            }
        }
    }
    if ($bad === 0) {
        ok('storage/files 全部子目录属主正确');
    } else {
        bad("storage/files 有 {$bad} 个子目录属主不是 {$webUser}", implode(', ', $sample));
    }
}

// ── 2. 可写性（真写一个文件，比看权限位可靠）──────────────────
echo "\n[实际可写]\n";
foreach (['storage/files', 'runtime', 'runtime/chunks'] as $rel) {
    $p = $root . '/' . $rel;
    if (!is_dir($p)) {
        warn("{$rel} 不存在，跳过");
        continue;
    }
    $probe = $p . '/.preflight-probe';
    $w = @file_put_contents($probe, 'x');
    if ($w === 1) {
        @unlink($probe);
        ok("{$rel} 可写");
    } else {
        bad("{$rel} 不可写");
    }
}

// ── 3. 关键文件 ────────────────────────────────────────────────
echo "\n[关键文件]\n";
$need = [
    'index.php'         => 0o644,
    'install.php'       => 0o644,
    'app/bootstrap.php' => 0o644,
    'assets/app.css'    => 0o644,
    '.htaccess'         => 0o644,
    'data/config.php'   => 0o640,
    'data/install.lock' => 0o644,
];
foreach ($need as $rel => $want) {
    $p = $root . '/' . $rel;
    if (!is_file($p)) {
        bad("{$rel} 缺失");
        continue;
    }
    $mode = fileperms($p) & 0o777;
    if ($mode === $want) {
        ok("{$rel} " . decoct($mode));
    } else {
        warn("{$rel} 权限 " . decoct($mode) . "（建议 " . decoct($want) . "）");
    }
}

// ── 4. 数据库与配置 ────────────────────────────────────────────
echo "\n[配置]\n";
$cfgFile = $root . '/data/config.php';
if (is_file($cfgFile)) {
    // config.php 有防护：未定义 APP_ROOT 会直接 exit('Access denied')
    if (!defined('APP_ROOT')) {
        define('APP_ROOT', $root);
    }
    $cfg = @include $cfgFile;
    if (is_array($cfg) && isset($cfg['db'])) {
        ok('data/config.php 可解析');
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['db']['host'] ?? '127.0.0.1',
                (int) ($cfg['db']['port'] ?? 3306),
                $cfg['db']['name'] ?? ''
            );
            $pdo = new PDO($dsn, (string) ($cfg['db']['user'] ?? ''), (string) ($cfg['db']['pass'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            $n = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
            ok("数据库连接正常（{$n} 张表）");
        } catch (Throwable $e) {
            bad('数据库连接失败', $e->getMessage());
        }
    } else {
        bad('data/config.php 无法解析');
    }
} else {
    warn('data/config.php 不存在', '站点可能尚未安装');
}

// ── 5. 磁盘余量 ────────────────────────────────────────────────
echo "\n[磁盘]\n";
$free = @disk_free_space($root);
$total = @disk_total_space($root);
if ($free !== false && $total) {
    $pct = (int) round((1 - $free / $total) * 100);
    if ($pct >= 95) {
        bad("磁盘已用 {$pct}%", '上传会失败，请清理');
    } elseif ($pct >= 85) {
        warn("磁盘已用 {$pct}%", '剩余空间偏少，大文件上传可能被 disk_guard 拒绝');
    } else {
        ok('磁盘已用 ' . $pct . '%（剩余 ' . round($free / 1073741824, 1) . ' GB）');
    }
}

// ── 6. 上传链路自洽：chunks 目录与 storage 是否同一分区 ────────
echo "\n[上传链路]\n";
$chunksDev = @fileowner($root . '/runtime/chunks');
$filesDev  = @fileowner($root . '/storage/files');
if (@stat($root . '/runtime/chunks')['dev'] === @stat($root . '/storage/files')['dev']) {
    ok('chunks 与 storage 同分区（rename 直接生效，无需跨区拷贝）');
} else {
    warn('chunks 与 storage 不同分区', '合并后需要 copy，大文件会有额外耗时');
}

// ── 修复 ────────────────────────────────────────────────────────
if ($fix && $fail > 0) {
    echo "\n[修复]\n";
    $cmds = [
        'chown -R www:www ' . escapeshellarg($root . '/storage'),
        'chown -R www:www ' . escapeshellarg($root . '/runtime'),
        'chmod -R u+rwX ' . escapeshellarg($root . '/storage'),
        'chmod -R u+rwX ' . escapeshellarg($root . '/runtime'),
    ];
    foreach ($cmds as $c) {
        @exec($c . ' 2>&1', $o, $rc);
        printf("  %s %s\n", $rc === 0 ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m", $c);
    }
    echo "  已修复，建议重跑一次自检确认。\n";
}

echo "\n", str_repeat('─', 62), "\n";
printf("  通过 %d  失败 %d  警告 %d\n\n", $pass, $fail, $warn);
exit($fail > 0 ? 1 : 0);