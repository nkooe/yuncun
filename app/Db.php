<?php
/**
 * PDO 单例与查询助手。全部走预处理，杜绝 SQL 注入。
 */
declare(strict_types=1);

class Db
{
    private static ?PDO $pdo = null;

    public static function dsn(): string
    {
        $host = Config::get('db.host', '127.0.0.1');
        $port = (int) Config::get('db.port', 3306);
        $name = Config::get('db.name', '');
        $cs   = Config::get('db.charset', 'utf8mb4');
        return "mysql:host={$host};port={$port};dbname={$name};charset={$cs}";
    }

    public static function conn(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }
        $opt = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];
        self::$pdo = new PDO(
            self::dsn(),
            (string) Config::get('db.user', ''),
            (string) Config::get('db.pass', ''),
            $opt
        );
        return self::$pdo;
    }

    /** 表名（自动加前缀） */
    public static function t(string $table): string
    {
        $prefix = (string) Config::get('db.prefix', '');
        return '`' . $prefix . $table . '`';
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function exec(string $sql, array $params = []): int
    {
        return self::q($sql, $params)->rowCount();
    }

    /** 插入一行，返回自增 ID */
    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $fields = implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols));
        $marks  = implode(', ', array_fill(0, count($cols), '?'));
        $sql = 'INSERT INTO ' . self::t($table) . " ({$fields}) VALUES ({$marks})";
        self::q($sql, array_values($data));
        return (int) self::conn()->lastInsertId();
    }

    /** 按主键更新 */
    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', array_keys($data)));
        $sql = 'UPDATE ' . self::t($table) . " SET {$sets} WHERE {$where}";
        return self::exec($sql, array_merge(array_values($data), $whereParams));
    }

    public static function begin(): void    { self::conn()->beginTransaction(); }
    public static function commit(): void   { self::conn()->commit(); }
    public static function rollback(): void
    {
        if (self::conn()->inTransaction()) {
            self::conn()->rollBack();
        }
    }
}
