<?php
/**
 * 演示数据填充（CLI）
 *
 *   php tools/seed.php            # 填充演示数据
 *   php tools/seed.php --clean    # 先清空再填充
 *   php tools/seed.php --wipe     # 只清空
 *
 * 可反复执行：所有演示账号以 demo_ 开头，清理时只删这些，
 * 不会动 admin 与真实上传的内容。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
if (!defined('APP_ROOT')) {
    define('APP_ROOT', $root);
}
$configFile = $root . '/data/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "未安装：找不到 data/config.php\n");
    exit(1);
}

$cfg = require $configFile;
$db = $cfg['db'] ?? [];
$prefix = (string) ($db['prefix'] ?? 'wx_');

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $db['host'] ?? '127.0.0.1', (int) ($db['port'] ?? 3306), $db['name'] ?? ''),
    (string) ($db['user'] ?? ''),
    (string) ($db['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$args = $argv ?? [];
$clean = in_array('--clean', $args, true);
$wipe  = in_array('--wipe', $args, true);

$T = static fn(string $n): string => '`' . $prefix . $n . '`';
$now = date('Y-m-d H:i:s');

// ---------------------------------------------------------------- 清空

function wipe(PDO $pdo, callable $T): void
{
    echo "清空演示数据...\n";
    $pdo->exec('DELETE FROM ' . $T('downloads'));
    $pdo->exec('DELETE FROM ' . $T('shares'));
    $pdo->exec('DELETE FROM ' . $T('files'));
    $pdo->exec('DELETE FROM ' . $T('folders'));
    $pdo->exec('DELETE FROM ' . $T('login_logs'));
    $pdo->exec('DELETE FROM ' . $T('admin_logs'));
    $pdo->exec("DELETE FROM " . $T('users') . " WHERE `username` LIKE 'demo\\_%'");
    $pdo->exec('ALTER TABLE ' . $T('files') . ' AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE ' . $T('folders') . ' AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE ' . $T('shares') . ' AUTO_INCREMENT = 1');
    $pdo->exec('ALTER TABLE ' . $T('downloads') . ' AUTO_INCREMENT = 1');
}

if ($wipe) {
    wipe($pdo, $T);
    echo "完成。\n";
    exit(0);
}
if ($clean) {
    wipe($pdo, $T);
}

// ---------------------------------------------------------------- 物理文件

$filesRoot = $root . '/storage/files';
$makeBlob = static function (int $bytes, string $salt = ''): array {
    // 每个文件带唯一盐值，确保 sha256 不同（否则内容寻址会把它们合并成一份）
    $line = "Cloud Pan demo payload [$salt]. 演示文件内容，仅用于本地测试。\n";
    $buf = str_repeat($line, max(1, intdiv($bytes, strlen($line)) + 1));
    $buf = substr($buf, 0, $bytes);
    $sha = hash('sha256', $buf);
    return [$sha, $buf];
};

// ---------------------------------------------------------------- 等级

echo "写入用户等级...\n";
$levels = [
    ['普通会员', 0,  '#86909C', '默认等级，可访问公开资源', 1],
    ['VIP 会员',  10, '#E67E22', '可访问需要 VIP 等级的资源', 0],
    ['超级会员',  20, '#722ED1', '可访问全部等级资源',        0],
];
$pdo->exec('DELETE FROM ' . $T('levels'));
$pdo->exec('ALTER TABLE ' . $T('levels') . ' AUTO_INCREMENT = 1');
$levelIds = [];
$st = $pdo->prepare('INSERT INTO ' . $T('levels')
    . ' (`name`,`sort`,`color`,`description`,`is_default`,`created_at`) VALUES (?,?,?,?,?,?)');
foreach ($levels as $lv) {
    $st->execute([$lv[0], $lv[1], $lv[2], $lv[3], $lv[4], $now]);
    $levelIds[$lv[0]] = (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------- 用户

echo "写入演示用户...\n";
$hash = password_hash('Demo@12345', PASSWORD_DEFAULT);
$users = [
    ['demo_vip',   '测试 VIP',   $levelIds['VIP 会员'],  1],
    ['demo_user',  '测试用户',   $levelIds['普通会员'],  1],
    ['demo_super', '超级会员',   $levelIds['超级会员'],  1],
];
$uSt = $pdo->prepare('INSERT INTO ' . $T('users')
    . ' (`username`,`password_hash`,`nickname`,`email`,`role`,`level_id`,`status`,`download_quota`,`quota_period`,`download_used`,`created_at`,`updated_at`)'
    . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
$userIds = [];
foreach ($users as $i => $u) {
    $uSt->execute([
        $u[0], $hash, $u[1], $u[0] . '@example.com', 'user', $u[2], $u[3],
        100, 'total', 0, date('Y-m-d H:i:s', time() - 86400 * (30 - $i)), $now,
    ]);
    $userIds[$u[0]] = (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------- 文件夹

echo "写入文件夹...\n";
$pdo->exec('DELETE FROM ' . $T('folders'));
$pdo->exec('ALTER TABLE ' . $T('folders') . ' AUTO_INCREMENT = 1');
$layout = [
    ['产品文档', 0, 1, 0],
    ['2026 年度', 0, 2, 0],   // parent 用索引占位，下面替换
    ['历史归档', 0, 3, 0],
    ['设计素材', 0, 4, 0],
    ['LOGO 源文件', 0, 5, 0],
    ['海报与 Banner', 0, 6, 0],
    ['合同模板', 0, 7, 0],
    ['培训视频', 0, 8, 0],
    ['客户资料', 0, 9, 0],
    ['内部资料', 0, 10, $levelIds['VIP 会员']],
    ['财务报表', 0, 11, $levelIds['超级会员']],
    ['临时文件', 0, 12, 0],
];
$fSt = $pdo->prepare('INSERT INTO ' . $T('folders')
    . ' (`parent_id`,`name`,`sort`,`is_public`,`level_id`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,?,?)');
$folderIds = [];
foreach ($layout as $f) {
    $fSt->execute([(int) $f[1], $f[0], (int) $f[2], 1, (int) $f[3], $now, $now]);
    $folderIds[$f[0]] = (int) $pdo->lastInsertId();
}
// 建立两层嵌套
$nested = [
    ['2026 年度', '产品文档'],
    ['历史归档', '产品文档'],
    ['LOGO 源文件', '设计素材'],
    ['海报与 Banner', '设计素材'],
];
foreach ($nested as [$child, $parent]) {
    if (isset($folderIds[$child], $folderIds[$parent])) {
        $pdo->prepare('UPDATE ' . $T('folders') . ' SET `parent_id` = ? WHERE `id` = ?')
            ->execute([$folderIds[$parent], $folderIds[$child]]);
    }
}

// ---------------------------------------------------------------- 文件

echo "写入文件...\n";
$pdo->exec('DELETE FROM ' . $T('files'));
$pdo->exec('ALTER TABLE ' . $T('files') . ' AUTO_INCREMENT = 1');

$samples = [
    ['2026 年度产品白皮书.pdf',       'pdf',  3145728, '产品文档', 0],
    ['产品需求文档 v3.docx',          'docx', 524288,  '产品文档', 0],
    ['版本迭代记录.xlsx',             'xlsx', 262144,  '产品文档', 0],
    ['竞品分析报告.pdf',              'pdf',  1572864, '产品文档', 0],
    ['品牌视觉规范.zip',              'zip',  10485760, '设计素材', 0],
    ['主视觉配色方案.jpg',            'jpg',  2097152, '设计素材', 0],
    ['产品演示视频.mp4',              'mp4',  20971520, '设计素材', 0],
    ['LOGO 矢量源文件.ai',            'ai',   1048576, 'LOGO 源文件', 0],
    ['图标集合.svg',                  'svg',  65536,   'LOGO 源文件', 0],
    ['618 活动 BANNER.psd',           'psd',  5242880, '海报与 Banner', 0],
    ['春节主题海报.png',              'png',  3145728, '海报与 Banner', 0],
    ['标准劳动合同模板.docx',         'docx', 131072,  '合同模板', 0],
    ['保密协议 NDA.pdf',              'pdf',  262144,  '合同模板', 0],
    ['供应商合作协议.docx',           'docx', 196608,  '合同模板', 0],
    ['新员工入职培训.mp4',            'mp4',  52428800,'培训视频', 0],
    ['产品操作演示.mp4',              'mp4',  31457280,'培训视频', 0],
    ['客户名单 2026.xlsx',            'xlsx', 1179648, '客户资料', $levelIds['VIP 会员']],
    ['客户合同扫描件.zip',            'zip',  5242880, '客户资料', $levelIds['VIP 会员']],
    ['核心客户报价单.xlsx',           'xlsx', 262144,  '客户资料', $levelIds['超级会员']],
    ['内部薪酬体系.docx',             'docx', 131072,  '内部资料', $levelIds['VIP 会员']],
    ['年度战略规划.pdf',              'pdf',  2097152, '内部资料', $levelIds['超级会员']],
    ['2026 Q1 财务报表.xlsx',         'xlsx', 524288,  '财务报表', $levelIds['超级会员']],
    ['2026 Q2 财务报表.xlsx',         'xlsx', 548576,  '财务报表', $levelIds['超级会员']],
    ['审计报告.pdf',                  'pdf',  3145728, '财务报表', $levelIds['超级会员']],
    ['临时导出数据.csv',              'csv',  1048576, '临时文件', 0],
    ['截图 2026-03-15.png',           'png',  524288,  '临时文件', 0],
    ['一个名字特别特别长的文件用来测试在小屏幕上会不会溢出显示异常.docx', 'docx', 88888, '', 0],
    ['README.txt',                    'txt',  4096,    '', 0],
    ['素材打包.rar',                  'rar',  15728640, '', 0],
    ['接口文档.pdf',                  'pdf',  786432,  '', 0],
];

$mimeMap = [
    'pdf' => 'application/pdf', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'zip' => 'application/zip', 'rar' => 'application/vnd.rar',
    'jpg' => 'image/jpeg', 'png' => 'image/png', 'svg' => 'image/svg+xml',
    'mp4' => 'video/mp4', 'psd' => 'image/vnd.adobe.photoshop',
    'ai' => 'application/postscript', 'csv' => 'text/csv', 'txt' => 'text/plain',
];

$iSt = $pdo->prepare('INSERT INTO ' . $T('files')
    . ' (`folder_id`,`name`,`orig_name`,`ext`,`mime`,`size`,`storage_key`,`sha256`,`downloads`,`is_public`,`level_id`,`status`,`uploader_id`,`created_at`,`updated_at`)'
    . ' VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');

$written = 0;
$fileIds = [];
foreach ($samples as $idx => $s) {
    [$name, $ext, $size, $folderName, $levelId] = $s;
    $folderId = $folderName !== '' && isset($folderIds[$folderName]) ? $folderIds[$folderName] : 0;

    // 生成物理文件：实际大小封顶 2MB，元数据与落盘保持一致，保证下载体验真实
    $realSize = min($size, 2 * 1024 * 1024);
    [$sha, $blob] = $makeBlob($realSize, $name);
    $key = substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha . '.bin';
    $abs = $filesRoot . '/' . $key;
    if (!is_file($abs)) {
        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $blob);
        $written++;
    }

    $created = date('Y-m-d H:i:s', time() - (int) (($idx + 1) * 3600 * 7));
    $iSt->execute([
        $folderId, $name, $name, $ext, $mimeMap[$ext] ?? 'application/octet-stream',
        strlen($blob), $key, $sha, random_int(0, 300), 1, (int) $levelId, 1, 1, $created, $created,
    ]);
    $fileIds[] = [(int) $pdo->lastInsertId(), $name];
}

// 回收站：3 个文件
$trashFiles = [
    ['废弃草稿.docx', 'docx', 65536],
    ['旧版说明.pdf',  'pdf',  131072],
    ['测试导出.csv',  'csv',  32768],
];
foreach ($trashFiles as $t) {
    [$sha, $blob] = $makeBlob((int) $t[2], 'trash:' . $t[0]);
    $key = substr($sha, 0, 2) . '/' . substr($sha, 2, 2) . '/' . $sha . '.bin';
    $abs = $filesRoot . '/' . $key;
    if (!is_file($abs)) {
        @mkdir(dirname($abs), 0775, true);
        file_put_contents($abs, $blob);
        $written++;
    }
    $iSt->execute([
        0, $t[0], $t[0], $t[1], $mimeMap[$t[1]] ?? 'application/octet-stream',
        strlen($blob), $key, $sha, 0, 1, 0, 1, 1,
        date('Y-m-d H:i:s', time() - 86400 * 3), date('Y-m-d H:i:s', time() - 86400 * 2),
    ]);
    $delId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE ' . $T('files') . ' SET `deleted_at` = ? WHERE `id` = ?')
        ->execute([date('Y-m-d H:i:s', time() - 86400 * 2), $delId]);
}
// 回收站：1 个文件夹
$pdo->prepare('INSERT INTO ' . $T('folders')
    . ' (`parent_id`,`name`,`sort`,`is_public`,`level_id`,`created_at`,`updated_at`,`deleted_at`) VALUES (0,?,0,1,0,?,?,?)')
    ->execute(['已废弃项目', $now, $now, date('Y-m-d H:i:s', time() - 86400)]);

// ---------------------------------------------------------------- 分享

echo "写入分享链接...\n";
$pdo->exec('DELETE FROM ' . $T('shares'));
$pdo->exec('ALTER TABLE ' . $T('shares') . ' AUTO_INCREMENT = 1');
$sSt = $pdo->prepare('INSERT INTO ' . $T('shares')
    . ' (`file_id`,`user_id`,`token`,`code`,`max_downloads`,`downloads`,`expires_at`,`status`,`created_at`) VALUES (?,?,?,?,?,?,?,?,?)');
$shareSpec = [
    [0, 'k7m2', 0,   15, null],
    [1, '',     20,  8,  7],
    [4, 'ab3x', 0,   0,  null],
    [14,'',     5,   5,  3],
    [16,'vip8', 100, 12, 30],
];
foreach ($shareSpec as $i => $sp) {
    if (!isset($fileIds[$sp[0]])) continue;
    $exp = $sp[4] !== null ? date('Y-m-d H:i:s', time() + 86400 * (int) $sp[4]) : null;
    $sSt->execute([
        $fileIds[$sp[0]][0], 1, bin2hex(random_bytes(6)), $sp[1],
        (int) $sp[2], (int) $sp[3], $exp, 1,
        date('Y-m-d H:i:s', time() - 3600 * ($i + 1)),
    ]);
}

// ---------------------------------------------------------------- 下载 / 登录记录

echo "写入下载与登录记录...\n";
$pdo->exec('DELETE FROM ' . $T('downloads'));
$pdo->exec('ALTER TABLE ' . $T('downloads') . ' AUTO_INCREMENT = 1');

$dSt = $pdo->prepare('INSERT INTO ' . $T('downloads')
    . ' (`file_id`,`user_id`,`share_id`,`ip`,`ua`,`bytes_sent`,`status`,`created_at`,`finished_at`) VALUES (?,?,?,?,?,?,?,?,?)');
$uaList = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0',
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 13_6) AppleWebKit/605.1.15 Safari/17.0',
    'Mozilla/5.0 (iPhone; CPU iPhone OS 17_2 like Mac OS X) Mobile/15E148',
    'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/120.0',
];
$actors = [1 => 'admin', $userIds['demo_vip'] => 'demo_vip', $userIds['demo_user'] => 'demo_user'];
$actorIds = array_keys($actors);

$dlCount = 0;
for ($i = 0; $i < 75; $i++) {
    $f = $fileIds[array_rand($fileIds)];
    $uid = $actorIds[array_rand($actorIds)];
    $status = match (true) {
        $i % 17 === 0 => 'aborted',
        $i % 23 === 0 => 'pending',
        default       => 'done',
    };
    $created = date('Y-m-d H:i:s', time() - (int) ($i * 3600 * 1.7 + random_int(0, 900)));
    $dSt->execute([
        $f[0], $uid, 0,
        '203.0.113.' . random_int(2, 250),
        $uaList[array_rand($uaList)],
        $status === 'done' ? random_int(80000, 8000000) : random_int(0, 500000),
        $status, $created,
        $status === 'done' ? $created : null,
    ]);
    $dlCount++;
}

$pdo->exec('DELETE FROM ' . $T('login_logs'));
$lSt = $pdo->prepare('INSERT INTO ' . $T('login_logs')
    . ' (`user_id`,`username`,`ip`,`ua`,`status`,`msg`,`created_at`) VALUES (?,?,?,?,?,?,?)');
$loginCount = 0;
for ($i = 0; $i < 45; $i++) {
    $isOk = $i % 6 !== 0;
    $uid = $isOk ? $actorIds[array_rand($actorIds)] : 0;
    $uname = $isOk ? $actors[$uid] : 'unknown_user';
    $lSt->execute([
        $uid, $uname,
        '203.0.113.' . random_int(2, 250),
        $uaList[array_rand($uaList)],
        $isOk ? 'ok' : 'fail',
        $isOk ? '登录成功' : '账号或密码错误',
        date('Y-m-d H:i:s', time() - (int) ($i * 3600 * 2.4 + random_int(0, 600))),
    ]);
    $loginCount++;
}

// ---------------------------------------------------------------- 汇总

$stat = static function (PDO $pdo, callable $T, string $table) {
    return (int) $pdo->query('SELECT COUNT(*) FROM ' . $T($table))->fetchColumn();
};

echo "\n=== 完成 ===\n";
printf("  等级    %d\n", $stat($pdo, $T, 'levels'));
printf("  用户    %d（含 admin）\n", $stat($pdo, $T, 'users'));
printf("  文件夹  %d\n", $stat($pdo, $T, 'folders'));
printf("  文件    %d\n", $stat($pdo, $T, 'files'));
printf("  分享    %d\n", $stat($pdo, $T, 'shares'));
printf("  下载    %d\n", $dlCount);
printf("  登录    %d\n", $loginCount);
printf("  落盘文件 %d 个\n", $written);
echo "\n演示账号（密码均为 Demo@12345）:\n";
echo "  demo_user   普通会员\n  demo_vip    VIP 会员\n  demo_super  超级会员\n";
echo "\n清理：php tools/seed.php --wipe\n";
