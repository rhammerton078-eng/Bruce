<?php
declare(strict_types=1);

namespace Atlas;

use Throwable;

/**
 * Makes ATLAS ready by itself when it opens:
 *  - installs the database if it is missing
 *  - repairs leftover, broken database files from an earlier install (MySQL errors 1813 / 1932 / 1146)
 *    in the main memory AND in memory cards
 *  - inserts the memory and the name that came with "Download my AI" (installed copies)
 */
final class Installer
{
    private const BROKEN = '/1813|1932|1146|Tablespace|doesn\'t exist in engine|DISCARD|Unknown database/i';

    public static function installed(): bool
    {
        try {
            $db = Db::get();
            foreach (['documents', 'settings', 'entities', 'chunks'] as $t) $db->val("SELECT 1 FROM `$t` LIMIT 1");
            return true;
        } catch (Throwable) { return false; }
    }

    /** Everything needed so the app just opens. */
    public static function run(): void
    {
        // 1. Active memory card broken? Remove it (its memory is re-inserted from the package below)
        $active = Cards::activeId();
        if ($active !== 'main' && !self::installed()) {
            self::removeCard($active);
            @unlink(self::packageDir() . '/.done');
        }
        // 2. Main memory database
        if (!self::installed()) {
            try {
                self::create();
            } catch (Throwable $e) {
                if (!preg_match(self::BROKEN, $e->getMessage())) throw $e;
                self::wipeDb(atlas_config()['db']['name']);
                self::create();
            }
        }
        // 3. Memory + name from "Download my AI"
        self::importPackage(self::packageDir());
    }

    /** Memory/name from "Download my AI" not inserted yet? */
    public static function pending(): bool
    {
        $p = self::packageDir();
        return is_file("$p/identity.json") && !is_file("$p/.done");
    }

    /** C:\ATLAS\package for an installed copy (src -> atlas -> htdocs -> xampp -> C:\ATLAS). */
    public static function packageDir(): string
    {
        return str_replace('\\', '/', dirname(__DIR__, 4)) . '/package';
    }

    /** Inserts memory.atlascard and sets the AI's name, once. Returns the name, or null when there is nothing to do. */
    public static function importPackage(string $pkg): ?string
    {
        $pkg = rtrim(str_replace('\\', '/', $pkg), '/');
        if ($pkg === '' || !is_file("$pkg/identity.json") || is_file("$pkg/.done")) return null;
        $id = json_decode((string)file_get_contents("$pkg/identity.json"), true) ?: [];
        $name = trim((string)($id['ai_name'] ?? 'Mnemo')) ?: 'Mnemo';
        if (is_file("$pkg/memory.atlascard")) {
            // An earlier, half-finished insert with the same name is replaced
            foreach (Cards::registry()['cards'] as $cid => $c) {
                if ($cid !== 'main' && ($c['name'] ?? '') === $name) self::removeCard($cid);
            }
            Cards::import("$pkg/memory.atlascard", $name);
            Db::reset();
            atlas_config(true);
        }
        $db = Db::get();
        $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', ['app.ai_name', $name]);
        $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', ['ollama.chat_model', 'mnemo:7b']);
        if (array_key_exists('vision', $id) && !$id['vision']) $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', ['ollama.vision_model', '']);
        @file_put_contents("$pkg/.done", date('c'));
        return $name;
    }

    private static function create(): void
    {
        $name = atlas_config()['db']['name'];
        $sql = (string)file_get_contents(__DIR__ . '/../sql/schema.sql');
        $sql = str_replace(['CREATE DATABASE IF NOT EXISTS atlas', 'USE atlas;'], ['CREATE DATABASE IF NOT EXISTS `' . $name . '`', 'USE `' . $name . '`;'], $sql);
        $pdo = Db::server()->pdo;
        foreach (array_filter(array_map('trim', preg_split('/;\s*\n/', preg_replace('/^--.*$/m', '', $sql)))) as $stmt) $pdo->exec($stmt);
        Db::reset();
        Schema::ensure(true);
    }

    /** Removes a memory card, even when its database files are broken. */
    private static function removeCard(string $id): void
    {
        try { Cards::remove($id); return; } catch (Throwable) {}
        self::wipeDb(Cards::dbFor($id));
        try { Cards::remove($id); } catch (Throwable) {}
        try { Cards::switch('main'); } catch (Throwable) {}
    }

    /** Drops a database and deletes its leftover files. */
    private static function wipeDb(string $name): void
    {
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
