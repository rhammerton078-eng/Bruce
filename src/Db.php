<?php
declare(strict_types=1);

namespace Atlas;

use PDO;

final class Db
{
    private static ?Db $inst = null;
    public PDO $pdo;

    private function __construct(bool $withDb = true)
    {
        $c = (require __DIR__ . '/../config.php')['db'];
        $dsn = "mysql:host={$c['host']};port={$c['port']};charset=utf8mb4" . ($withDb ? ';dbname=' . Cards::activeDb() : '');
        $this->pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }

    public static function get(): Db
    {
        return self::$inst ??= new Db();
    }

    /** Forget the current connection (used when the active memory card changes). */
    public static function reset(): void
    {
        self::$inst = null;
    }

    public static function server(): Db
    {
        return new Db(false);
    }

    public function run(string $sql, array $p = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($p);
        return $st;
    }

    public function all(string $sql, array $p = []): array { return $this->run($sql, $p)->fetchAll(); }
    public function one(string $sql, array $p = []): ?array { $r = $this->run($sql, $p)->fetch(); return $r ?: null; }
    public function val(string $sql, array $p = []): mixed { $r = $this->run($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
    public function id(): int { return (int)$this->pdo->lastInsertId(); }

    public function log(string $event, ?int $docId = null, ?int $entityId = null, ?string $detail = null): void
    {
        $this->run('INSERT INTO ingest_log (event, document_id, entity_id, detail) VALUES (?,?,?,?)',
            [$event, $docId, $entityId, $detail !== null ? mb_substr($detail, 0, 500) : null]);
    }
}
