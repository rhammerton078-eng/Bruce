<?php
declare(strict_types=1);

namespace Atlas;

/** Keeps the database schema up to date (safe to run repeatedly). */
final class Schema
{
    public const VERSION = 3;

    public static function statements(): array
    {
        $sql = (string)file_get_contents(__DIR__ . '/../sql/schema.sql');
        $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
        $out = [];
        foreach (preg_split('/;\s*\n/', $sql) ?: [] as $st) {
            $st = trim($st);
            if ($st === '' || stripos($st, 'CREATE DATABASE') === 0 || stripos($st, 'USE ') === 0) continue;
            $out[] = $st;
        }
        return $out;
    }

    /** Creates the full, current schema on a fresh database (used for new memory cards). */
    public static function install(\PDO $pdo): void
    {
        foreach (self::statements() as $st) $pdo->exec($st);
        $pdo->exec("REPLACE INTO settings (k, v) VALUES ('schema_version', '" . self::VERSION . "')");
    }

    public static function ensure(bool $force = false): bool
    {
        $db = Db::get();
        $cur = (int)($db->val("SELECT v FROM settings WHERE k = 'schema_version'") ?? 1);
        if (!$force && $cur >= self::VERSION) return false;
        foreach (self::statements() as $st) $db->pdo->exec($st);
        // column-level migrations (portable: check information_schema first)
        $hasCol = fn(string $t, string $c) => (bool)$db->val('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$t, $c]);
        $db->pdo->exec("ALTER TABLE documents MODIFY kind ENUM('document','image','audio') NOT NULL DEFAULT 'document'");
        if (!$hasCol('documents', 'phash')) $db->pdo->exec('ALTER TABLE documents ADD COLUMN phash CHAR(16) NULL AFTER kind');
        $db->run("REPLACE INTO settings (k, v) VALUES ('schema_version', ?)", [(string)self::VERSION]);
        return true;
    }
}
