<?php
declare(strict_types=1);

namespace Atlas;

/** Mnemo's "sleep cycle": consolidation jobs that make the graph better every day. */
final class Nightly
{
    private Db $db;
    private Ollama $llm;
    public array $log = [];

    public function __construct()
    {
        $this->db = Db::get();
        $this->llm = new Ollama();
    }

    public function run(): array
    {
        @set_time_limit(0);
        $inferred = 0;
        try { $this->embedEntities(); $inferred = $this->inferLinks(); } catch (\Throwable $e) { $this->log[] = 'Similarity links skipped: ' . $e->getMessage(); }
        $clusters = $this->cluster();
        $this->recomputeConfidence();
        $extra = $this->phaseJobs();
        $gaps = $this->gaps();
        $digest = $this->digest($inferred, $clusters, $gaps);
        $backup = null;
        try {
            if (Maintenance::setting('backup.auto', '1') === '1') { $backup = (new Maintenance())->backup(false, 'auto'); $this->log[] = "Automatic backup: $backup"; }
        } catch (\Throwable $e) { $this->log[] = 'Automatic backup failed: ' . $e->getMessage(); }
        foreach ((new Maintenance())->storage(true)['warnings'] as $w) $this->log[] = strtoupper($w['level']) . ': ' . $w['message'];
        try { $g = new Growth(); $g->checkLevelUp(); $d = $g->writeDiary(); $this->log[] = 'Diary: ' . $d['entry']; } catch (\Throwable $e) { $this->log[] = 'Diary skipped: ' . $e->getMessage(); }
        $this->db->log('nightly_run', null, null, "inferred=$inferred clusters=$clusters");
        return ['backup' => $backup, 'jobs' => $extra, 'inferred_links' => $inferred, 'clusters' => $clusters, 'gaps' => $gaps, 'digest' => $digest, 'log' => $this->log];
    }

    /** Truth engine, self-improvement and assistant jobs. Each one is isolated so a failure never stops the cycle. */
    private function phaseJobs(): array
    {
        $r = [];
        $job = function (string $name, callable $fn) use (&$r) {
            $t = microtime(true);
            try { $r[$name] = $fn(); $this->log[] = "$name: " . (is_array($r[$name]) ? json_encode($r[$name]) : $r[$name]) . sprintf(' (%.1fs)', microtime(true) - $t); }
            catch (\Throwable $e) { $r[$name] = null; $this->log[] = "$name skipped: " . $e->getMessage(); }
        };
        $truth = new Truth($this->llm);
        $job('fact_checks', function () use ($truth) { $n = 0; $end = time() + 600; while ($truth->uncheckedCount() && time() < $end) $n += $truth->checkNext(5); return $n; });
        $job('merge_suggestions', fn() => $truth->suggestMerges());
        $job('hypotheses', fn() => $truth->discoverHypotheses());
        $job('image_links', fn() => Media::linkImages());
        $job('flashcards', fn() => (new Practice())->generateFromConcepts(50));
        $job('watchlist_updates', fn() => (new Insights())->checkAll());
        $job('self_quiz', fn() => count((new Quality())->selfQuiz((int)Maintenance::setting('quality.selfquiz_n', '5'))));
        $growth = new Growth();
        $job('curiosity_questions', fn() => $growth->wonder(8));
        $job('forgotten_links', fn() => $growth->forget());
        $job('benchmark', function () { $q = new Quality(); return $this->db->val('SELECT COUNT(*) FROM golden_tests WHERE active = 1') ? $q->runTests() : 'no tests yet'; });
        return $r;
    }

    /** Embeds entities (name + description) that have no vector yet. */
    private function embedEntities(): void
    {
        $rows = $this->db->all('SELECT id, name, type, description FROM entities WHERE vec IS NULL LIMIT 2000');
        foreach (array_chunk($rows, 32) as $batch) {
            $vecs = $this->llm->embed(array_map(fn($r) => "{$r['name']} ({$r['type']}): " . ($r['description'] ?? ''), $batch));
            foreach ($batch as $i => $r) {
                if (isset($vecs[$i])) $this->db->run('UPDATE entities SET vec = ? WHERE id = ?', [Vector::pack($vecs[$i]), $r['id']]);
            }
        }
        $this->log[] = 'Embedded ' . count($rows) . ' entities';
    }

