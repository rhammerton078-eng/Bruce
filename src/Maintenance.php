<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * Memory management: storage monitoring, limits, backups (download/restore),
 * wiping memory, re-embedding and index maintenance. All data lives in MySQL.
 */
final class Maintenance
{
    public const WIPE_SCOPES = [
        'conversations' => 'Chat history',
        'taught' => 'Facts taught by you',
        'learning' => 'Self-learning data: approved answers, benchmark tests, self-quiz, flashcards, watchlist',
        'graph' => 'Knowledge graph (concepts & links), sources kept and re-learned',
        'everything' => 'Everything: all sources, files, graph, chats and history',
    ];

    private Db $db;
    private string $storage;

    public function __construct()
    {
        $this->db = Db::get();
        $this->storage = atlas_config()['storage'];
    }

    public static function setting(string $k, string $default): string
    {
        try { return (string)(Db::get()->val('SELECT v FROM settings WHERE k = ?', [$k]) ?? $default); } catch (\Throwable) { return $default; }
    }

    private static function iniBytes(string $v): int
    {
        $v = trim($v);
        if ($v === '' || $v === '-1') return -1;
        $n = (float)$v;
        return (int)match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    }

    public static function dirSize(string $dir): int
    {
        if (!is_dir($dir)) return 0;
        $size = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $size += $f->getSize();
        return $size;
    }

    /** Effective max upload size in bytes (smallest of PHP limits and ATLAS setting). */
    public static function uploadLimit(): int
    {
        $limits = array_filter([
            self::iniBytes((string)ini_get('upload_max_filesize')),
            self::iniBytes((string)ini_get('post_max_size')),
            (int)self::setting('storage.max_upload_mb', '256') * 1048576,
        ], fn($x) => $x > 0);
        return $limits ? min($limits) : 256 * 1048576;
    }

