<?php
/**
 * 数据库结构定义。由安装向导与 Schema::ensure() 共用，幂等。
 *
 * 设计原则：一次建全，后续版本只 ALTER 不重建。
 * - shares 表支撑「分享链接 + 提取码」
 * - files/folders 带 deleted_at 支撑「回收站」
 * - users 带 quota_period / quota_reset_at 支撑「配额按周期重置」
 */
declare(strict_types=1);

class Schema
{
    /** @return string[] 建表语句（含 {prefix} 占位） */
    public static function statements(): array
    {
        return [
            // 用户
            'users' => "CREATE TABLE IF NOT EXISTS `{prefix}users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(32) NOT NULL COMMENT '登录名',
  `email` VARCHAR(128) DEFAULT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `nickname` VARCHAR(64) NOT NULL DEFAULT '',
  `avatar` VARCHAR(255) NOT NULL DEFAULT '',
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '用户等级 0=无等级',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1正常 0禁用',
  `download_quota` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '周期内可下载次数 0=不限',
  `download_used` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '本周期已用次数',
  `quota_period` ENUM('total','daily','monthly') NOT NULL DEFAULT 'total',
  `quota_reset_at` DATETIME DEFAULT NULL COMMENT '下次配额重置时间',
  `storage_quota` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '字节 0=不限',
  `storage_used` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) NOT NULL DEFAULT '',
  `login_count` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 用户等级
            'levels' => "CREATE TABLE IF NOT EXISTS `{prefix}levels` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(32) NOT NULL,
  `sort` INT NOT NULL DEFAULT 0 COMMENT '数值越大等级越高',
  `color` VARCHAR(16) NOT NULL DEFAULT '#07C160',
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `is_default` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '新注册用户默认等级',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_sort` (`sort`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 文件夹（邻接表）
            'folders' => "CREATE TABLE IF NOT EXISTS `{prefix}folders` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `name` VARCHAR(128) NOT NULL,
  `slug` VARCHAR(128) NOT NULL DEFAULT '',
  `description` VARCHAR(255) NOT NULL DEFAULT '',
  `sort` INT NOT NULL DEFAULT 0,
  `is_public` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下载所需等级 0=不限',
  `view_level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '可见所需等级 0=不限',
  `login_required` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=仅登录用户可见',
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_parent` (`parent_id`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 资源文件
            'files' => "CREATE TABLE IF NOT EXISTS `{prefix}files` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `folder_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `name` VARCHAR(255) NOT NULL COMMENT '展示名',
  `orig_name` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `ext` VARCHAR(16) NOT NULL DEFAULT '',
  `mime` VARCHAR(128) NOT NULL DEFAULT 'application/octet-stream',
  `size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `storage_key` VARCHAR(128) NOT NULL DEFAULT '' COMMENT '物理相对路径',
  `sha256` CHAR(64) NOT NULL DEFAULT '' COMMENT '用于秒传与去重',
  `downloads` INT UNSIGNED NOT NULL DEFAULT 0,
  `is_public` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '下载所需等级 0=不限',
  `view_level_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '可见所需等级 0=不限',
  `login_required` TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '1=仅登录用户可见',
  `sort` INT NOT NULL DEFAULT 0,
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1正常 0隐藏',
  `uploader_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `deleted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_folder` (`folder_id`),
  KEY `idx_sha256` (`sha256`),
  KEY `idx_created` (`created_at`),
  KEY `idx_deleted` (`deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 下载记录
            'downloads' => "CREATE TABLE IF NOT EXISTS `{prefix}downloads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=游客',
  `share_id` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '经分享链接下载时记录',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `ua` VARCHAR(255) NOT NULL DEFAULT '',
  `bytes_sent` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('pending','done','aborted') NOT NULL DEFAULT 'pending',
  `created_at` DATETIME NOT NULL,
  `finished_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_file` (`file_id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 登录记录
            'login_logs' => "CREATE TABLE IF NOT EXISTS `{prefix}login_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `username` VARCHAR(32) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `ua` VARCHAR(255) NOT NULL DEFAULT '',
  `status` ENUM('ok','fail','locked') NOT NULL DEFAULT 'ok',
  `msg` VARCHAR(128) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_user_time` (`user_id`, `created_at`),
  KEY `idx_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 分享链接
            'shares' => "CREATE TABLE IF NOT EXISTS `{prefix}shares` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `file_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `token` CHAR(16) NOT NULL COMMENT 'URL 中的短标识',
  `code` VARCHAR(16) NOT NULL DEFAULT '' COMMENT '提取码，空=不需要',
  `max_downloads` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0=不限',
  `downloads` INT UNSIGNED NOT NULL DEFAULT 0,
  `expires_at` DATETIME DEFAULT NULL COMMENT 'NULL=永久',
  `status` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_file` (`file_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 系统设置 KV
            'settings' => "CREATE TABLE IF NOT EXISTS `{prefix}settings` (
  `k` VARCHAR(64) NOT NULL,
  `v` TEXT NOT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",

            // 后台操作审计
            'admin_logs' => "CREATE TABLE IF NOT EXISTS `{prefix}admin_logs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `admin_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `action` VARCHAR(64) NOT NULL DEFAULT '',
  `target_type` VARCHAR(32) NOT NULL DEFAULT '',
  `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
  `detail` VARCHAR(500) NOT NULL DEFAULT '',
  `ip` VARCHAR(45) NOT NULL DEFAULT '',
  `created_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_admin_time` (`admin_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
        ];
    }

    /** 默认设置 */
    public static function defaultSettings(): array
    {
        return [
            'site_name'              => '云盘',
            'site_desc'              => '简洁、快速的资源分享',
            'site_url'               => '',
            'site_logo'              => '',
            'icp'                    => '',
            'theme_color'            => '#07C160',
            'require_login_download' => 0,
            'guest_visible'          => 1,
            'allow_register'         => 1,
            'default_download_quota' => 0,
            'default_quota_period'   => 'total',
            'default_storage_quota'  => 0,
            'max_upload_size'        => 2147483648,
            'ext_blacklist'          => 'php,php3,php4,php5,phtml,phar,exe,bat,cmd,sh,so,dll,jsp,asp,aspx',
            'page_size'              => 20,
            'sort_default'           => 'new',
            'maintenance_mode'       => 0,
            'login_lock_minutes'  => 60,
  'login_fail_limit'       => 5,
            'share_enabled'          => 1,
            'recycle_days'           => 30,
            'chunk_size'             => 2097152,
        ];
    }

    /**
     * 建表（幂等）。返回创建的表名列表。
     * @param PDO $pdo
     */
    public static function ensure(PDO $pdo, string $prefix = ''): array
    {
        $done = [];
        foreach (self::statements() as $name => $sql) {
            $pdo->exec(str_replace('{prefix}', $prefix, $sql));
            $done[] = $prefix . $name;
        }
        return $done;
    }

    public static function prefix(): string
    {
        return (string) Config::get('db.prefix', '');
    }

    /** 运行时补齐缺失表（升级用） */
    public static function ensureInstalled(): void
    {
        $tables = self::ensure(Db::conn(), self::prefix());
        foreach (self::defaultSettings() as $k => $v) {
            $exists = Db::val('SELECT 1 FROM ' . Db::t('settings') . ' WHERE `k` = ?', [$k]);
            if ($exists === null) {
                setting_set($k, $v);
            }
        }
    }
}
