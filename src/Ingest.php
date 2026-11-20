<?php
declare(strict_types=1);

namespace Atlas;

use Throwable;

/**
 * Ingestion pipeline, run in small steps so it works both from the
 * browser ("Process now") and from the CLI worker.
 *   queued     -> parse, chunk, embed, summarize  -> processing
 *   processing -> extract entities/relations per chunk -> ready
 */
final class Ingest
{
    private Db $db;
    private Ollama $llm;

    public function __construct()
    {
        $this->db = Db::get();
        $this->llm = new Ollama();
    }

    /** Saves an uploaded/inbox file and queues it. Returns [docId, duplicate]. */
    public function addFile(string $tmpPath, string $origName, ?int $collectionId = null, bool $move = true): array
    {
        $kind = DocParser::kindFor($origName);
        if ($kind === null) throw new \InvalidArgumentException("Unsupported file type: $origName");
        $maint = new Maintenance();
        if ($why = $maint->uploadsBlocked()) throw new \RuntimeException($why);
        $limit = Maintenance::uploadLimit();
        if (filesize($tmpPath) > $limit) throw new \RuntimeException('File is larger than the ' . round($limit / 1048576) . ' MB upload limit (change it in Memory > Limits).');
        $sha = hash_file('sha256', $tmpPath);
        if ($dup = $this->db->val('SELECT id FROM documents WHERE sha256 = ?', [$sha])) {
            if ($move) @unlink($tmpPath);
            return [(int)$dup, true];
        }
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $rel = 'uploads/' . date('Y/m') . "/$sha.$ext";
        $abs = atlas_config()['storage'] . '/' . $rel;
        @mkdir(dirname($abs), 0775, true);
        $ok = $move ? (is_uploaded_file($tmpPath) ? move_uploaded_file($tmpPath, $abs) : rename($tmpPath, $abs)) : copy($tmpPath, $abs);
        if (!$ok) throw new \RuntimeException('Could not store file');
        $title = trim(preg_replace('/[_-]+/', ' ', pathinfo($origName, PATHINFO_FILENAME)) ?? $origName);
        $mime = function_exists('mime_content_type') ? (mime_content_type($abs) ?: null) : null;
        $this->db->run('INSERT INTO documents (collection_id, title, filename, stored_path, mime, kind, sha256, size_bytes) VALUES (?,?,?,?,?,?,?,?)',
            [$collectionId ?: null, mb_substr($title, 0, 250), mb_substr($origName, 0, 250), $rel, $mime, $kind, $sha, filesize($abs)]);
        $id = $this->db->id();
        $this->db->log('document_added', $id, null, $origName);
        return [$id, false];
    }

