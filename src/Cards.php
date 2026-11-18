<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * Memory cards. Each card is one MySQL database plus its own files folder.
 * Insert a card file and Mnemo knows everything on it; eject it and that knowledge is gone.
 * Exactly one card is active at a time.
 *
 * Registry: storage/cards.json   (the built-in "main" card uses the database from config.php)
 */
final class Cards
{
    private static ?string $override = null;

    private static function cfg(): array { return require __DIR__ . '/../config.php'; }
    public static function root(): string { return self::cfg()['storage']; }
    private static function file(): string { return self::root() . '/cards.json'; }

    public static function registry(): array
    {
        $reg = is_file(self::file()) ? (json_decode((string)file_get_contents(self::file()), true) ?: []) : [];
        $reg['cards'] ??= [];
        if (!isset($reg['cards']['main'])) $reg['cards'] = ['main' => ['name' => 'Main memory', 'created' => date('Y-m-d H:i:s')]] + $reg['cards'];
        if (!isset($reg['cards'][$reg['active'] ?? ''])) $reg['active'] = 'main';
        return $reg;
    }

    private static function save(array $reg): void
    {
        if (file_put_contents(self::file(), json_encode($reg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX) === false) {
            throw new RuntimeException('Cannot write storage/cards.json (is the storage folder writable?)');
        }
    }

    public static function activeId(): string { return self::$override ?? self::registry()['active']; }

    public static function dbFor(string $id): string
    {
        $base = self::cfg()['db']['name'];
        return $id === 'main' ? $base : $base . '_c_' . $id;
    }

    public static function dirFor(string $id): string
    {
        return $id === 'main' ? self::root() : self::root() . '/cards/' . $id;
    }

    public static function activeDb(): string { return self::dbFor(self::activeId()); }
    public static function activeDir(): string { return self::dirFor(self::activeId()); }

    /** Runs $fn with another card temporarily active (for export/import), then restores the previous one. */
    public static function with(string $id, callable $fn): mixed
    {
        $prev = self::$override;
        self::$override = $id;
        Db::reset(); atlas_config(true);
        try { return $fn(); }
        finally { self::$override = $prev; Db::reset(); atlas_config(true); }
    }

    private static function stats(string $id): array
    {
        $db = self::dbFor($id);
        try {
            $pdo = Db::server()->pdo;
            $q = fn(string $sql) => (int)$pdo->query($sql)->fetchColumn();
            return [
                'sources' => $q("SELECT COUNT(*) FROM `$db`.documents WHERE sha256 <> 'taught-knowledge'"),
                'concepts' => $q("SELECT COUNT(*) FROM `$db`.entities"),
                'links' => $q("SELECT COUNT(*) FROM `$db`.relations"),
                'taught' => $q("SELECT COUNT(*) FROM `$db`.user_facts"),
                'db_bytes' => $q("SELECT COALESCE(SUM(DATA_LENGTH + INDEX_LENGTH), 0) FROM information_schema.TABLES WHERE TABLE_SCHEMA = " . $pdo->quote($db)),
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Database missing or unreadable'];
        }
    }

    public static function list(): array
    {
        $reg = self::registry();
        $out = [];
        foreach ($reg['cards'] as $id => $c) {
            $out[] = ['id' => (string)$id, 'name' => $c['name'], 'created' => $c['created'] ?? null, 'active' => (string)$id === $reg['active'], 'builtin' => $id === 'main'] + self::stats((string)$id);
        }
        return $out;
    }

    public static function manifest(): array
    {
        $id = self::activeId();
        $reg = self::registry();
        return ['format' => 'atlas-card', 'version' => 1, 'schema' => Schema::VERSION, 'name' => $reg['cards'][$id]['name'] ?? 'Memory card',
                'created' => $reg['cards'][$id]['created'] ?? null, 'exported' => date('c')] + self::stats($id);
    }

    /** Creates a blank card (zero knowledge). */
    public static function create(string $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', $name) ?? '');
        if ($name === '') throw new RuntimeException('Give the card a name');
        $reg = self::registry();
        $slug = substr(preg_replace('/[^a-z0-9]+/', '', strtolower($name)) ?: 'card', 0, 16);
        do { $id = $slug . substr(bin2hex(random_bytes(3)), 0, 5); } while (isset($reg['cards'][$id]));
        $db = self::dbFor($id);
        try {
            $pdo = Db::server()->pdo;
            $pdo->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$db`");
            Schema::install($pdo);
        } catch (\PDOException $e) {
            throw new RuntimeException('Could not create the card database. If ATLAS uses a dedicated MySQL user, re-run "Create & use it" in setup.php so it may create card databases. (' . $e->getMessage() . ')');
        }
        foreach (['uploads', 'backups'] as $d) @mkdir(self::dirFor($id) . '/' . $d, 0775, true);
        $reg['cards'][$id] = ['name' => mb_substr($name, 0, 60), 'created' => date('Y-m-d H:i:s')];
        self::save($reg);
        return $id;
    }

    public static function switch(string $id): void
    {
        $reg = self::registry();
        if (!isset($reg['cards'][$id])) throw new RuntimeException('Card not found');
        $reg['active'] = $id;
        self::save($reg);
        Db::reset(); atlas_config(true);
    }

    public static function rename(string $id, string $name): void
    {
        $reg = self::registry();
        if (!isset($reg['cards'][$id])) throw new RuntimeException('Card not found');
        $name = trim($name);
        if ($name === '') throw new RuntimeException('Name required');
        $reg['cards'][$id]['name'] = mb_substr($name, 0, 60);
        self::save($reg);
    }

    /** Removes a card from this PC. The built-in card is emptied instead of dropped. */
    public static function remove(string $id): void
    {
        $reg = self::registry();
        if (!isset($reg['cards'][$id])) throw new RuntimeException('Card not found');
        if ($id === 'main') {
            self::with('main', fn() => (new Maintenance())->wipe('everything', false));
            $reg['cards']['main'] = ['name' => 'Main memory', 'created' => date('Y-m-d H:i:s')];
            self::save($reg);
            return;
        }
        Db::server()->pdo->exec('DROP DATABASE IF EXISTS `' . self::dbFor($id) . '`');
        self::rmTree(self::dirFor($id));
        unset($reg['cards'][$id]);
        if ($reg['active'] === $id) $reg['active'] = 'main';
        self::save($reg);
        Db::reset(); atlas_config(true);
    }

    private static function rmTree(string $dir): void
    {
        if (!is_dir($dir) || !str_starts_with(realpath($dir) ?: '', realpath(self::root()) . DIRECTORY_SEPARATOR . 'cards')) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ card files (.atlascard)

    private static function exportDir(): string
    {
        $d = self::root() . '/exports';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        foreach (glob("$d/*.atlascard") ?: [] as $f) if (filemtime($f) < time() - 3600) @unlink($f); // tidy old exports
        return $d;
    }

    /** Writes a card file for download. Returns [token, filename]. */
    public static function export(string $id, ?string $password = null): array
    {
        $reg = self::registry();
        if (!isset($reg['cards'][$id])) throw new RuntimeException('Card not found');
        $token = bin2hex(random_bytes(12));
        $path = self::exportDir() . "/$token.atlascard";
        self::with($id, function () use ($path, $password) {
            $m = new Maintenance();
            $zipName = $m->backup(true, 'card', self::manifest(), $password ?: null);
            rename($m->backupPath($zipName), $path);
        });
        $nice = (preg_replace('/[^A-Za-z0-9 _-]+/', '', $reg['cards'][$id]['name']) ?: 'memory') . ' ' . date('Y-m-d') . '.atlascard';
        return [$token, $nice];
    }

    public static function exportPath(string $token): string
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $token)) throw new RuntimeException('Invalid token');
        $p = self::exportDir() . "/$token.atlascard";
        if (!is_file($p)) throw new RuntimeException('Export expired, please eject again');
        return $p;
    }

    /** Reads a card file's label without inserting it. */
    public static function inspect(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('This is not a valid card or backup file');
        $raw = $zip->getFromName('card.json');
        $hasDb = $zip->locateName('database.sql.gz') !== false || $zip->locateName('database.sql') !== false;
        $st = $zip->statName('database.sql.gz') ?: $zip->statName('database.sql');
        $zip->close();
        if (!$hasDb) throw new RuntimeException('This file does not contain an ATLAS memory');
        $m = $raw ? (json_decode($raw, true) ?: []) : [];
        return ['name' => $m['name'] ?? 'Imported memory', 'manifest' => $m, 'encrypted' => (bool)(($st['encryption_method'] ?? 0) !== 0)];
    }

    /** Inserts a card file as a new card and makes it active. */
    public static function import(string $path, ?string $name = null, ?string $password = null): string
    {
        $info = self::inspect($path);
        if ($info['encrypted'] && ($password === null || $password === '')) throw new RuntimeException('This card is password-protected. Enter its password.');
        $id = self::create($name ?: $info['name']);
        try {
            self::with($id, fn() => (new Maintenance())->restore($path, 'card.zip', false, $password ?: null));
        } catch (\Throwable $e) {
            self::remove($id);
            throw new RuntimeException(str_contains($e->getMessage(), 'password') || $info['encrypted'] ? 'Could not read the card: wrong password?' : $e->getMessage());
        }
        self::switch($id);
        return $id;
    }
}
