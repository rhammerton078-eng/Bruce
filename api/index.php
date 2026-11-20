<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Atlas\{Cards, Db, Graph, Growth, Ingest, Insights, Maintenance, Media, Mnemo, Nightly, Ollama, Practice, Quality, Truth, Tutorial, Vector};

$action = $_GET['action'] ?? '';
@set_time_limit(900); // slow CPUs: AI answers can take minutes
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = [];
if ($method === 'POST' && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
}
$in = fn(string $k, mixed $d = null) => $body[$k] ?? $_POST[$k] ?? $_GET[$k] ?? $d;

function json_out(mixed $data, int $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Only allow requests from this machine unless explicitly opened up.
$remote = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
if (PHP_SAPI !== 'cli' && !in_array($remote, ['127.0.0.1', '::1'], true) && !is_file(__DIR__ . '/../storage/allow_lan')) {
    json_out(['error' => 'ATLAS only accepts requests from localhost. Create storage/allow_lan to allow your network.'], 403);
}
$writes = ['card_create', 'card_switch', 'card_rename', 'card_remove', 'card_export', 'card_import', 'curiosity_answer', 'curiosity_skip', 'wonder', 'lesson_teach', 'lesson_exam', 'lesson_delete', 'baby_settings', 'tutorial_save', 'demo_load', 'demo_remove', 'contradiction_resolve', 'review_decide', 'merge', 'merge_undo', 'hypothesis_decide', 'truth_scan', 'golden_add', 'golden_delete', 'golden_toggle', 'golden_run',
    'self_quiz_run', 'quiz_promote', 'approved_delete', 'watch_add', 'watch_delete', 'watch_check', 'watch_read', 'card_review', 'cards_generate', 'card_delete',
    'image_search', 'vector_native', 'backup_create', 'backup_delete', 'restore', 'wipe', 'entity_forget', 'reembed', 'rebuild_index', 'optimize', 'storage_settings_save', 'upload', 'process', 'ask', 'teach', 'nightly', 'feedback', 'settings_save', 'document_delete', 'document_retry', 'collection_create', 'document_update', 'conversation_delete'];
if (in_array($action, $writes, true) && $method !== 'POST') json_out(['error' => 'POST required'], 405);

try {
    $db = Db::get();
    atlas_migrate();

    switch ($action) {
        case 'status':
            $counts = $db->one("SELECT
                (SELECT COUNT(*) FROM documents WHERE sha256 <> 'taught-knowledge') AS documents,
                (SELECT COUNT(*) FROM documents WHERE kind = 'image') AS images,
                (SELECT COUNT(*) FROM chunks) AS chunks,
                (SELECT COUNT(*) FROM entities) AS entities,
                (SELECT COUNT(*) FROM relations) AS relations,
                (SELECT COUNT(*) FROM relations WHERE origin = 'inferred') AS inferred,
                (SELECT COUNT(DISTINCT cluster_id) FROM entities WHERE cluster_id IS NOT NULL) AS clusters,
                (SELECT COUNT(*) FROM user_facts) AS taught");
            $st = (new Maintenance())->storage(true);
            $tc = (new Truth())->counts();
            $badges = ['review' => $tc['contradictions'] + $tc['relations'] + $tc['merges'] + $tc['hypotheses'], 'contradictions' => $tc['contradictions'],
                       'cards_due' => (int)$db->val('SELECT COUNT(*) FROM flashcards WHERE due_at <= NOW()'), 'watch_unread' => (new Insights())->unread()];
            $g = new Growth();
            $levelUp = $g->checkLevelUp();
            $gs = $g->status();
            $badges['questions'] = $gs['questions_pending'];
            $reg = Cards::registry();
            json_out(['counts' => array_map('intval', $counts), 'badges' => $badges,
                'growth' => ['level' => $gs['level'], 'stage' => $gs['stage'], 'xp' => $gs['xp'], 'progress' => $gs['progress'], 'level_up' => $levelUp],
                'card' => ['id' => $reg['active'], 'name' => $reg['cards'][$reg['active']]['name'], 'count' => count($reg['cards'])], 'pending' => (new Ingest())->pending(), 'ollama' => (new Ollama())->status(),
                'storage' => ['level' => $st['level'], 'warnings' => array_values(array_filter($st['warnings'], fn($w) => $w['level'] !== 'info')),
                              'db_bytes' => $st['db_bytes'], 'disk_free' => $st['disk_free'], 'upload_limit' => $st['limits']['upload_effective']]]);

        case 'dashboard':
            $growth = $db->all("SELECT d AS day, SUM(docs) docs, SUM(ents) ents, SUM(rels) rels FROM (
                    SELECT DATE(created_at) d, COUNT(*) docs, 0 ents, 0 rels FROM documents WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY d
                    UNION ALL SELECT DATE(first_seen), 0, COUNT(*), 0 FROM entities WHERE first_seen >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(first_seen)
                    UNION ALL SELECT DATE(created_at), 0, 0, COUNT(*) FROM relations WHERE created_at >= CURDATE() - INTERVAL 29 DAY GROUP BY DATE(created_at)
                ) x GROUP BY d ORDER BY d");
            $types = $db->all('SELECT type, COUNT(*) n FROM entities GROUP BY type ORDER BY n DESC');
            $top = $db->all('SELECT e.id, e.name, e.type, e.mention_count,
                    (SELECT COUNT(*) FROM relations r WHERE r.source_id = e.id OR r.target_id = e.id) AS degree
                    FROM entities e ORDER BY degree DESC, mention_count DESC LIMIT 10');
            $digest = $db->one('SELECT * FROM daily_digest ORDER BY day DESC LIMIT 1');
            $streak = 0;
            $days = array_column($db->all("SELECT DISTINCT DATE(created_at) d FROM ingest_log WHERE event IN ('document_added','user_taught') ORDER BY d DESC LIMIT 400"), 'd');
            for ($t = strtotime('today'); in_array(date('Y-m-d', $t), $days, true); $t -= 86400) $streak++;
            json_out(['growth' => $growth, 'types' => $types, 'top' => $top, 'digest' => $digest, 'gaps' => (new Nightly())->gaps(6), 'streak' => $streak]);

        case 'tree':
            $collections = $db->all("SELECT c.id, c.name, c.color, COUNT(d.id) AS n FROM collections c
                LEFT JOIN documents d ON d.collection_id = c.id AND d.sha256 <> 'taught-knowledge' GROUP BY c.id ORDER BY c.name");
            $docs = $db->all("SELECT id, title, kind, status, collection_id FROM documents ORDER BY created_at DESC LIMIT 500");
            $types = $db->all('SELECT type, COUNT(*) n FROM entities GROUP BY type ORDER BY n DESC');
            $byType = [];
            foreach ($types as $t) {
                $byType[$t['type']] = ['n' => (int)$t['n'], 'items' => $db->all('SELECT id, name, mention_count FROM entities WHERE type = ? ORDER BY mention_count DESC, name LIMIT 40', [$t['type']])];
            }
            $clusters = $db->all("SELECT cluster_id AS id, COUNT(*) n,
                    SUBSTRING_INDEX(GROUP_CONCAT(name ORDER BY mention_count DESC SEPARATOR '|'), '|', 3) AS label
                FROM entities WHERE cluster_id IS NOT NULL GROUP BY cluster_id HAVING n > 1 ORDER BY n DESC LIMIT 30");
            json_out(['collections' => $collections, 'documents' => $docs, 'types' => $byType, 'clusters' => $clusters]);

        case 'graph':
            json_out(Graph::export([
                'limit' => $in('limit', 600), 'type' => $in('type'), 'collection' => $in('collection'),
                'document' => $in('document'), 'since' => $in('since'), 'min_conf' => $in('min_conf', 0),
            ]));

        case 'entity':
            $e = Graph::entityDetail((int)$in('id'));
            if (!$e) json_out(['error' => 'Not found'], 404);
            $e['claims'] = $db->all('SELECT c.id, c.statement, c.status, c.origin, c.support, c.confidence, c.chunk_id, d.title AS doc_title
                FROM claims c LEFT JOIN documents d ON d.id = c.document_id WHERE c.subject_id = ? ORDER BY FIELD(c.status, \'disputed\', \'active\', \'superseded\', \'rejected\'), c.support DESC LIMIT 40', [$e['id']]);
            $e['history'] = (new Truth())->history('entity', (int)$e['id']);
            $e['merges'] = $db->all('SELECT id, merged_name, created_at, undone_at FROM entity_merges WHERE kept_id = ? ORDER BY id DESC LIMIT 10', [$e['id']]);
            $e['flashcard'] = $db->val('SELECT id FROM flashcards WHERE entity_id = ?', [$e['id']]);
            // Preview: how each source can be shown, and cross-references
            foreach ($e['sources'] as &$src) {
                $d = $db->one('SELECT filename, mime, stored_path FROM documents WHERE id = ?', [$src['document_id']]);
                $src['filename'] = $d['filename'] ?? '';
                $src['mime'] = $d['mime'] ?? '';
                $src['has_file'] = !empty($d['stored_path']);
                $src['chunk_id'] = (int)$db->val('SELECT c.id FROM entity_mentions m JOIN chunks c ON c.id = m.chunk_id
                    WHERE m.entity_id = ? AND c.document_id = ? ORDER BY c.seq LIMIT 1', [$e['id'], $src['document_id']]);
            }
            unset($src);
            // concepts that appear in the same passages (even when not directly linked)
            $e['cooccur'] = $db->all('SELECT o.id, o.name, o.type, COUNT(*) AS n,
                    (SELECT COUNT(*) FROM relations r WHERE (r.source_id = o.id AND r.target_id = ?) OR (r.target_id = o.id AND r.source_id = ?)) AS linked
                FROM entity_mentions m1 JOIN entity_mentions m2 ON m2.chunk_id = m1.chunk_id AND m2.entity_id <> m1.entity_id
                JOIN entities o ON o.id = m2.entity_id
                WHERE m1.entity_id = ? GROUP BY o.id, o.name, o.type ORDER BY n DESC, o.mention_count DESC LIMIT 15', [$e['id'], $e['id'], $e['id']]);
            json_out($e);

        case 'path':
            json_out(['steps' => Graph::path((int)$in('from'), (int)$in('to'))]);

        case 'search':
            $q = trim((string)$in('q', ''));
            if (mb_strlen($q) < 2) json_out(['entities' => [], 'documents' => []]);
            $like = '%' . addcslashes($q, '%_\\') . '%';
            json_out([
                'entities' => $db->all('SELECT id, name, type, mention_count FROM entities WHERE name LIKE ? ORDER BY mention_count DESC LIMIT 8', [$like]),
                'documents' => $db->all('SELECT id, title, kind FROM documents WHERE title LIKE ? OR filename LIKE ? ORDER BY created_at DESC LIMIT 6', [$like, $like]),
            ]);

        case 'documents':
            json_out($db->all("SELECT d.id, d.title, d.filename, d.kind, d.status, d.error, d.page_count, d.size_bytes, d.trust,
                    d.created_at, d.processed_at, d.collection_id, c.name AS collection,
                    (SELECT COUNT(*) FROM chunks ch WHERE ch.document_id = d.id) AS chunks,
                    (SELECT COUNT(*) FROM chunks ch WHERE ch.document_id = d.id AND ch.extracted = 1) AS extracted,
                    (SELECT COUNT(DISTINCT em.entity_id) FROM entity_mentions em JOIN chunks ch ON ch.id = em.chunk_id WHERE ch.document_id = d.id) AS entities
                FROM documents d LEFT JOIN collections c ON c.id = d.collection_id ORDER BY d.created_at DESC"));

        case 'document':
            $id = (int)$in('id');
            $d = $db->one('SELECT d.*, c.name AS collection FROM documents d LEFT JOIN collections c ON c.id = d.collection_id WHERE d.id = ?', [$id]);
            if (!$d) json_out(['error' => 'Not found'], 404);
            $d['entities'] = $db->all('SELECT e.id, e.name, e.type, COUNT(*) n FROM entity_mentions em JOIN chunks c ON c.id = em.chunk_id
                JOIN entities e ON e.id = em.entity_id WHERE c.document_id = ? GROUP BY e.id ORDER BY n DESC LIMIT 60', [$id]);
            $d['chunks'] = $db->all('SELECT id, seq, page, heading, SUBSTRING(content, 1, 600) AS content FROM chunks WHERE document_id = ? ORDER BY seq LIMIT 200', [$id]);
            $d['related_images'] = $d['kind'] === 'image' ? Media::relatedImages($id) : [];
            $d['claims'] = (int)$db->val('SELECT COUNT(*) FROM claims WHERE document_id = ?', [$id]);
            $d['disputed'] = (int)$db->val("SELECT COUNT(*) FROM claims WHERE document_id = ? AND status IN ('disputed','rejected','superseded')", [$id]);
            json_out($d);

        case 'chunk':
            $c = $db->one('SELECT c.id, c.page, c.heading, c.content, d.id AS document_id, d.title, d.kind FROM chunks c JOIN documents d ON d.id = c.document_id WHERE c.id = ?', [(int)$in('id')]);
            $c ? json_out($c) : json_out(['error' => 'Not found'], 404);

        case 'file':
            $d = $db->one('SELECT stored_path, mime, filename FROM documents WHERE id = ?', [(int)$in('id')]);
            $base = realpath(atlas_config()['storage']);
            $path = $d ? realpath($base . '/' . $d['stored_path']) : false;
            if (!$path || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) json_out(['error' => 'Not found'], 404);
            header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . addslashes($d['filename']) . '"');
            header('X-Content-Type-Options: nosniff');
            readfile($path);
            exit;

        case 'upload':
            $ing = new Ingest();
            $results = [];
            $files = $_FILES['files'] ?? null;
            if (!$files) json_out(['error' => 'No files received. Check upload_max_filesize / post_max_size in php.ini.'], 400);
            $count = is_array($files['name']) ? count($files['name']) : 1;
            for ($i = 0; $i < $count; $i++) {
                $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                $err = is_array($files['error']) ? $files['error'][$i] : $files['error'];
                if ($err !== UPLOAD_ERR_OK) { $results[] = ['name' => $name, 'error' => 'Upload error ' . $err]; continue; }
                try {
                    [$id, $dup] = $ing->addFile($tmp, $name, ((int)$in('collection_id', 0)) ?: null);
                    $results[] = ['name' => $name, 'id' => $id, 'duplicate' => $dup];
                } catch (Throwable $e) {
                    $results[] = ['name' => $name, 'error' => $e->getMessage()];
                }
            }
            json_out(['results' => $results]);

        case 'process':
            session_write_close();
            json_out((new Ingest())->step((int)$in('budget', 20)));

        case 'document_retry':
            $db->run("UPDATE documents SET status = 'queued', error = NULL WHERE id = ?", [(int)$in('id')]);
            json_out(['ok' => true]);

        case 'document_delete':
            $id = (int)$in('id');
            $d = $db->one('SELECT stored_path FROM documents WHERE id = ?', [$id]);
            if ($d) {
                $db->run('DELETE FROM documents WHERE id = ?', [$id]);
                if ($d['stored_path'] !== '') @unlink(atlas_config()['storage'] . '/' . $d['stored_path']);
                // remove entities no longer mentioned anywhere and not taught
                $db->run("DELETE e FROM entities e LEFT JOIN entity_mentions m ON m.entity_id = e.id WHERE m.entity_id IS NULL
                          AND NOT EXISTS (SELECT 1 FROM relations r WHERE (r.source_id = e.id OR r.target_id = e.id) AND r.origin = 'user')");
                (new Maintenance())->cleanOrphans();
                $db->log('document_deleted', null, null, "Document #$id");
            }
            json_out(['ok' => true]);

        case 'document_update':
            $id = (int)$in('id');
            if (($t = $in('title')) !== null) $db->run('UPDATE documents SET title = ? WHERE id = ?', [mb_substr((string)$t, 0, 250), $id]);
            if (($c = $in('collection_id')) !== null) $db->run('UPDATE documents SET collection_id = ? WHERE id = ?', [((int)$c) ?: null, $id]);
            if (($tr = $in('trust')) !== null) $db->run('UPDATE documents SET trust = ? WHERE id = ?', [max(0, min(100, (int)$tr)), $id]);
            json_out(['ok' => true]);

        case 'collection_create':
            $name = trim((string)$in('name', ''));
            if ($name === '') json_out(['error' => 'Name required'], 400);
            $db->run('INSERT IGNORE INTO collections (name, color) VALUES (?, ?)', [mb_substr($name, 0, 190), (string)$in('color', '#38bdf8')]);
            json_out(['ok' => true]);

        case 'conversations':
            json_out($db->all('SELECT c.id, c.title, c.created_at, (SELECT COUNT(*) FROM messages m WHERE m.conversation_id = c.id) n
                               FROM conversations c ORDER BY c.id DESC LIMIT 50'));

        case 'conversation':
            json_out($db->all('SELECT id, role, content, sources, feedback, created_at FROM messages WHERE conversation_id = ? ORDER BY id', [(int)$in('id')]));

        case 'conversation_delete':
            $db->run('DELETE FROM conversations WHERE id = ?', [(int)$in('id')]);
            json_out(['ok' => true]);

        case 'ask':
            session_write_close();
            $q = trim((string)$in('q', ''));
            if ($q === '') json_out(['error' => 'Empty question'], 400);
            $convId = (int)$in('conversation_id', 0);
            if (!$convId) {
                $db->run('INSERT INTO conversations (title) VALUES (?)', [mb_substr($q, 0, 120)]);
                $convId = $db->id();
            }
            $history = $db->all('SELECT role, content FROM messages WHERE conversation_id = ? ORDER BY id DESC LIMIT 6', [$convId]);
            $history = array_reverse($history);
            $db->run("INSERT INTO messages (conversation_id, role, content) VALUES (?, 'user', ?)", [$convId, $q]);

            // Stream NDJSON
            header('Content-Type: application/x-ndjson; charset=utf-8');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            @ini_set('zlib.output_compression', '0');
            @ini_set('output_buffering', '0');
            while (ob_get_level()) ob_end_flush();
            $send = function (array $o) { echo json_encode($o, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n"; flush(); };
            $send(['type' => 'meta', 'conversation_id' => $convId]);

            $mnemo = new Mnemo();
            $answer = '';
            $sources = [];
            try {
                if ($cmd = $mnemo->parseCommand($q)) {
                    $answer = $mnemo->teach($cmd['cmd'], $cmd['text']);
                    $send(['type' => 'token', 't' => $answer]);
                } else {
                    $asOf = $in('as_of') ?: null;
                    $chunks = $mnemo->retrieve($q, (int)atlas_config()['retrieval']['top_k'], $asOf, ((int)$in('collection', 0)) ?: null);
                    $graph = $mnemo->graphFacts($q);
                    $approved = $mnemo->approvedAnswer($q);
                    foreach ($chunks as $i => $c) {
                        $sources[] = ['n' => $i + 1, 'chunk_id' => (int)$c['id'], 'document_id' => (int)$c['document_id'], 'title' => $c['title'],
                            'page' => $c['page'] ? (int)$c['page'] : null, 'kind' => $c['kind'], 'score' => $c['score'],
                            'snippet' => mb_substr($c['content'], 0, 280)];
                    }
                    $notes = (new \Atlas\Truth())->notesForChunks(array_map(fn($c) => (int)$c['id'], $chunks));
                    $send(['type' => 'sources', 'sources' => $sources, 'entities' => $graph['entities'], 'approved' => $approved, 'disputed' => count($notes)]);
                    if (!$chunks && !$approved && Mnemo::strict()) {
                        $answer = Mnemo::DONT_KNOW; // baby mode: never answer from the model's own knowledge
                        $send(['type' => 'token', 't' => $answer]);
                    } else {
                        $answer = (new Ollama())->chatStream($mnemo->buildMessages($q, $chunks, $graph, $history, $approved), fn($t) => $send(['type' => 'token', 't' => $t]));
                    }
                }
            } catch (Throwable $e) {
                $msg = "⚠️ " . $e->getMessage();
                $answer .= ($answer ? "\n\n" : '') . $msg;
                $send(['type' => 'token', 't' => "\n\n" . $msg]);
            }
            $db->run("INSERT INTO messages (conversation_id, role, content, sources) VALUES (?, 'assistant', ?, ?)",
                [$convId, $answer, json_encode($sources, JSON_UNESCAPED_UNICODE)]);
            $send(['type' => 'done', 'message_id' => $db->id()]);
            exit;

        case 'feedback':
            $v = max(-1, min(1, (int)$in('value')));
            $db->run('UPDATE messages SET feedback = ? WHERE id = ?', [$v, (int)$in('id')]);
            (new Quality())->feedback((int)$in('id'), $v);
            json_out(['ok' => true]);

        case 'timeline':
            $events = $db->all("SELECT l.id, l.event, l.detail, l.created_at, l.document_id, l.entity_id, d.title AS doc_title
                FROM ingest_log l LEFT JOIN documents d ON d.id = l.document_id
                WHERE l.event IN ('document_added','document_ready','document_failed','entity_created','user_taught','nightly_run','document_deleted','backup_created','backup_restored','memory_wiped','entity_forgotten','reembed_started','search_index_rebuilt','contradiction_found','contradiction_resolved','entity_merged','merge_undone','hypothesis_accepted','golden_run','self_quiz','standing_updated','answer_approved','demo_loaded','demo_removed','level_up','lesson_taught','lesson_exam')
                ORDER BY l.id DESC LIMIT " . max(20, min(1000, (int)$in('limit', 300))));
            $days = $db->all('SELECT DATE(created_at) day, SUM(event = \'entity_created\') entities, SUM(event = \'relation_created\') relations,
                SUM(event = \'document_added\') documents FROM ingest_log GROUP BY day ORDER BY day DESC LIMIT 60');
            json_out(['events' => $events, 'days' => $days]);

        case 'nightly':
            session_write_close();
            json_out((new Nightly())->run());

        case 'settings':
            $cfg = atlas_config();
            json_out(['app' => $cfg['app'] ?? [], 'ollama' => array_intersect_key($cfg['ollama'], array_flip(['url', 'chat_model', 'embed_model', 'vision_model'])),
                'retrieval' => $cfg['retrieval'], 'tools' => ['pdftotext' => \Atlas\DocParser::tool('pdftotext'), 'tesseract' => \Atlas\DocParser::tool('tesseract')] + Media::tools(),
                'vector' => Vector::nativeSupported(), 'gd' => function_exists('imagecreatefromstring')]);

        case 'settings_save':
            $allowed = ['ollama.chat_model', 'ollama.embed_model', 'ollama.vision_model', 'retrieval.top_k'];
            foreach ($allowed as $k) {
                $v = $in(str_replace('.', '_', $k));
                if ($v !== null) $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, trim((string)$v)]);
            }
            json_out(['ok' => true]);

        // ---------------------------------------------------------- memory management
        case 'storage':
            $m = new Maintenance();
            json_out($m->storage() + ['backups' => $m->listBackups(), 'wipe_scopes' => Maintenance::WIPE_SCOPES, 'vector' => Vector::nativeSupported(),
                'embeddings' => (int)$db->val('SELECT COUNT(*) FROM chunk_embeddings')]);

        case 'storage_settings_save':
            $map = ['warn_db_mb' => 'storage.warn_db_mb', 'crit_db_mb' => 'storage.crit_db_mb', 'warn_disk_free_mb' => 'storage.warn_disk_free_mb',
                    'crit_disk_free_mb' => 'storage.crit_disk_free_mb', 'max_upload_mb' => 'storage.max_upload_mb', 'backup_keep' => 'backup.keep'];
            foreach ($map as $in_k => $k) {
                $v = $in($in_k);
                if ($v !== null && is_numeric($v)) $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', [$k, (string)max(1, (int)$v)]);
            }
            json_out(['ok' => true]);

        case 'backup_create':
            session_write_close();
            json_out(['file' => (new Maintenance())->backup((bool)$in('with_files', false))]);

        case 'backup_download':
            $p = (new Maintenance())->backupPath((string)$in('file'));
            header('Content-Type: ' . (str_ends_with($p, '.zip') ? 'application/zip' : 'application/gzip'));
            header('Content-Disposition: attachment; filename="' . basename($p) . '"');
            header('Content-Length: ' . filesize($p));
            readfile($p);
            exit;

        case 'backup_delete':
            (new Maintenance())->deleteBackup((string)$in('file'));
            json_out(['ok' => true]);

        case 'restore':
            session_write_close();
            $m = new Maintenance();
            if ($in('file')) {
                json_out($m->restore($m->backupPath((string)$in('file')), (string)$in('file')));
            }
            $f = $_FILES['backup'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'No backup file received (it may exceed upload_max_filesize).'], 400);
            if (!preg_match('/\.(sql|sql\.gz|zip)$/i', $f['name'])) json_out(['error' => 'Choose an ATLAS backup (.sql.gz, .sql or .zip)'], 400);
            json_out($m->restore($f['tmp_name'], $f['name']));

        case 'wipe':
            if ($in('confirm') !== 'DELETE') json_out(['error' => 'Type DELETE to confirm.'], 400);
            json_out((new Maintenance())->wipe((string)$in('scope'), $in('backup', true) !== false));

        case 'entity_forget':
            (new Maintenance())->forgetEntity((int)$in('id'));
            json_out(['ok' => true]);

        case 'reembed':
            json_out(['queued' => (new Maintenance())->reembed()]);

        case 'rebuild_index':
            (new Maintenance())->rebuildSearchIndex();
            json_out(['ok' => true]);

        case 'optimize':
            session_write_close();
            (new Maintenance())->optimize();
            json_out(['ok' => true]);

        case 'export_graph':
            header('Content-Disposition: attachment; filename="atlas-graph-' . date('Ymd') . '.json"');
            json_out((new Maintenance())->exportGraph());

        case 'export_md':
            header('Content-Type: text/markdown; charset=utf-8');
            header('Content-Disposition: attachment; filename="mnemo-knowledge-' . date('Ymd') . '.md"');
            echo (new Maintenance())->exportMarkdown();
            exit;

        // ---------------------------------------------------------- Phase 5: truth engine
        case 'review_counts':
            json_out((new Truth())->counts());

        case 'contradictions':
            json_out((new Truth())->contradictions((string)$in('status', 'open')));

        case 'contradiction_resolve':
            (new Truth())->resolve((int)$in('id'), (string)$in('decision'));
            json_out(['ok' => true]);

        case 'review_items':
            json_out((new Truth())->reviewItems((string)$in('kind', 'relation')));

        case 'review_decide':
            (new Truth())->decide((int)$in('id'), (bool)$in('approve'));
            json_out(['ok' => true]);

        case 'merge':
            json_out(['merge_id' => (new Truth())->merge((int)$in('keep'), (int)$in('merge'))]);

        case 'merges':
            json_out((new Truth())->recentMerges());

        case 'merge_undo':
            (new Truth())->undoMerge((int)$in('id'));
            json_out(['ok' => true]);

        case 'hypotheses':
            json_out((new Truth())->hypotheses());

        case 'hypothesis_decide':
            (new Truth())->decideHypothesis((int)$in('id'), (bool)$in('accept'));
            json_out(['ok' => true]);

        case 'truth_scan':
            session_write_close();
            $t = new Truth();
            $checked = 0; $end = time() + 60;
            while ($t->uncheckedCount() && time() < $end) $checked += $t->checkNext(5);
            json_out(['checked' => $checked, 'merges' => $t->suggestMerges(), 'hypotheses' => $t->discoverHypotheses(), 'remaining' => $t->uncheckedCount()]);

        // ---------------------------------------------------------- Phase 6: self-improvement
        case 'quality':
            $q = new Quality();
            json_out(['tests' => $q->tests(), 'runs' => $q->runs(), 'quiz' => $q->quizResults(), 'quiz_stats' => $q->quizStats(), 'approved' => $q->approved()]);

        case 'golden_add':
            json_out(['id' => (new Quality())->addTest((string)$in('question', ''), (string)$in('expected', ''))]);

        case 'golden_delete':
            $db->run('DELETE FROM golden_tests WHERE id = ?', [(int)$in('id')]);
            json_out(['ok' => true]);

        case 'golden_toggle':
            $db->run('UPDATE golden_tests SET active = 1 - active WHERE id = ?', [(int)$in('id')]);
            json_out(['ok' => true]);

        case 'golden_run':
            session_write_close();
            json_out((new Quality())->runTests());

        case 'self_quiz_run':
            session_write_close();
            json_out(['results' => (new Quality())->selfQuiz(max(1, min(20, (int)$in('n', 5))))]);

        case 'quiz_promote':
            json_out(['id' => (new Quality())->promoteQuiz((int)$in('id'))]);

        case 'approved_delete':
            $db->run('DELETE FROM qa_memory WHERE id = ?', [(int)$in('id')]);
            json_out(['ok' => true]);

        case 'training_export':
            header('Content-Type: application/jsonl; charset=utf-8');
            header('Content-Disposition: attachment; filename="mnemo-training-' . date('Ymd') . '.jsonl"');
            echo (new Quality())->trainingData();
            exit;

        // ---------------------------------------------------------- Phase 7: watchlist & practice
        case 'watch_list':
            json_out((new Insights())->list());

        case 'watch_add':
            session_write_close();
            json_out(['id' => (new Insights())->add((string)$in('question', ''))]);

        case 'watch_delete':
            (new Insights())->remove((int)$in('id'));
            json_out(['ok' => true]);

        case 'watch_check':
            session_write_close();
            $ins = new Insights();
            json_out(['updated' => $in('id') ? (int)$ins->check((int)$in('id'), (bool)$in('force', false)) : $ins->checkAll()]);

        case 'watch_read':
            (new Insights())->markRead((int)$in('id'));
            json_out(['ok' => true]);

        case 'cards':
            $p = new Practice();
            json_out(['due' => $p->due(), 'stats' => $p->stats()]);

        case 'card_review':
            json_out((new Practice())->review((int)$in('id'), (string)$in('grade')));

        case 'cards_generate':
            session_write_close();
            $p = new Practice();
            json_out(['created' => $in('source') === 'sources' ? $p->generateFromSources(3) : $p->generateFromConcepts(50)]);

        case 'card_delete':
            (new Practice())->delete((int)$in('id'));
            json_out(['ok' => true]);

        // ---------------------------------------------------------- Phase 8: media & scale
        case 'image_search':
            session_write_close();
            $f = $_FILES['image'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'No image received'], 400);
            if (\Atlas\DocParser::kindFor($f['name']) !== 'image') json_out(['error' => 'Please choose an image file'], 400);
            json_out(Media::searchByImage($f['tmp_name']));

        case 'vector_native':
            if ($in('enable')) json_out(['indexed' => Vector::enableNative()]);
            Vector::disableNative();
            json_out(['ok' => true]);

        // ---------------------------------------------------------- memory cards
        case 'memory_cards':
            json_out(['cards' => Cards::list(), 'can_encrypt' => defined('ZipArchive::EM_AES_256')]);

        case 'card_create':
            $id = Cards::create((string)$in('name', ''));
            if ($in('switch', true)) Cards::switch($id);
            json_out(['id' => $id]);

        case 'card_switch':
            Cards::switch((string)$in('id'));
            json_out(['ok' => true]);

        case 'card_rename':
            Cards::rename((string)$in('id'), (string)$in('name', ''));
            json_out(['ok' => true]);

        case 'card_remove':
            if ($in('confirm') !== 'DELETE') json_out(['error' => 'Type DELETE to confirm.'], 400);
            Cards::remove((string)$in('id'));
            json_out(['ok' => true]);

        case 'card_export':
            session_write_close();
            @set_time_limit(0);
            [$token, $name] = Cards::export((string)$in('id', Cards::activeId()), ((string)$in('password', '')) ?: null);
            json_out(['token' => $token, 'filename' => $name]);

        case 'card_download':
            $p = Cards::exportPath((string)$in('token'));
            $name = preg_replace('/[^A-Za-z0-9 ._-]+/', '', (string)$in('name', 'memory.atlascard')) ?: 'memory.atlascard';
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $name . '"');
            header('Content-Length: ' . filesize($p));
            readfile($p);
            exit;

        case 'card_import':
            session_write_close();
            @set_time_limit(0);
            $f = $_FILES['card'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) json_out(['error' => 'No card file received (it may be larger than upload_max_filesize in php.ini).'], 400);
            if ($in('inspect')) json_out(Cards::inspect($f['tmp_name']));
            $id = Cards::import($f['tmp_name'], ((string)$in('name', '')) ?: null, ((string)$in('password', '')) ?: null);
            json_out(['id' => $id]);

        // ---------------------------------------------------------- baby mode
        case 'growth':
            $g = new Growth();
            json_out(['status' => $g->status(), 'questions' => $g->questions('pending'), 'answered' => $g->questions('answered', 10), 'lessons' => $g->lessons(), 'diary' => $g->diary()]);

        case 'wonder':
            json_out(['new' => (new Growth())->wonder((int)$in('n', 8))]);

        case 'curiosity_answer':
            session_write_close();
            json_out(['message' => (new Growth())->answer((int)$in('id'), (string)$in('answer', ''))]);

        case 'curiosity_skip':
            (new Growth())->skip((int)$in('id'));
            json_out(['ok' => true]);

        case 'lesson_teach':
            session_write_close();
            json_out((new Growth())->teachLesson((string)$in('title', ''), (string)$in('content', '')));

        case 'lesson_exam':
            session_write_close();
            json_out((new Growth())->exam((int)$in('id')));

        case 'lesson_delete':
            (new Growth())->deleteLesson((int)$in('id'));
            json_out(['ok' => true]);

        case 'baby_settings':
            foreach (['strict' => 'baby.strict', 'forgetting' => 'baby.forgetting'] as $k => $key) {
                if ($in($k) !== null) $db->run('REPLACE INTO settings (k, v) VALUES (?, ?)', [$key, $in($k) ? '1' : '0']);
            }
            json_out(['ok' => true]);

        // ---------------------------------------------------------- tutorial mode
        case 'tutorial':
            $t = new Tutorial();
            json_out(['state' => $t->state(), 'checklist' => $t->checklist(), 'demo' => $t->demoInfo(),
                      'empty' => !(int)$db->val("SELECT COUNT(*) FROM documents WHERE sha256 <> 'taught-knowledge'")]);

        case 'tutorial_save':
            json_out((new Tutorial())->save($body ?: $_POST));

        case 'demo_load':
            json_out((new Tutorial())->loadDemo());

        case 'demo_remove':
            json_out((new Tutorial())->removeDemo());

        default:
            json_out(['error' => 'Unknown action'], 404);
    }
} catch (PDOException $e) {
    json_out(['error' => 'Database error: ' . $e->getMessage(), 'setup' => true], 500);
} catch (Throwable $e) {
    json_out(['error' => $e->getMessage()], 500);
}