    /** Moves files dropped in storage/inbox into the queue. */
    public function scanInbox(): int
    {
        $n = 0;
        $dir = atlas_config()['storage_root'] . '/inbox';
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (!is_file($f) || DocParser::kindFor($f) === null) continue;
            try { $this->addFile($f, basename($f)); $n++; } catch (Throwable) {}
        }
        return $n;
    }

    public function pending(): array
    {
        return [
            'queued' => (int)$this->db->val("SELECT COUNT(*) FROM documents WHERE status = 'queued'"),
            'processing' => (int)$this->db->val("SELECT COUNT(*) FROM documents WHERE status = 'processing'"),
            'chunks_left' => (int)$this->db->val("SELECT COUNT(*) FROM chunks c JOIN documents d ON d.id = c.document_id WHERE d.status = 'processing' AND c.extracted = 0"),
            'claims_left' => (int)$this->db->val('SELECT COUNT(*) FROM claims WHERE checked = 0'),
            'embed_left' => (int)$this->db->val('SELECT COUNT(*) FROM chunks c LEFT JOIN chunk_embeddings e ON e.chunk_id = c.id WHERE e.chunk_id IS NULL'),
            // Totals for the percent bar
            'chunks_total' => (int)$this->db->val("SELECT COUNT(*) FROM chunks c JOIN documents d ON d.id = c.document_id WHERE d.status = 'processing'"),
            'claims_total' => (int)$this->db->val('SELECT COUNT(*) FROM claims'),
            'embed_total' => (int)$this->db->val('SELECT COUNT(*) FROM chunks'),
        ];
    }

    /** Does up to $budget seconds of work. Returns a progress report. */
    public function step(int $budget = 20): array
    {
        // A slow CPU can need several minutes for one AI call: never cut it off
        @set_time_limit(0);
        $start = microtime(true);
        $report = ['parsed' => [], 'extracted_chunks' => 0, 'completed' => [], 'errors' => []];
        // Only ONE learner at a time (browser tab and background worker would otherwise do the same work twice)
        if ((int)$this->db->val("SELECT GET_LOCK('atlas_ingest', 0)") !== 1) {
            $report['busy'] = true;
            $report['pending'] = $this->pending();
            return $report;
        }
        try {
            return $this->runSteps($budget, $start, $report);
        } finally {
            $this->db->val("SELECT RELEASE_LOCK('atlas_ingest')");
        }
    }

    private function runSteps(int $budget, float $start, array $report): array
    {
        $this->scanInbox();

        while (microtime(true) - $start < $budget) {
            // Stage 1: one queued document
            $doc = $this->db->one("SELECT * FROM documents WHERE status = 'queued' ORDER BY id LIMIT 1");
            if ($doc) {
                try {
                    $this->stageParse($doc);
                    $report['parsed'][] = $doc['title'];
                } catch (Throwable $e) {
                    $this->db->run("UPDATE documents SET status = 'failed', error = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 1000), $doc['id']]);
                    $this->db->log('document_failed', (int)$doc['id'], null, $e->getMessage());
                    $report['errors'][] = $doc['title'] . ': ' . $e->getMessage();
                }
                continue;
            }
            // Stage 1b: (re-)embed passages that have no vector (after a model change / re-embed)
            $missing = $this->db->all('SELECT c.id, c.content, d.title FROM chunks c JOIN documents d ON d.id = c.document_id
                                       LEFT JOIN chunk_embeddings e ON e.chunk_id = c.id WHERE e.chunk_id IS NULL ORDER BY c.id LIMIT 16');
            if ($missing) {
                try {
                    $vecs = $this->llm->embed(array_map(fn($m) => $m['title'] . "\n" . $m['content'], $missing));
                    foreach ($missing as $i => $m) {
                        if (isset($vecs[$i])) Vector::store((int)$m['id'], $this->llm->model('embed'), $vecs[$i]);
                    }
                    $report['embedded'] = ($report['embedded'] ?? 0) + count($missing);
                } catch (Throwable $e) {
                    $report['errors'][] = 'Embedding: ' . $e->getMessage();
                    break;
                }
                continue;
            }
            // Stage 2: extract one chunk
            $chunk = $this->db->one("SELECT c.*, d.title FROM chunks c JOIN documents d ON d.id = c.document_id
                                     WHERE d.status = 'processing' AND c.extracted = 0 ORDER BY c.document_id, c.seq LIMIT 1");
            if ($chunk) {
                try {
                    $this->stageExtract($chunk);
                } catch (Throwable $e) {
                    $report['errors'][] = 'Chunk ' . $chunk['id'] . ': ' . $e->getMessage();
                    if (str_contains($e->getMessage(), 'unreachable')) break;
                }
                $this->db->run('UPDATE chunks SET extracted = 1 WHERE id = ?', [$chunk['id']]);
                $report['extracted_chunks']++;
                $left = (int)$this->db->val('SELECT COUNT(*) FROM chunks WHERE document_id = ? AND extracted = 0', [$chunk['document_id']]);
                if ($left === 0) {
                    $this->db->run("UPDATE documents SET status = 'ready', processed_at = NOW() WHERE id = ?", [$chunk['document_id']]);
                    $this->db->log('document_ready', (int)$chunk['document_id'], null, $chunk['title']);
                    $report['completed'][] = $chunk['title'];
                }
                continue;
            }
            // Stage 3: fact-check new claims against everything Mnemo already knows
            $truth = new Truth($this->llm);
            if ($truth->uncheckedCount()) {
                try {
                    $report['checked_claims'] = ($report['checked_claims'] ?? 0) + $truth->checkNext(3);
                } catch (Throwable $e) {
                    $report['errors'][] = 'Fact check: ' . $e->getMessage();
                    break;
                }
                continue;
            }
            break; // nothing to do
        }
        $report['pending'] = $this->pending();
        return $report;
    }

    private function stageParse(array $doc): void
    {
        $cfg = atlas_config();
        $abs = $cfg['storage'] . '/' . $doc['stored_path'];
        $this->db->run("UPDATE documents SET status = 'processing', error = NULL WHERE id = ?", [$doc['id']]);

        if ($doc['kind'] === 'audio') {
            $pages = Media::transcribe($abs);
        } elseif ($doc['kind'] === 'image') {
            if ($h = Media::dhash($abs)) $this->db->run('UPDATE documents SET phash = ? WHERE id = ?', [$h, $doc['id']]);
            $desc = $this->llm->describeImage($abs);
            $ocr = DocParser::ocr($abs);
            $text = trim(($desc ? "Image description:\n$desc" : '') . ($ocr ? "\n\nText visible in image:\n$ocr" : ''));
            if ($text === '') $text = 'Image: ' . $doc['title'];
            $pages = [['page' => 1, 'text' => $text]];
        } else {
            $pages = DocParser::parse($abs, $doc['filename']);
        }

        $chunks = Chunker::chunk($pages, (int)$cfg['chunk']['target_chars'], (int)$cfg['chunk']['overlap_chars']);
        if (!$chunks) throw new \RuntimeException('Document contained no usable text.');

        $this->db->run('DELETE FROM chunks WHERE document_id = ?', [$doc['id']]);
        $ids = [];
        foreach ($chunks as $i => $c) {
            $this->db->run('INSERT INTO chunks (document_id, seq, page, heading, content, token_estimate) VALUES (?,?,?,?,?,?)',
                [$doc['id'], $i, $c['page'], $c['heading'], $c['content'], (int)ceil(mb_strlen($c['content']) / 4)]);
            $ids[] = $this->db->id();
        }

        // Embeddings, in batches. Prefix with the title so chunks carry document context.
        $model = $this->llm->model('embed');
        foreach (array_chunk(array_keys($chunks), 16) as $batch) {
            $texts = array_map(fn($i) => $doc['title'] . "\n" . $chunks[$i]['content'], $batch);
            $vecs = $this->llm->embed($texts);
            foreach ($batch as $j => $i) {
                if (!isset($vecs[$j])) continue;
                Vector::store($ids[$i], $model, $vecs[$j]);
            }
        }

        $full = implode("\n\n", array_column($pages, 'text'));
        $summary = '';
        try { $summary = (new Extractor($this->llm))->summarize($full, $doc['title']); } catch (Throwable) {}
        $this->db->run('UPDATE documents SET page_count = ?, summary = ? WHERE id = ?', [count($pages), $summary ?: null, $doc['id']]);
        $this->db->log('document_parsed', (int)$doc['id'], null, count($chunks) . ' chunks');
    }

    private function stageExtract(array $chunk): void
    {
        $out = (new Extractor($this->llm))->extract($chunk['content'], $chunk['title']);
        $map = [];
        foreach ($out['entities'] as $e) {
            [$id] = Graph::upsertEntity($e['name'], $e['type'], $e['description'], (int)$chunk['document_id']);
            if ($id) { $map[Graph::norm($e['name'])] = $id; Graph::mention($id, (int)$chunk['id']); }
        }
        $truth = new Truth($this->llm);
        foreach ($out['relations'] as $r) {
            $s = $map[Graph::norm($r['source'])] ?? Graph::upsertEntity($r['source'], 'concept', null, (int)$chunk['document_id'])[0];
            $t = $map[Graph::norm($r['target'])] ?? Graph::upsertEntity($r['target'], 'concept', null, (int)$chunk['document_id'])[0];
            if (!$s || !$t) continue;
            // Verify the quoted evidence really is in the passage; unverified links get low confidence + review
            $verified = Truth::evidenceFound($r['evidence'], $chunk['content']);
            if (Graph::addRelation($s, $t, $r['type'], 'extracted', $r['evidence'], (int)$chunk['id'], $verified ? 0.6 : 0.3)) {
                $relId = $this->db->id();
                $this->db->log('relation_created', (int)$chunk['document_id'], $s, $r['source'] . ' → ' . $r['type'] . ' → ' . $r['target']);
                if (!$verified && $relId) $truth->queue('relation', $relId, null, 'The quoted evidence was not found in the passage', 0.3);
            }
        }
        if (!empty($out['claims'])) $truth->saveClaims($out['claims'], $map, (int)$chunk['id'], (int)$chunk['document_id']);
    }
}
