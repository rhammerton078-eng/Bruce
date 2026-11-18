<?php
declare(strict_types=1);

namespace Atlas;

/** Tutorial mode: saved progress, a self-checking "getting started" checklist and the demo knowledge pack. */
final class Tutorial
{
    private const DEMO_COLLECTION = 'Tutorial (demo)';
    private Db $db;

    public function __construct()
    {
        $this->db = Db::get();
    }

    public function state(): array
    {
        $raw = $this->db->val("SELECT v FROM settings WHERE k = 'tutorial.state'");
        $s = $raw ? (json_decode((string)$raw, true) ?: []) : [];
        return ['done' => array_values($s['done'] ?? []), 'welcomed' => (bool)($s['welcomed'] ?? false), 'flags' => $s['flags'] ?? (object)[]];
    }

    public function save(array $patch): array
    {
        $s = $this->state();
        if (isset($patch['done']) && is_array($patch['done'])) $s['done'] = array_values(array_unique(array_map('strval', $patch['done'])));
        if (isset($patch['complete'])) $s['done'] = array_values(array_unique([...$s['done'], (string)$patch['complete']]));
        if (isset($patch['welcomed'])) $s['welcomed'] = (bool)$patch['welcomed'];
        if (isset($patch['flag'])) { $f = (array)$s['flags']; $f[(string)$patch['flag']] = true; $s['flags'] = $f; }
        if (!empty($patch['reset'])) $s = ['done' => [], 'welcomed' => false, 'flags' => []];
        $this->db->run("REPLACE INTO settings (k, v) VALUES ('tutorial.state', ?)", [json_encode($s)]);
        return $this->state();
    }

    /** Each item is checked against the real database, so it ticks itself off when you actually do it. */
    public function checklist(): array
    {
        $v = fn(string $sql) => (int)$this->db->val($sql) > 0;
        $flags = (array)$this->state()['flags'];
        $manualBackups = array_filter((new Maintenance())->listBackups(), fn($b) => !preg_match('/-(auto|prewipe|prerestore)/', $b['file']));
        $items = [
            ['id' => 'upload', 'chapter' => 'teach', 'title' => 'Upload your first document', 'done' => $v("SELECT COUNT(*) FROM documents WHERE sha256 <> 'taught-knowledge'")],
            ['id' => 'learned', 'chapter' => 'teach', 'title' => 'Let Mnemo finish learning it', 'done' => $v("SELECT COUNT(*) FROM documents WHERE status = 'ready' AND sha256 <> 'taught-knowledge'")],
            ['id' => 'explore', 'chapter' => 'graph', 'title' => 'Open a concept in the graph', 'done' => !empty($flags['opened_entity'])],
            ['id' => 'ask', 'chapter' => 'ask', 'title' => 'Ask Mnemo a question', 'done' => $v("SELECT COUNT(*) FROM messages WHERE role = 'user' AND content NOT REGEXP '^[[:space:]]*(remember|define|correct)[[:space:]]*:'")],
            ['id' => 'teach', 'chapter' => 'ask', 'title' => 'Teach a fact with remember:', 'done' => $v('SELECT COUNT(*) FROM user_facts')],
            ['id' => 'feedback', 'chapter' => 'ask', 'title' => 'Rate an answer with 👍 or 👎', 'done' => $v('SELECT COUNT(*) FROM messages WHERE feedback IS NOT NULL')],
            ['id' => 'sleep', 'chapter' => 'routine', 'title' => 'Run a sleep cycle', 'done' => $v('SELECT COUNT(*) FROM daily_digest')],
            ['id' => 'review', 'chapter' => 'review', 'title' => 'Resolve a contradiction or review item', 'done' => $v("SELECT (SELECT COUNT(*) FROM contradictions WHERE status <> 'open') + (SELECT COUNT(*) FROM review_queue WHERE status <> 'pending') + (SELECT COUNT(*) FROM hypotheses WHERE status <> 'pending')")],
            ['id' => 'practice', 'chapter' => 'practice', 'title' => 'Review a flashcard', 'done' => $v('SELECT COUNT(*) FROM flashcards WHERE reviews > 0')],
            ['id' => 'watch', 'chapter' => 'ask', 'title' => 'Add a question to your watchlist', 'done' => $v('SELECT COUNT(*) FROM standing_questions')],
            ['id' => 'benchmark', 'chapter' => 'quality', 'title' => 'Run the benchmark once', 'done' => $v('SELECT COUNT(*) FROM golden_runs WHERE total > 0')],
            ['id' => 'backup', 'chapter' => 'memory', 'title' => 'Download a backup of the memory', 'done' => (bool)$manualBackups],
        ];
        return $items;
    }

