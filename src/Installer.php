<?php
declare(strict_types=1);

namespace Atlas;

use Throwable;

/**
 * Installs the ATLAS database by itself. If old, broken database files are in the way
 * (left behind by an earlier install: MySQL errors 1813 / 1932 / 1146), they are removed first.
 */
final class Installer
{
    public static function installed(): bool
    {
        try { Db::get()->val('SELECT 1 FROM documents LIMIT 1'); return true; } catch (Throwable) { return false; }
    }

    /** Creates the database and tables. Repairs leftover files once if needed. */
    public static function run(): void
    {
        try {
            self::create();
        } catch (Throwable $e) {
            if (!preg_match('/1813|1932|1146|Tablespace|doesn\'t exist in engine|DISCARD/i', $e->getMessage())) throw $e;
            self::repair();
            self::create();
        }
    }

    private static function create(): void
    {
        $cfg = atlas_config();
        $name = $cfg['db']['name'];
        $sql = (string)file_get_contents(__DIR__ . '/../sql/schema.sql');
        $sql = str_replace(['CREATE DATABASE IF NOT EXISTS atlas', 'USE atlas;'], ['CREATE DATABASE IF NOT EXISTS `' . $name . '`', 'USE `' . $name . '`;'], $sql);
        $pdo = Db::server()->pdo;
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) $pdo->exec($stmt);
        Db::reset();
        Schema::ensure(true);
    }

    /** Drops the database and deletes its leftover files (memory cards in other databases are not touched). */
    private static function repair(): void
    {
        $name = atlas_config()['db']['name'];
        $pdo = Db::server()->pdo;
        $dir = rtrim(str_replace('\\', '/', (string)$pdo->query('SELECT @@datadir')->fetchColumn()), '/') . '/' . $name;
        try { $pdo->exec('DROP DATABASE IF EXISTS `' . $name . '`'); } catch (Throwable) {}
        if (is_dir($dir)) {
            foreach (glob("$dir/*") ?: [] as $f) if (is_file($f)) @unlink($f);
            @rmdir($dir);
        }
        try { $pdo->exec('DROP DATABASE IF EXISTS `' . $name . '`'); } catch (Throwable) {}
        Db::reset();
    }
}
