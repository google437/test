<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Two connection profiles:
 *  - owner: migrations and ingestion (table owner, bypasses RLS)
 *  - app:   web requests (non-owner role, RLS enforced, context set via set_config)
 */
final class DB
{
    private static ?DB $owner = null;
    private static ?DB $app = null;

    public function __construct(public readonly PDO $pdo) {}

    public static function owner(): DB
    {
        if (!self::$owner) {
            self::$owner = new DB(self::connect(Config::get('DATABASE_USER', 'nvvp'), Config::get('DATABASE_PASSWORD', '')));
        }
        return self::$owner;
    }

    public static function app(): DB
    {
        if (!self::$app) {
            $user = Config::get('APP_DB_USER');
            if ($user === null) {
                self::$app = self::owner();
            } else {
                self::$app = new DB(self::connect($user, Config::get('APP_DB_PASSWORD', '')));
            }
        }
        return self::$app;
    }

    public static function connect(?string $user, ?string $pass, ?string $dsn = null): PDO
    {
        $dsn = $dsn ?? Config::get('DATABASE_URL', 'pgsql:host=127.0.0.1;port=5432;dbname=nvvp');
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
        $pdo->exec("SET TIME ZONE '" . Config::get('TIMEZONE', 'America/Los_Angeles') . "'");
        return $pdo;
    }

    /** Set the tenant context used by row-level security policies. */
    public function setContext(?int $orgId, ?int $userId, bool $isStaff): void
    {
        $st = $this->pdo->prepare("SELECT set_config('app.org_id', :o, false), set_config('app.user_id', :u, false), set_config('app.is_staff', :s, false)");
        $st->execute([':o' => $orgId === null ? '' : (string) $orgId, ':u' => $userId === null ? '' : (string) $userId, ':s' => $isStaff ? '1' : '0']);
    }

    public function q(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $key = is_int($k) ? $k + 1 : $k;
            $type = match (true) {
                is_int($v) => PDO::PARAM_INT,
                is_bool($v) => PDO::PARAM_BOOL,
                $v === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $st->bindValue($key, $v, $type);
        }
        $st->execute();
        return $st;
    }

    public function all(string $sql, array $params = []): array
    {
        return $this->q($sql, $params)->fetchAll();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $r = $this->q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public function val(string $sql, array $params = []): mixed
    {
        $v = $this->q($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public function exec(string $sql, array $params = []): int
    {
        return $this->q($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data, string $returning = 'id'): mixed
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)%s', $table, implode(',', $cols),
            implode(',', array_map(fn($c) => ':' . $c, $cols)), $returning ? ' RETURNING ' . $returning : '');
        $st = $this->q($sql, array_combine(array_map(fn($c) => ':' . $c, $cols), array_values($data)));
        return $returning ? $st->fetchColumn() : null;
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = implode(', ', array_map(fn($c) => "$c = :set_$c", array_keys($data)));
        $params = $whereParams;
        foreach ($data as $k => $v) {
            $params[":set_$k"] = $v;
        }
        return $this->exec("UPDATE $table SET $sets WHERE $where", $params);
    }

    public function tx(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn($this);
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Builds "(:p0,:p1,...)" and merges params; for IN clauses. */
    public static function inList(array $values, array &$params, string $prefix): string
    {
        $keys = [];
        foreach (array_values($values) as $i => $v) {
            $k = ':' . $prefix . $i;
            $params[$k] = $v;
            $keys[] = $k;
        }
        return '(' . implode(',', $keys) . ')';
    }
}
