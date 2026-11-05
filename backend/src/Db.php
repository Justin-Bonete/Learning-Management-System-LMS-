<?php
declare(strict_types=1);
namespace App;

use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        return self::$pdo ??= new PDO(
            sprintf('pgsql:host=%s;port=5432;dbname=%s', getenv('DB_HOST') ?: 'db', getenv('DB_NAME') ?: 'lms'),
            getenv('DB_USER') ?: 'lms',
            getenv('DB_PASSWORD') ?: '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]
        );
    }

    private static function exec(string $sql, array $p): \PDOStatement
    {
        $p = array_map(fn($v) => is_bool($v) ? ($v ? 't' : 'f') : $v, array_values($p));
        $s = self::pdo()->prepare($sql);
        $s->execute($p);
        return $s;
    }

    public static function all(string $sql, array $p = []): array { return self::exec($sql, $p)->fetchAll(); }

    public static function one(string $sql, array $p = []): ?array
    {
        $r = self::exec($sql, $p)->fetch();
        return $r === false ? null : $r;
    }

    public static function run(string $sql, array $p = []): int { return self::exec($sql, $p)->rowCount(); }

    public static function tx(callable $f): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $r = $f();
            $pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    public static function isDuplicate(\Throwable $e): bool { return $e instanceof \PDOException && $e->getCode() === '23505'; }
}