    /** Adds "similar_to" links between semantically close entities that are not yet connected. */
    private function inferLinks(float $threshold = 0.86, int $perEntity = 3): int
    {
        $rows = $this->db->all('SELECT id, vec FROM entities WHERE vec IS NOT NULL ORDER BY mention_count DESC LIMIT 1500');
        $vecs = [];
        foreach ($rows as $r) $vecs[(int)$r['id']] = Vector::unpack($r['vec']);
        $ids = array_keys($vecs);
        $added = 0;
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            $best = [];
            for ($j = 0; $j < $n; $j++) {
                if ($i === $j) continue;
                $s = Vector::dot($vecs[$ids[$i]], $vecs[$ids[$j]]);
                if ($s >= $threshold) $best[$ids[$j]] = $s;
            }
            arsort($best);
            foreach (array_slice($best, 0, $perEntity, true) as $other => $s) {
                [$a, $b] = $ids[$i] < $other ? [$ids[$i], $other] : [$other, $ids[$i]];
                $exists = $this->db->val('SELECT 1 FROM relations WHERE (source_id = ? AND target_id = ?) OR (source_id = ? AND target_id = ?) LIMIT 1', [$a, $b, $b, $a]);
                if (!$exists && Graph::addRelation($a, $b, 'similar_to', 'inferred', 'Semantic similarity ' . round($s, 3), null, round(($s - 0.5) * 2, 3))) {
                    $added++;
                }
            }
        }
        $this->log[] = "Inferred $added similarity links";
        return $added;
    }

    /** Label-propagation community detection; stores cluster_id on entities. */
    private function cluster(int $iterations = 12): int
    {
        $edges = $this->db->all('SELECT source_id, target_id, weight FROM relations');
        $adj = [];
        foreach ($edges as $e) {
            $a = (int)$e['source_id']; $b = (int)$e['target_id']; $w = (int)$e['weight'];
            $adj[$a][$b] = ($adj[$a][$b] ?? 0) + $w;
            $adj[$b][$a] = ($adj[$b][$a] ?? 0) + $w;
        }
        $label = [];
        foreach (array_keys($adj) as $n) $label[$n] = $n;
        $nodes = array_keys($adj);
        for ($it = 0; $it < $iterations; $it++) {
            shuffle($nodes);
            $changed = 0;
            foreach ($nodes as $n) {
                $count = [];
                foreach ($adj[$n] as $m => $w) $count[$label[$m]] = ($count[$label[$m]] ?? 0) + $w;
                arsort($count);
                $top = array_key_first($count);
                if ($top !== null && $top !== $label[$n]) { $label[$n] = $top; $changed++; }
            }
            if (!$changed) break;
        }
        // renumber clusters by size
        $sizes = array_count_values($label);
        arsort($sizes);
        $map = [];
        $k = 1;
        foreach (array_keys($sizes) as $l) $map[$l] = $k++;
        $this->db->run('UPDATE entities SET cluster_id = NULL');
        foreach ($label as $n => $l) $this->db->run('UPDATE entities SET cluster_id = ? WHERE id = ?', [$map[$l], $n]);
        $this->log[] = 'Found ' . count($sizes) . ' topic clusters';
        return count($sizes);
    }

    /** Entity confidence grows with the number of independent documents mentioning it. */
    private function recomputeConfidence(): void
    {
        $this->db->run('UPDATE entities e LEFT JOIN (
              SELECT em.entity_id, COUNT(DISTINCT c.document_id) AS docs
              FROM entity_mentions em JOIN chunks c ON c.id = em.chunk_id GROUP BY em.entity_id) x ON x.entity_id = e.id
            SET e.confidence = LEAST(0.99, 0.4 + 0.15 * COALESCE(x.docs, 0))');
    }

    /** Knowledge gaps: entities referenced a lot but poorly explained. */
    public function gaps(int $limit = 8): array
    {
        return $this->db->all("SELECT e.id, e.name, e.type,
                (SELECT COUNT(*) FROM relations r WHERE r.source_id = e.id OR r.target_id = e.id) AS degree, e.mention_count
            FROM entities e
            WHERE (e.description IS NULL OR CHAR_LENGTH(e.description) < 40)
            HAVING degree >= 2 ORDER BY degree DESC, e.mention_count DESC LIMIT $limit");
    }

    private function digest(int $inferred, int $clusters, array $gaps): array
    {
        $day = date('Y-m-d');
        $since = date('Y-m-d H:i:s', time() - 86400);
        $d = [
            'documents' => (int)$this->db->val("SELECT COUNT(*) FROM documents WHERE created_at >= ?", [$since]),
            'chunks' => (int)$this->db->val("SELECT COUNT(*) FROM chunks WHERE created_at >= ?", [$since]),
            'entities' => (int)$this->db->val("SELECT COUNT(*) FROM entities WHERE first_seen >= ?", [$since]),
            'relations' => (int)$this->db->val("SELECT COUNT(*) FROM relations WHERE created_at >= ? AND origin <> 'inferred'", [$since]),
        ];
        $parts = [];
        if ($gaps) $parts[] = 'Upload material explaining: ' . implode(', ', array_column(array_slice($gaps, 0, 5), 'name')) . '.';
        $c = (new Truth($this->llm))->counts();
        if ($c['contradictions']) $parts[] = "{$c['contradictions']} contradiction(s) need your decision.";
        if ($c['merges'] + $c['relations']) $parts[] = ($c['merges'] + $c['relations']) . ' item(s) waiting in Review.';
        if ($c['hypotheses']) $parts[] = "{$c['hypotheses']} possible new connection(s) to explore.";
        $notes = $parts ? implode(' ', $parts) : null;
        $this->db->run('REPLACE INTO daily_digest (day, documents, chunks, entities, relations, inferred_links, clusters, notes) VALUES (?,?,?,?,?,?,?,?)',
            [$day, $d['documents'], $d['chunks'], $d['entities'], $d['relations'], $inferred, $clusters, $notes]);
        return $d + ['day' => $day, 'inferred_links' => $inferred, 'clusters' => $clusters, 'notes' => $notes];
    }
}