    // ------------------------------------------------------------------ demo pack

    public function demoInfo(): array
    {
        $cid = $this->db->val('SELECT id FROM collections WHERE name = ?', [self::DEMO_COLLECTION]);
        $docs = $cid ? (int)$this->db->val('SELECT COUNT(*) FROM documents WHERE collection_id = ?', [$cid]) : 0;
        return ['loaded' => $docs > 0, 'documents' => $docs, 'files' => count(glob(__DIR__ . '/../tutorial/demo/*.md') ?: [])];
    }

    /** Copies the demo documents into the queue (they are learned like any upload) and adds sample tests. */
    public function loadDemo(): array
    {
        $this->db->run('INSERT IGNORE INTO collections (name, color) VALUES (?, ?)', [self::DEMO_COLLECTION, '#fbbf24']);
        $cid = (int)$this->db->val('SELECT id FROM collections WHERE name = ?', [self::DEMO_COLLECTION]);
        $ing = new Ingest();
        $added = 0;
        foreach (glob(__DIR__ . '/../tutorial/demo/*.md') ?: [] as $f) {
            [$id, $dup] = $ing->addFile($f, preg_replace('/^\d+\s+/', '', basename($f)), $cid, false);
            if ($dup) $this->db->run('UPDATE documents SET collection_id = ? WHERE id = ?', [$cid, $id]);
            else $added++;
        }
        $tests = [
            ['How many planets are in the Solar System?', 'Eight planets (Pluto was reclassified as a dwarf planet in 2006)'],
            ['Who were the first humans to walk on the Moon?', 'Neil Armstrong and Buzz Aldrin (Apollo 11, 1969)'],
            ['What is the tallest volcano in the Solar System?', 'Olympus Mons on Mars'],
        ];
        $ids = [];
        foreach ($tests as [$q, $a]) {
            $ex = $this->db->val('SELECT id FROM golden_tests WHERE question = ?', [$q]);
            $ids[] = $ex ? (int)$ex : (new Quality())->addTest($q, $a);
        }
        $this->db->run("REPLACE INTO settings (k, v) VALUES ('tutorial.demo_tests', ?)", [json_encode($ids)]);
        $this->db->log('demo_loaded', null, null, "$added demo documents");
        return ['added' => $added, 'collection_id' => $cid, 'tests' => count($ids)];
    }

    public function removeDemo(): array
    {
        $cid = $this->db->val('SELECT id FROM collections WHERE name = ?', [self::DEMO_COLLECTION]);
        $n = 0;
        if ($cid) {
            foreach ($this->db->all('SELECT id, stored_path FROM documents WHERE collection_id = ?', [$cid]) as $d) {
                $this->db->run('DELETE FROM documents WHERE id = ?', [$d['id']]);
                if ($d['stored_path'] !== '') @unlink(atlas_config()['storage'] . '/' . $d['stored_path']);
                $n++;
            }
            $this->db->run('DELETE FROM collections WHERE id = ?', [$cid]);
        }
        $ids = json_decode((string)($this->db->val("SELECT v FROM settings WHERE k = 'tutorial.demo_tests'") ?? '[]'), true) ?: [];
        if ($ids) $this->db->run('DELETE FROM golden_tests WHERE id IN (' . implode(',', array_map('intval', $ids)) . ')');
        $this->db->run("DELETE FROM settings WHERE k = 'tutorial.demo_tests'");
        (new Maintenance())->cleanOrphans();
        $this->db->log('demo_removed', null, null, "$n demo documents");
        return ['removed' => $n];
    }
}