    /** Full storage report with warnings. */
    public function storage(bool $quick = false): array
    {
        $dbName = Cards::activeDb();
        $tables = $this->db->all('SELECT TABLE_NAME AS name, TABLE_ROWS AS rows_est, DATA_LENGTH AS data, INDEX_LENGTH AS idx, DATA_FREE AS free
            FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC', [$dbName]);
        $dbBytes = 0;
        foreach ($tables as &$t) {
            $t['rows'] = (int)$t['rows_est'];
            $t['bytes'] = (int)$t['data'] + (int)$t['idx'];
            $dbBytes += $t['bytes'];
            unset($t['rows_est'], $t['data'], $t['idx']);
        }
        unset($t);
        $uploads = $quick ? 0 : self::dirSize($this->storage . '/uploads');
        $backups = $quick ? 0 : self::dirSize($this->storage . '/backups');
        $diskFree = (int)(@disk_free_space($this->storage) ?: 0);
        $diskTotal = (int)(@disk_total_space($this->storage) ?: 0);

        $vars = [];
        foreach ($this->db->all("SHOW VARIABLES WHERE Variable_name IN ('max_allowed_packet','innodb_buffer_pool_size','innodb_ft_min_token_size','innodb_log_file_size','character_set_server','version','datadir','innodb_file_per_table')") as $r) {
            $vars[$r['Variable_name']] = $r['Value'];
        }

        $warnDbMb = (int)self::setting('storage.warn_db_mb', '2048');
        $critDbMb = (int)self::setting('storage.crit_db_mb', '8192');
        $warnDiskMb = (int)self::setting('storage.warn_disk_free_mb', '5120');
        $critDiskMb = (int)self::setting('storage.crit_disk_free_mb', '1024');

        $warnings = [];
        $level = 'ok';
        $raise = function (string $lvl, string $msg) use (&$warnings, &$level) {
            $warnings[] = ['level' => $lvl, 'message' => $msg];
            if ($lvl === 'critical' || ($lvl === 'warn' && $level === 'ok')) $level = $lvl;
        };
        $mb = fn(int $b) => round($b / 1048576);
        if ($dbBytes >= $critDbMb * 1048576) $raise('critical', "Database is {$mb($dbBytes)} MB, above your critical limit of $critDbMb MB. Download a backup and consider forgetting old sources.");
        elseif ($dbBytes >= $warnDbMb * 1048576) $raise('warn', "Database is {$mb($dbBytes)} MB, above your warning level of $warnDbMb MB.");
        if ($diskTotal && $diskFree < $critDiskMb * 1048576) $raise('critical', "Only {$mb($diskFree)} MB of disk space left. New uploads are paused until you free space.");
        elseif ($diskTotal && $diskFree < $warnDiskMb * 1048576) $raise('warn', "Disk space is getting low: {$mb($diskFree)} MB free.");

        $packet = (int)($vars['max_allowed_packet'] ?? 0);
        if ($packet && $packet < 16 * 1048576) $raise('warn', 'MySQL max_allowed_packet is ' . $mb($packet) . ' MB. Set it to 64M in my.ini so large documents can be saved.');
        if ((int)($vars['innodb_ft_min_token_size'] ?? 3) > 2) $raise('info', 'Short words like "AI" are not keyword-searchable. Set innodb_ft_min_token_size=2 in my.ini, restart MySQL, then click "Rebuild search index".');
        $upl = self::uploadLimit();
        if ($upl < 32 * 1048576) $raise('warn', 'PHP only accepts uploads up to ' . $mb($upl) . ' MB. Raise upload_max_filesize and post_max_size to 128M in php.ini.');
        $lastBackup = $quick ? null : ($this->listBackups()[0]['created'] ?? null);
        if (!$quick && $dbBytes > 0 && (!$lastBackup || strtotime($lastBackup) < time() - 7 * 86400)) $raise('info', $lastBackup ? 'Your last backup is more than 7 days old.' : 'You have no backups yet. Download one so Mnemo\'s memory is safe.');

        return [
            'level' => $level,
            'warnings' => $warnings,
            'db_bytes' => $dbBytes,
            'uploads_bytes' => $uploads,
            'backups_bytes' => $backups,
            'disk_free' => $diskFree,
            'disk_total' => $diskTotal,
            'tables' => $tables,
            'thresholds' => ['warn_db_mb' => $warnDbMb, 'crit_db_mb' => $critDbMb, 'warn_disk_free_mb' => $warnDiskMb, 'crit_disk_free_mb' => $critDiskMb,
                             'max_upload_mb' => (int)self::setting('storage.max_upload_mb', '256'), 'backup_keep' => (int)self::setting('backup.keep', '14')],
            'limits' => [
                'upload_effective' => $upl,
                'php' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'),
                          'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')],
                'mysql' => $vars,
            ],
            'last_backup' => $lastBackup,
        ];
    }

    /** True when uploads must be refused (critical disk space). */
    public function uploadsBlocked(): ?string
    {
        $free = (int)(@disk_free_space($this->storage) ?: 0);
        $crit = (int)self::setting('storage.crit_disk_free_mb', '1024');
        if ($free && $free < $crit * 1048576) return 'Uploads paused: less than ' . $crit . ' MB disk space left. Free some space or forget old sources.';
        return null;
    }

    // ------------------------------------------------------------------ backups

    private function backupDir(): string
    {
        $d = $this->storage . '/backups';
        if (!is_dir($d)) @mkdir($d, 0775, true);
        return $d;
    }

    public function listBackups(): array
    {
        $out = [];
        foreach (glob($this->backupDir() . '/atlas-*.{sql.gz,zip}', GLOB_BRACE) ?: [] as $f) {
            $out[] = ['file' => basename($f), 'bytes' => filesize($f), 'created' => date('Y-m-d H:i:s', filemtime($f)),
                      'with_files' => str_ends_with($f, '.zip')];
        }
        usort($out, fn($a, $b) => strcmp($b['created'], $a['created']));
        return $out;
    }

    public function backupPath(string $file): string
    {
        if (!preg_match('/^atlas-[\w.-]+\.(sql\.gz|zip)$/', $file)) throw new RuntimeException('Invalid backup name');
        $p = $this->backupDir() . '/' . $file;
        if (!is_file($p)) throw new RuntimeException('Backup not found');
        return $p;
    }

    /** Pure-PHP SQL dump (no mysqldump needed). Returns the backup filename. */
    public function backup(bool $withFiles = false, string $label = 'manual', ?array $manifest = null, ?string $password = null): string
    {
        @set_time_limit(0);
        $stamp = date('Ymd-His');
        $label = preg_replace('/[^a-z0-9]+/i', '', $label) ?: 'manual';
        $base = "atlas-$stamp-$label" . ($withFiles ? '-full' : '');
        for ($n = 2; glob($this->backupDir() . "/$base.*"); $n++) $base = "atlas-$stamp-$label" . ($withFiles ? '-full' : '') . "-$n";
        $sqlFile = $this->backupDir() . "/$base" . ($withFiles ? '.tmp' : '') . '.sql.gz';
        $gz = gzopen($sqlFile, 'wb6');
        if (!$gz) throw new RuntimeException('Cannot write backup file (is storage/backups writable?)');
        $pdo = $this->db->pdo;
        gzwrite($gz, "-- ATLAS / Mnemo memory backup\n-- Created " . date('c') . "\n-- Restore from ATLAS > Memory > Restore, or: mysql -u root atlas < file.sql\n"
            . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\n\n");
        $tables = array_column($this->db->all("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"), 'TABLE_NAME');
        foreach ($tables as $t) {
            $create = $this->db->one("SHOW CREATE TABLE `$t`")['Create Table'];
            gzwrite($gz, "DROP TABLE IF EXISTS `$t`;\n" . str_replace("\n", ' ', $create) . ";\n");
            $cols = $this->db->all("SHOW COLUMNS FROM `$t`");
            $blob = [];
            foreach ($cols as $c) $blob[$c['Field']] = (bool)preg_match('/blob|binary/i', $c['Type']);
            $st = $pdo->query("SELECT * FROM `$t`", \PDO::FETCH_ASSOC);
            $batch = [];
            $flush = function () use (&$batch, $gz, $t) {
                if ($batch) { gzwrite($gz, "INSERT INTO `$t` VALUES " . implode(',', $batch) . ";\n"); $batch = []; }
            };
            $size = 0;
            while ($row = $st->fetch()) {
                $vals = [];
                foreach ($row as $k => $v) {
                    if ($v === null) $vals[] = 'NULL';
                    elseif ($blob[$k]) $vals[] = $v === '' ? "''" : '0x' . bin2hex($v);
                    else $vals[] = $pdo->quote((string)$v);
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line;
                $size += strlen($line);
                if ($size > 512 * 1024) { $flush(); $size = 0; }
            }
            $flush();
            gzwrite($gz, "\n");
        }
        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n");
        gzclose($gz);

        $final = basename($sqlFile);
        if ($withFiles) {
            $zipFile = $this->backupDir() . "/$base.zip";
            $zip = new \ZipArchive();
            if ($zip->open($zipFile, \ZipArchive::CREATE) !== true) throw new RuntimeException('Cannot create zip');
            $zip->addFile($sqlFile, 'database.sql.gz');
            $up = $this->storage . '/uploads';
            if (is_dir($up)) {
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($up, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) if ($f->isFile()) $zip->addFile($f->getPathname(), 'uploads/' . str_replace('\\', '/', substr($f->getPathname(), strlen($up) + 1)));
            }
            $zip->addFromString('README.txt', "ATLAS memory card / full backup.\nInsert it in ATLAS > Memory > Memory cards, or restore it in Memory > Restore.\n");
            if ($manifest) $zip->addFromString('card.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            if ($password !== null && $password !== '') {
                if (!defined('ZipArchive::EM_AES_256')) throw new RuntimeException('This PHP build cannot encrypt card files. Export without a password.');
                $zip->setPassword($password);
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $n = $zip->getNameIndex($i);
                    if ($n !== 'card.json' && $n !== 'README.txt') $zip->setEncryptionName($n, \ZipArchive::EM_AES_256); // the label stays readable
                }
            }
            $zip->close();
            @unlink($sqlFile);
            $final = basename($zipFile);
        }
        $this->db->log('backup_created', null, null, $final);
        $this->prune();
        return $final;
    }

    /** Keeps only the newest N automatic backups (manual ones are kept). */
    public function prune(): void
    {
        $keep = max(1, (int)self::setting('backup.keep', '14'));
        $auto = array_values(array_filter($this->listBackups(), fn($b) => str_contains($b['file'], '-auto') || str_contains($b['file'], '-prewipe') || str_contains($b['file'], '-prerestore')));
        foreach (array_slice($auto, $keep) as $b) @unlink($this->backupDir() . '/' . $b['file']);
    }

    public function deleteBackup(string $file): void
    {
        @unlink($this->backupPath($file));
    }

    /** Restores an ATLAS backup (.sql, .sql.gz or full .zip). A safety backup is made first. */
    public function restore(string $path, string $origName, bool $safetyBackup = true, ?string $password = null): array
    {
        @set_time_limit(0);
        $safety = $safetyBackup ? $this->backup(false, 'prerestore') : null;
        $tmpSql = null;
        $restoredFiles = 0;
        if (preg_match('/\.zip$/i', $origName)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) throw new RuntimeException('Not a valid zip');
            $sqlName = $zip->locateName('database.sql.gz') !== false ? 'database.sql.gz' : ($zip->locateName('database.sql') !== false ? 'database.sql' : null);
            if (!$sqlName) throw new RuntimeException('Zip does not contain database.sql.gz');
            if ($password !== null && $password !== '') $zip->setPassword($password);
            $sqlData = $zip->getFromName($sqlName);
            if ($sqlData === false || $sqlData === '') throw new RuntimeException('Could not read the memory: wrong or missing password?');
            $tmpSql = tempnam(sys_get_temp_dir(), 'atr') . ($sqlName === 'database.sql.gz' ? '.gz' : '.sql');
            file_put_contents($tmpSql, $sqlData);
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $n = $zip->getNameIndex($i);
                if (str_starts_with($n, 'uploads/') && !str_ends_with($n, '/') && !str_contains($n, '..')) {
                    $dest = $this->storage . '/' . $n;
                    @mkdir(dirname($dest), 0775, true);
                    $data = $zip->getFromIndex($i);
                    if ($data === false) continue;
                    file_put_contents($dest, $data);
                    $restoredFiles++;
                }
            }
            $zip->close();
            $path = $tmpSql;
            $origName = basename($tmpSql);
        }
        $fh = preg_match('/\.gz$/i', $origName) ? gzopen($path, 'rb') : fopen($path, 'rb');
        if (!$fh) throw new RuntimeException('Cannot read backup');
        $read = preg_match('/\.gz$/i', $origName) ? fn() => gzgets($fh, 1 << 24) : fn() => fgets($fh, 1 << 24);
        $pdo = $this->db->pdo;
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $stmt = '';
        $count = 0;
        try {
            while (($line = $read()) !== false) {
                if ($stmt === '' && (str_starts_with($line, '--') || trim($line) === '' || str_starts_with($line, '/*'))) continue;
                $stmt .= $line;
                if (preg_match('/;\s*$/', $line) && self::balanced($stmt)) {
                    $pdo->exec($stmt);
                    $stmt = '';
                    $count++;
                }
            }
            if (trim($stmt) !== '') { $pdo->exec($stmt); $count++; }
        } catch (\Throwable $e) {
            throw new RuntimeException('Restore failed after ' . $count . ' statements: ' . $e->getMessage() . ". Your previous memory was saved as $safety.");
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            preg_match('/\.gz$/i', $origName) ? gzclose($fh) : fclose($fh);
            if ($tmpSql) @unlink($tmpSql);
        }
        $this->db->log('backup_restored', null, null, "$count statements, $restoredFiles files");
        return ['statements' => $count, 'files' => $restoredFiles, 'safety_backup' => $safety];
    }

    /** True if the SQL text has no unterminated quoted string. */
    private static function balanced(string $sql): bool
    {
        $in = null;
        $len = strlen($sql);
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            if ($in) {
                if ($c === '\\') { $i++; continue; }
                if ($c === $in) { if ($i + 1 < $len && $sql[$i + 1] === $in) { $i++; continue; } $in = null; }
            } elseif ($c === "'" || $c === '"' || $c === '`') $in = $c;
        }
        return $in === null;
    }

    // ------------------------------------------------------------------ deleting memory

    /** Wipes part of Mnemo's memory. Makes a safety backup first unless $backup is false. */
    public function wipe(string $scope, bool $backup = true): array
    {
        if (!isset(self::WIPE_SCOPES[$scope])) throw new RuntimeException('Unknown scope');
        $safety = $backup ? $this->backup(false, 'prewipe') : null;
        $db = $this->db;
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            switch ($scope) {
                case 'conversations':
                    $db->pdo->exec('TRUNCATE messages'); $db->pdo->exec('TRUNCATE conversations');
                    break;
                case 'taught':
                    $doc = $db->val("SELECT id FROM documents WHERE sha256 = 'taught-knowledge'");
                    if ($doc) $db->run('DELETE FROM documents WHERE id = ?', [$doc]);
                    $db->pdo->exec('TRUNCATE user_facts');
                    $db->pdo->exec("DELETE FROM relations WHERE origin = 'user'");
                    $this->cleanOrphans();
                    break;
                case 'learning':
                    foreach (['qa_memory', 'golden_results', 'golden_runs', 'golden_tests', 'self_quiz', 'flashcards', 'standing_questions'] as $t) $db->pdo->exec("TRUNCATE $t");
                    break;
                case 'graph':
                    foreach (['entity_mentions', 'entity_aliases', 'relations', 'entities', 'daily_digest', 'claims', 'contradictions', 'review_queue', 'entity_merges', 'hypotheses', 'flashcards', 'fact_versions', 'curiosity_questions'] as $t) $db->pdo->exec("TRUNCATE $t");
                    $db->pdo->exec("UPDATE chunks SET extracted = 0");
                    $db->pdo->exec("UPDATE documents SET status = 'processing' WHERE status = 'ready' AND sha256 <> 'taught-knowledge'");
                    $db->pdo->exec("UPDATE chunks c JOIN documents d ON d.id = c.document_id SET c.extracted = 1 WHERE d.sha256 = 'taught-knowledge'");
                    break;
                case 'everything':
                    foreach (['entity_mentions', 'entity_aliases', 'relations', 'entities', 'chunk_embeddings', 'chunks', 'documents', 'messages', 'conversations', 'user_facts', 'daily_digest', 'ingest_log',
                              'claims', 'contradictions', 'fact_versions', 'review_queue', 'entity_merges', 'qa_memory', 'golden_results', 'golden_runs', 'golden_tests', 'self_quiz',
                              'standing_questions', 'flashcards', 'hypotheses', 'image_links', 'curiosity_questions', 'lessons', 'growth_diary'] as $t) $db->pdo->exec("TRUNCATE $t");
                    if (Vector::nativeEnabled()) $db->pdo->exec('TRUNCATE chunk_vectors');
                    $db->pdo->exec("DELETE FROM settings WHERE k IN ('baby.level', 'tutorial.demo_tests')");
                    $this->rmTree($this->storage . '/uploads', false);
                    break;
            }
        } finally {
            $db->pdo->exec('SET FOREIGN_KEY_CHECKS=1');
        }
        $db->log('memory_wiped', null, null, self::WIPE_SCOPES[$scope]);
        return ['scope' => $scope, 'safety_backup' => $safety];
    }

    public function forgetEntity(int $id): void
    {
        $name = $this->db->val('SELECT name FROM entities WHERE id = ?', [$id]);
        $this->db->run('DELETE FROM entities WHERE id = ?', [$id]);
        $this->db->log('entity_forgotten', null, null, (string)$name);
    }

    public function cleanOrphans(): void
    {
        $this->db->run("DELETE e FROM entities e LEFT JOIN entity_mentions m ON m.entity_id = e.id WHERE m.entity_id IS NULL
                        AND NOT EXISTS (SELECT 1 FROM relations r WHERE (r.source_id = e.id OR r.target_id = e.id) AND r.origin = 'user')");
        $this->db->run('UPDATE entities e SET mention_count = (SELECT COUNT(*) FROM entity_mentions m WHERE m.entity_id = e.id)');
        // a fact is no longer "disputed" once its rival is gone
        $this->db->run("UPDATE claims c SET c.status = 'active' WHERE c.status = 'disputed'
                        AND NOT EXISTS (SELECT 1 FROM contradictions x WHERE x.status = 'open' AND (x.claim_a = c.id OR x.claim_b = c.id))");
    }

    private function rmTree(string $dir, bool $removeSelf = true): void
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f->getFilename() === '.gitkeep') continue;
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        if ($removeSelf) @rmdir($dir);
    }

    // ------------------------------------------------------------------ maintenance

    /** Drops all embeddings; the ingest worker re-embeds them with the current model. */
    public function reembed(): int
    {
        $n = (int)$this->db->val('SELECT COUNT(*) FROM chunk_embeddings');
        $this->db->pdo->exec('TRUNCATE chunk_embeddings');
        if (Vector::nativeEnabled()) $this->db->pdo->exec('TRUNCATE chunk_vectors');
        $this->db->pdo->exec('UPDATE entities SET vec = NULL');
        $this->db->pdo->exec('UPDATE qa_memory SET vec = NULL');
        $this->db->log('reembed_started', null, null, "$n passages");
        return (int)$this->db->val('SELECT COUNT(*) FROM chunks');
    }

    public function rebuildSearchIndex(): void
    {
        $this->db->pdo->exec('ALTER TABLE chunks DROP INDEX ft_content');
        $this->db->pdo->exec('ALTER TABLE chunks ADD FULLTEXT KEY ft_content (content)');
        $this->db->pdo->exec('ALTER TABLE entities DROP INDEX ft_ent');
        $this->db->pdo->exec('ALTER TABLE entities ADD FULLTEXT KEY ft_ent (name, description)');
        $this->db->log('search_index_rebuilt');
    }

    public function optimize(): void
    {
        foreach (['chunks', 'chunk_embeddings', 'entities', 'relations', 'entity_mentions', 'ingest_log', 'messages'] as $t) {
            $this->db->all("OPTIMIZE TABLE `$t`");
        }
        $this->db->log('tables_optimized');
    }

    /** Exports the knowledge graph as JSON (nodes + links). */
    public function exportGraph(): array
    {
        return [
            'exported_at' => date('c'),
            'nodes' => $this->db->all('SELECT id, name, type, description, mention_count, cluster_id, confidence, first_seen FROM entities'),
            'links' => $this->db->all('SELECT source_id AS source, target_id AS target, type, origin, weight, confidence, evidence FROM relations'),
        ];
    }

    /** Exports everything Mnemo knows as readable Markdown. */
    public function exportMarkdown(): string
    {
        $md = "# Mnemo knowledge export\n\n_Exported " . date('Y-m-d H:i') . "_\n\n## Sources\n\n";
        foreach ($this->db->all("SELECT title, filename, summary FROM documents WHERE sha256 <> 'taught-knowledge' ORDER BY created_at") as $d) {
            $md .= "### {$d['title']}\n_{$d['filename']}_\n\n" . ($d['summary'] ? $d['summary'] . "\n\n" : '');
        }
        $md .= "## Taught by you\n\n";
        foreach ($this->db->all('SELECT kind, content, created_at FROM user_facts ORDER BY id') as $f) $md .= "- **{$f['kind']}** ({$f['created_at']}): {$f['content']}\n";
        $md .= "\n## Concepts\n\n";
        foreach ($this->db->all('SELECT id, name, type, description FROM entities ORDER BY mention_count DESC, name') as $e) {
            $md .= "### {$e['name']} ({$e['type']})\n" . ($e['description'] ? $e['description'] . "\n" : '');
            foreach ($this->db->all('SELECT r.type, t.name FROM relations r JOIN entities t ON t.id = r.target_id WHERE r.source_id = ? LIMIT 30', [$e['id']]) as $r) {
                $md .= "- " . str_replace('_', ' ', $r['type']) . " → {$r['name']}\n";
            }
            $md .= "\n";
        }
        return $md;
    }
}
