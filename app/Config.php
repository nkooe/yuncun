<?php
/**
 * 配置读写。data/config.php 由安装向导生成。
 */
declare(strict_types=1);

class Config
{
    private static ?array $data = null;
    private static bool $dirty = false;

    public static function path(): string
    {
        return DATA_PATH . '/config.php';
    }

    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $file = self::path();
        if (!is_file($file)) {
            self::$data = [];
            return self::$data;
        }
        $loaded = require $file;
        self::$data = is_array($loaded) ? $loaded : [];
        return self::$data;
    }

    /** 点号取配置：Config::get('db.host') */
    public static function get(string $key, mixed $default = null): mixed
    {
        $data = self::load();
        $node = $data;
        foreach (explode('.', $key) as $seg) {
            if (!is_array($node) || !array_key_exists($seg, $node)) {
                return $default;
            }
            $node = $node[$seg];
        }
        return $node;
    }

    /** 点号写配置（内存） */
    public static function set(string $key, mixed $value): void
    {
        $data = self::load();
        $ref = &$data;
        $segs = explode('.', $key);
        $last = array_pop($segs);
        foreach ($segs as $seg) {
            if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                $ref[$seg] = [];
            }
            $ref = &$ref[$seg];
        }
        $ref[$last] = $value;
        self::$data = $data;
        self::$dirty = true;
    }

    /** 落盘。使用临时文件 + rename 保证原子性。 */
    public static function save(): bool
    {
        if (self::$data === null) {
            return false;
        }
        $body = "<?php\n"
              . "// 由安装向导生成，手工修改请保持数组结构。\n"
              . "if (!defined('APP_ROOT')) { http_response_code(403); exit('Access denied'); }\n"
              . "return " . var_export(self::$data, true) . ";\n";
        $file = self::path();
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0755, true);
        }
        $tmp = $file . '.tmp' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $body) === false) {
            return false;
        }
        @chmod($tmp, 0640);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            return false;
        }
        self::$dirty = false;
        return true;
    }
}
