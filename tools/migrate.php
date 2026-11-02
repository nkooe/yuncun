<?php
/**
 * 数据库迁移脚本（CLI）
 *
 *   php tools/migrate.php
 *
 * 幂等：已存在的表/列会跳过，可反复执行。
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$root = dirname(__DIR__);
define('APP_ROOT', $root);

$configFile = $root . '/data/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "未安装：找不到 data/config.php\n");
    exit(1);
}
$cfg = require $configFile;
$db = $cfg['db'] ?? [];
$prefix = (string) ($db['prefix'] ?? '');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $db['host'] ?? '127.0.0.1',
    (int) ($db['port'] ?? 3306),
    $db['name'] ?? ''
);

try {
    $pdo = new PDO($dsn, (string) ($db['user'] ?? ''), (string) ($db['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    fwrite(STDERR, "数据库连接失败: " . $e->getMessage() . "\n");
    exit(1);
}

function hasTable(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $st->execute([$table]);
    return (int) $st->fetchColumn() > 0;
}

function hasColumn(PDO $pdo, string $table, string $col): bool
{
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $st->execute([$table, $col]);
    return (int) $st->fetchColumn() > 0;
}

$log = [];
$now = date('Y-m-d H:i:s');

// ---- 1. 等级表 ----
$levelsTable = $prefix . 'levels';
if (!hasTable($pdo, $levelsTable)) {
    $pdo->exec("CREATE TABLE `{$levelsTable}` (
      `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `name` VARCHAR(32) NOT NULL,
      `sort` INT NOT NULL DEFAULT 0 COMMENT '数值越大等级越高',
      `color` VARCHAR(16) NOT NULL DEFAULT '#07C160',
      `description` VARCHAR(255) NOT NULL DEFAULT '',
      `is_default` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '新注册用户默认等级',
      `created_at` DATETIME NOT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_sort` (`sort`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $log[] = '创建表 levels';
} else {
    $log[] = '表 levels 已存在，跳过';
}

// ---- 2. 各表加 level_id ----
$alters = [
    $prefix . 'users'   => "ALTER TABLE `%s` ADD COLUMN `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户等级 0=无等级' AFTER `role`",
    $prefix . 'files'   => "ALTER TABLE `%s` ADD COLUMN `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '访问所需等级 0=不限' AFTER `is_public`",
    $prefix . 'folders' => "ALTER TABLE `%s` ADD COLUMN `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '访问所需等级 0=不限' AFTER `is_public`",
];
foreach ($alters as $table => $sqlTpl) {
    if (!hasTable($pdo, $table)) {
        $log[] = "表 {$table} 不存在，跳过";
        continue;
    }
    if (hasColumn($pdo, $table, 'level_id')) {
        $log[] = "{$table}.level_id 已存在，跳过";
        continue;
    }
    $pdo->exec(sprintf($sqlTpl, $table));
    $log[] = "{$table} 新增 level_id";
}

// ---- 2.5 可见性控制：可见等级 + 仅登录可见 ----
$visAlters = [
    $prefix . 'files' => [
        'view_level_id'  => "ALTER TABLE `%s` ADD COLUMN `view_level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '可见所需等级 0=不限' AFTER `level_id`",
        'login_required' => "ALTER TABLE `%s` ADD COLUMN `login_required` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=仅登录用户可见' AFTER `view_level_id`",
    ],
    $prefix . 'folders' => [
        'view_level_id'  => "ALTER TABLE `%s` ADD COLUMN `view_level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '可见所需等级 0=不限' AFTER `level_id`",
        'login_required' => "ALTER TABLE `%s` ADD COLUMN `login_required` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=仅登录用户可见' AFTER `view_level_id`",
    ],
];
foreach ($visAlters as $table => $cols) {
    if (!hasTable($pdo, $table)) {
        $log[] = "表 {$table} 不存在，跳过可见性字段";
        continue;
    }
    foreach ($cols as $col => $sqlTpl) {
        if (hasColumn($pdo, $table, $col)) {
            $log[] = "{$table}.{$col} 已存在，跳过";
            continue;
        }
        $pdo->exec(sprintf($sqlTpl, $table));
        $log[] = "{$table} 新增 {$col}";
    }
}

// ---- 3. 默认等级 ----
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM `{$levelsTable}`")->fetchColumn();
if ($cnt === 0) {
    $ins = $pdo->prepare("INSERT INTO `{$levelsTable}` (`name`,`sort`,`color`,`description`,`is_default`,`created_at`) VALUES (?,?,?,?,?,?)");
    $ins->execute(['普通会员', 0, '#86909C', '默认等级，可访问公开资源', 1, $now]);
    $ins->execute(['VIP 会员', 10, '#E67E22', '可访问需要 VIP 等级的资源', 0, $now]);
    $ins->execute(['超级会员', 20, '#7C4DFF', '可访问全部等级资源', 0, $now]);
    $log[] = '写入 3 个默认等级';
} else {
    $log[] = "等级表已有 {$cnt} 条记录，跳过初始化";
}

// ---- 4. 新增设置项 ----
$settingsTable = $prefix . 'settings';
$defaults = [
    'allow_user_level_view' => 1,
    'hard_delete_confirm'   => 1,
    'hide_locked'           => 0,   // 1 = 等级不足的资源直接隐藏，0 = 显示但锁定
    'disk_guard_percent'    => 92,
    'login_lock_minutes'    => 60,  // 登录失败锁定时长（分钟）  // 磁盘使用率超过该值暂停上传
];
$ins2 = $pdo->prepare("INSERT INTO `{$settingsTable}` (`k`,`v`) VALUES (?,?) ON DUPLICATE KEY UPDATE `v` = `v`");
foreach ($defaults as $k => $v) {
    $ins2->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
$log[] = '补齐新增设置项';

echo "=== 迁移结果 ===\n";
foreach ($log as $l) {
    echo "  - {$l}\n";
}
echo "完成。\n";
