<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * Phase 5 — Truth engine.
 * Claims, contradiction detection, fact history, review queue, entity merges (with undo),
 * merge suggestions and hypothesis discovery.
 */
final class Truth
{
    private Db $db;

    public function __construct(private ?Ollama $llm = null)
    {
        $this->db = Db::get();
        $this->llm ??= new Ollama();
    }

    // ------------------------------------------------------------------ history

    public static function version(string $type, int $id, string $field, ?string $old, ?string $new, ?string $reason = null): void
    {
        if ($old === $new) return;
        Db::get()->run('INSERT INTO fact_versions (object_type, object_id, field, old_value, new_value, reason) VALUES (?,?,?,?,?,?)',
            [$type, $id, $field, $old !== null ? mb_substr($old, 0, 5000) : null, $new !== null ? mb_substr($new, 0, 5000) : null, $reason ? mb_substr($reason, 0, 250) : null]);
    }

    public function history(string $type, int $id, int $limit = 30): array
    {
        return $this->db->all('SELECT field, old_value, new_value, reason, created_at FROM fact_versions WHERE object_type = ? AND object_id = ? ORDER BY id DESC LIMIT ' . $limit, [$type, $id]);
    }

    // ------------------------------------------------------------------ evidence check

    private static function normText(string $t): string
    {
        return trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($t)) ?? '') ?? '');
    }

    /** True when the quoted evidence really appears (approximately) in the passage. */
    public static function evidenceFound(?string $evidence, string $passage): bool
    {
        if ($evidence === null || trim($evidence) === '') return true; // nothing to verify
        $e = self::normText($evidence);
        $p = self::normText($passage);
        if ($e === '' || str_contains($p, mb_substr($e, 0, 40))) return true;
        $words = array_unique(array_filter(explode(' ', $e), fn($w) => mb_strlen($w) > 3));
        if (!$words) return true;
        $hit = 0;
        foreach ($words as $w) if (str_contains($p, $w)) $hit++;
        return $hit / count($words) >= 0.6;
    }

    // ------------------------------------------------------------------ claims

    /** Stores claims extracted from a chunk. $map: normalized entity name => id. */
    public function saveClaims(array $claims, array $map, ?int $chunkId, ?int $docId, string $origin = 'extracted', float $conf = 0.6): int
    {
        $n = 0;
        foreach ($claims as $c) {
            $subjectId = $map[Graph::norm($c['subject'])] ?? null;
            if (!$subjectId) {
                $subjectId = $this->db->val('SELECT id FROM entities WHERE norm_key = ?', [Graph::norm($c['subject'])])
                    ?? $this->db->val('SELECT entity_id FROM entity_aliases WHERE norm_key = ?', [Graph::norm($c['subject'])]);
            }
            $this->db->run('INSERT INTO claims (chunk_id, document_id, subject_id, subject, predicate, object, statement, confidence, origin) VALUES (?,?,?,?,?,?,?,?,?)',
                [$chunkId, $docId, $subjectId ? (int)$subjectId : null, mb_substr($c['subject'], 0, 250), mb_substr($c['predicate'], 0, 120), $c['object'], $c['statement'], $conf, $origin]);
            $n++;
        }
        return $n;
    }

    public function uncheckedCount(): int
    {
        return (int)$this->db->val('SELECT COUNT(*) FROM claims WHERE checked = 0');
    }

    /** Checks the next unchecked claims for contradictions/support. Returns number checked. */
    public function checkNext(int $limit = 3): int
    {
        $rows = $this->db->all('SELECT * FROM claims WHERE checked = 0 ORDER BY id LIMIT ' . $limit);
        foreach ($rows as $c) {
            try {
                $this->checkClaim($c);
            } finally {
                $this->db->run('UPDATE claims SET checked = 1 WHERE id = ?', [$c['id']]);
            }
        }
        return count($rows);
    }

    private function candidates(array $c): array
    {
        $p = [$c['id'], $c['chunk_id'] ?? 0];
        // compare only against claims that were already fact-checked: each pair is examined exactly once
        $where = "id <> ? AND COALESCE(chunk_id, 0) <> ? AND checked = 1 AND status IN ('active','disputed')";
        $bySubject = $c['subject_id'] ? $this->db->all("SELECT * FROM claims WHERE $where AND subject_id = ? ORDER BY id DESC LIMIT 6", [...$p, $c['subject_id']]) : [];
        $byText = $this->db->all("SELECT * FROM claims WHERE $where AND MATCH(statement) AGAINST (?) ORDER BY MATCH(statement) AGAINST (?) DESC LIMIT 4",
            [...$p, $c['statement'], $c['statement']]);
        $all = [];
        foreach (array_merge($bySubject, $byText) as $r) $all[(int)$r['id']] = $r;
        return array_slice($all, 0, 8, true);
    }

    public function checkClaim(array $c): array
    {
        $cands = $this->candidates($c);
        if (!$cands) return [];
        $list = '';
        foreach ($cands as $id => $r) $list .= "[$id] {$r['statement']}\n";
        $j = $this->llm->chatJson([
            ['role' => 'system', 'content' => 'You compare factual claims. Two claims CONTRADICT only if they cannot both be true about the same thing at the same time (watch for different dates, scopes or contexts — those are not contradictions). SUPPORT means they state the same fact. Otherwise UNRELATED. Return JSON: {"results":[{"id":number,"relation":"contradicts|supports|unrelated","reason":"short explanation"}]}'],
            ['role' => 'user', 'content' => "New claim: {$c['statement']}\n\nExisting claims:\n$list"],
        ]);
        $out = [];
        foreach (($j['results'] ?? []) as $r) {
            $oid = (int)($r['id'] ?? 0);
            if (!isset($cands[$oid])) continue;
            $rel = strtolower((string)($r['relation'] ?? ''));
            $reason = mb_substr((string)($r['reason'] ?? ''), 0, 500);
            if ($rel === 'supports') {
                $this->db->run('UPDATE claims SET support = support + 1, confidence = LEAST(0.99, confidence + 0.05) WHERE id IN (?, ?)', [$c['id'], $oid]);
                $out[] = ['id' => $oid, 'relation' => 'supports'];
            } elseif ($rel === 'contradicts') {
                $this->addContradiction((int)$c['id'], $oid, $reason, $c, $cands[$oid]);
                $out[] = ['id' => $oid, 'relation' => 'contradicts'];
            }
        }
        return $out;
    }

    private function addContradiction(int $newId, int $oldId, string $reason, array $new, array $old): void
    {
        [$a, $b] = [min($oldId, $newId), max($oldId, $newId)]; // a = older claim, b = newer
        if ($a !== $oldId) [$new, $old] = [$old, $new];
        $ins = $this->db->run('INSERT IGNORE INTO contradictions (claim_a, claim_b, reason) VALUES (?,?,?)', [$a, $b, $reason]);
        if (!$ins->rowCount()) return;
        $cid = $this->db->id();
        // Facts taught by the user win automatically
        if ($new['origin'] === 'user' && $old['origin'] !== 'user') { $this->resolve($cid, 'b_correct', 'Corrected by you'); return; }
        if ($old['origin'] === 'user' && $new['origin'] !== 'user') { $this->resolve($cid, 'a_correct', 'Contradicts a fact you taught'); return; }
        $this->db->run("UPDATE claims SET status = 'disputed' WHERE id IN (?, ?) AND status = 'active'", [$a, $b]);
        self::version('claim', $a, 'status', 'active', 'disputed', 'Contradiction #' . $cid);
        self::version('claim', $b, 'status', 'active', 'disputed', 'Contradiction #' . $cid);
        $this->db->log('contradiction_found', isset($new['document_id']) ? (int)$new['document_id'] : null, null, mb_substr($old['statement'] . '  ⟷  ' . $new['statement'], 0, 480));
    }

    public function resolve(int $id, string $decision, ?string $why = null): void
    {
        if (!in_array($decision, ['a_correct', 'b_correct', 'both_valid', 'ignored'], true)) throw new RuntimeException('Invalid decision');
        $con = $this->db->one('SELECT * FROM contradictions WHERE id = ?', [$id]);
        if (!$con) throw new RuntimeException('Not found');
        $this->db->run('UPDATE contradictions SET status = ?, resolved_at = NOW() WHERE id = ?', [$decision, $id]);
        $set = function (int $claim, string $status) use ($decision, $why, $id) {
            $old = (string)$this->db->val('SELECT status FROM claims WHERE id = ?', [$claim]);
            $this->db->run('UPDATE claims SET status = ? WHERE id = ?', [$status, $claim]);
            self::version('claim', $claim, 'status', $old, $status, $why ?? "Contradiction #$id: $decision");
        };
        $a = (int)$con['claim_a']; $b = (int)$con['claim_b'];
        match ($decision) {
            'a_correct' => [$set($a, 'active'), $set($b, 'rejected')],
            'b_correct' => [$set($b, 'active'), $set($a, 'superseded')],
            'both_valid', 'ignored' => [$set($a, 'active'), $set($b, 'active')],
        };
        // A rejected claim lowers trust in the relations from the same passage a little
        $lost = $decision === 'a_correct' ? $b : ($decision === 'b_correct' ? $a : 0);
        if ($lost) $this->db->run('UPDATE relations r JOIN claims c ON c.chunk_id = r.chunk_id SET r.confidence = GREATEST(0.1, r.confidence - 0.1) WHERE c.id = ?', [$lost]);
        $this->db->log('contradiction_resolved', null, null, "#$id → $decision");
    }

    public function contradictions(string $status = 'open', int $limit = 100): array
    {
        $rows = $this->db->all("SELECT x.id, x.reason, x.status, x.created_at, x.resolved_at,
                a.id AS a_id, a.statement AS a_statement, a.origin AS a_origin, a.status AS a_status, a.chunk_id AS a_chunk, da.id AS a_doc, da.title AS a_title, ca.page AS a_page, da.trust AS a_trust,
                b.id AS b_id, b.statement AS b_statement, b.origin AS b_origin, b.status AS b_status, b.chunk_id AS b_chunk, db.id AS b_doc, db.title AS b_title, cb.page AS b_page, db.trust AS b_trust
            FROM contradictions x
            JOIN claims a ON a.id = x.claim_a JOIN claims b ON b.id = x.claim_b
            LEFT JOIN documents da ON da.id = a.document_id LEFT JOIN documents db ON db.id = b.document_id
            LEFT JOIN chunks ca ON ca.id = a.chunk_id LEFT JOIN chunks cb ON cb.id = b.chunk_id
            WHERE " . ($status === 'all' ? '1=1' : ($status === 'resolved' ? "x.status <> 'open'" : "x.status = 'open'")) . "
            ORDER BY x.id DESC LIMIT $limit");
        return $rows;
    }

    /** Disputes / rejections affecting a set of chunks (used to warn Mnemo while answering). */
    public function notesForChunks(array $chunkIds): array
    {
        if (!$chunkIds) return [];
        $in = implode(',', array_map('intval', $chunkIds));
        return $this->db->all("SELECT chunk_id, statement, status FROM claims WHERE chunk_id IN ($in) AND status IN ('disputed','rejected','superseded') LIMIT 20");
    }

    // ------------------------------------------------------------------ review queue

    public function queue(string $kind, int $a, ?int $b, string $reason, ?float $score = null): void
    {
        $this->db->run('INSERT IGNORE INTO review_queue (kind, ref_a, ref_b, reason, score) VALUES (?,?,?,?,?)', [$kind, $a, $b ?? 0, mb_substr($reason, 0, 500), $score]);
    }

    public function reviewItems(string $kind, int $limit = 100): array
    {
        if ($kind === 'relation') {
            return $this->db->all("SELECT q.id, q.reason, q.score, q.created_at, r.id AS relation_id, r.type, r.evidence, r.confidence,
                    s.id AS s_id, s.name AS s_name, t.id AS t_id, t.name AS t_name, c.id AS chunk_id, c.page, d.title AS doc_title, SUBSTRING(c.content, 1, 400) AS passage
                FROM review_queue q JOIN relations r ON r.id = q.ref_a JOIN entities s ON s.id = r.source_id JOIN entities t ON t.id = r.target_id
                LEFT JOIN chunks c ON c.id = r.chunk_id LEFT JOIN documents d ON d.id = c.document_id
                WHERE q.kind = 'relation' AND q.status = 'pending' ORDER BY q.id DESC LIMIT $limit");
        }
        if ($kind === 'merge') {
            return $this->db->all("SELECT q.id, q.reason, q.score, q.created_at, a.id AS a_id, a.name AS a_name, a.type AS a_type, a.description AS a_desc, a.mention_count AS a_mentions,
                    b.id AS b_id, b.name AS b_name, b.type AS b_type, b.description AS b_desc, b.mention_count AS b_mentions
                FROM review_queue q JOIN entities a ON a.id = q.ref_a JOIN entities b ON b.id = q.ref_b
                WHERE q.kind = 'merge' AND q.status = 'pending' ORDER BY q.score DESC, q.id DESC LIMIT $limit");
        }
        return [];
    }

    public function decide(int $id, bool $approve): void
    {
        $q = $this->db->one('SELECT * FROM review_queue WHERE id = ?', [$id]);
        if (!$q || $q['status'] !== 'pending') throw new RuntimeException('Item already decided');
        if ($q['kind'] === 'relation') {
            $rel = $this->db->one('SELECT * FROM relations WHERE id = ?', [$q['ref_a']]);
            if ($rel) {
                if ($approve) {
                    $this->db->run('UPDATE relations SET confidence = 0.9 WHERE id = ?', [$rel['id']]);
                    self::version('relation', (int)$rel['id'], 'confidence', (string)$rel['confidence'], '0.9', 'Approved by you');
                } else {
                    self::version('relation', (int)$rel['id'], 'deleted', $rel['source_id'] . ' ' . $rel['type'] . ' ' . $rel['target_id'], null, 'Rejected by you: ' . ($rel['evidence'] ?? ''));
                    $this->db->run('DELETE FROM relations WHERE id = ?', [$rel['id']]);
                }
            }
        } elseif ($q['kind'] === 'merge' && $approve) {
            $this->merge((int)$q['ref_a'], (int)$q['ref_b'], 'Approved merge suggestion');
        }
        $this->db->run('UPDATE review_queue SET status = ?, decided_at = NOW() WHERE id = ?', [$approve ? 'approved' : 'rejected', $id]);
    }

    // ------------------------------------------------------------------ merging

    /** Merges entity $mergeId into $keepId. Fully reversible via undoMerge(). */
    public function merge(int $keepId, int $mergeId, string $reason = 'Merged by you'): int
    {
        if ($keepId === $mergeId) throw new RuntimeException('Cannot merge a concept into itself');
        $db = $this->db;
        $keep = $db->one('SELECT * FROM entities WHERE id = ?', [$keepId]);
        $m = $db->one('SELECT * FROM entities WHERE id = ?', [$mergeId]);
        if (!$keep || !$m) throw new RuntimeException('Concept not found');
        $db->pdo->beginTransaction();
        try {
            $snap = [
                'entity' => array_merge($m, ['vec' => $m['vec'] !== null ? bin2hex($m['vec']) : null]),
                'keep_description' => $keep['description'],
                'aliases' => $db->all('SELECT id, alias, norm_key FROM entity_aliases WHERE entity_id = ?', [$mergeId]),
                'mentions' => array_map('intval', array_column($db->all('SELECT chunk_id FROM entity_mentions WHERE entity_id = ?', [$mergeId]), 'chunk_id')),
                'keep_mentions' => array_map('intval', array_column($db->all('SELECT chunk_id FROM entity_mentions WHERE entity_id = ?', [$keepId]), 'chunk_id')),
                'relations' => $db->all('SELECT * FROM relations WHERE source_id = ? OR target_id = ?', [$mergeId, $mergeId]),
                'claims' => array_map('intval', array_column($db->all('SELECT id FROM claims WHERE subject_id = ?', [$mergeId]), 'id')),
                'moved' => [],
            ];
            // aliases
            $db->run('UPDATE entity_aliases SET entity_id = ? WHERE entity_id = ?', [$keepId, $mergeId]);
            $db->run('INSERT IGNORE INTO entity_aliases (entity_id, alias, norm_key) VALUES (?,?,?)', [$keepId, $m['name'], $m['norm_key']]);
            $snap['added_alias'] = $m['norm_key'];
            // mentions
            $db->run('INSERT IGNORE INTO entity_mentions (entity_id, chunk_id) SELECT ?, chunk_id FROM entity_mentions WHERE entity_id = ?', [$keepId, $mergeId]);
            // relations
            foreach ($snap['relations'] as $r) {
                $s = (int)$r['source_id'] === $mergeId ? $keepId : (int)$r['source_id'];
                $t = (int)$r['target_id'] === $mergeId ? $keepId : (int)$r['target_id'];
                if ($s === $t) continue;
                $existing = $db->one('SELECT id FROM relations WHERE source_id = ? AND target_id = ? AND type = ?', [$s, $t, $r['type']]);
                if ($existing) {
                    $db->run('UPDATE relations SET weight = weight + ? WHERE id = ?', [$r['weight'], $existing['id']]);
                    $snap['moved'][] = ['op' => 'weight', 'id' => (int)$existing['id'], 'by' => (int)$r['weight']];
                } else {
                    $db->run('INSERT INTO relations (source_id, target_id, type, origin, weight, confidence, evidence, chunk_id) VALUES (?,?,?,?,?,?,?,?)',
                        [$s, $t, $r['type'], $r['origin'], $r['weight'], $r['confidence'], $r['evidence'], $r['chunk_id']]);
                    $snap['moved'][] = ['op' => 'insert', 'id' => $db->id()];
                }
            }
            $db->run('UPDATE claims SET subject_id = ? WHERE subject_id = ?', [$keepId, $mergeId]);
            if ($m['description'] && mb_strlen((string)$m['description']) > mb_strlen((string)$keep['description'])) {
                $db->run('UPDATE entities SET description = ? WHERE id = ?', [$m['description'], $keepId]);
            }
            $db->run('DELETE FROM entities WHERE id = ?', [$mergeId]);
            $db->run('UPDATE entities SET mention_count = (SELECT COUNT(*) FROM entity_mentions WHERE entity_id = ?) WHERE id = ?', [$keepId, $keepId]);
            $db->run('INSERT INTO entity_merges (kept_id, merged_id, merged_name, snapshot) VALUES (?,?,?,?)', [$keepId, $mergeId, $m['name'], json_encode($snap, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)]);
            $mid = $db->id();
            self::version('entity', $keepId, 'merged', null, $m['name'], $reason);
            $db->pdo->commit();
        } catch (\Throwable $e) {
            $db->pdo->rollBack();
            throw $e;
        }
        $db->log('entity_merged', null, $keepId, $m['name'] . ' → ' . $keep['name']);
        return $mid;
    }

    public function undoMerge(int $mergeLogId): void
    {
        $db = $this->db;
        $row = $db->one('SELECT * FROM entity_merges WHERE id = ? AND undone_at IS NULL', [$mergeLogId]);
        if (!$row) throw new RuntimeException('Merge not found or already undone');
        $snap = json_decode($row['snapshot'], true);
        $e = $snap['entity'];
        $keepId = (int)$row['kept_id'];
        if (!$db->val('SELECT 1 FROM entities WHERE id = ?', [$keepId])) throw new RuntimeException('The kept concept no longer exists');
        if ($db->val('SELECT 1 FROM entities WHERE norm_key = ?', [$e['norm_key']])) throw new RuntimeException('A concept with that name exists again; cannot undo');
        $db->pdo->beginTransaction();
        try {
            $db->run('INSERT INTO entities (id, name, norm_key, type, description, mention_count, cluster_id, confidence, vec, first_seen) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$e['id'], $e['name'], $e['norm_key'], $e['type'], $e['description'], $e['mention_count'], $e['cluster_id'], $e['confidence'], $e['vec'] !== null ? hex2bin($e['vec']) : null, $e['first_seen']]);
            $db->run('DELETE FROM entity_aliases WHERE norm_key = ? AND entity_id = ?', [$snap['added_alias'], $keepId]);
            foreach ($snap['aliases'] as $a) $db->run('UPDATE entity_aliases SET entity_id = ? WHERE id = ?', [$e['id'], $a['id']]);
            foreach ($snap['mentions'] as $c) {
                $db->run('INSERT IGNORE INTO entity_mentions (entity_id, chunk_id) VALUES (?,?)', [$e['id'], $c]);
                if (!in_array($c, $snap['keep_mentions'], true)) $db->run('DELETE FROM entity_mentions WHERE entity_id = ? AND chunk_id = ?', [$keepId, $c]);
            }
            foreach ($snap['moved'] as $mv) {
                if ($mv['op'] === 'insert') $db->run('DELETE FROM relations WHERE id = ?', [$mv['id']]);
                else $db->run('UPDATE relations SET weight = GREATEST(1, weight - ?) WHERE id = ?', [$mv['by'], $mv['id']]);
            }
            foreach ($snap['relations'] as $r) {
                $db->run('INSERT IGNORE INTO relations (id, source_id, target_id, type, origin, weight, confidence, evidence, chunk_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$r['id'], $r['source_id'], $r['target_id'], $r['type'], $r['origin'], $r['weight'], $r['confidence'], $r['evidence'], $r['chunk_id'], $r['created_at']]);
            }
            if ($snap['claims']) $db->run('UPDATE claims SET subject_id = ? WHERE id IN (' . implode(',', array_map('intval', $snap['claims'])) . ')', [$e['id']]);
            $db->run('UPDATE entities SET description = ? WHERE id = ?', [$snap['keep_description'], $keepId]);
            $db->run('UPDATE entities SET mention_count = (SELECT COUNT(*) FROM entity_mentions WHERE entity_id = entities.id) WHERE id IN (?, ?)', [$keepId, $e['id']]);
            $db->run('UPDATE entity_merges SET undone_at = NOW() WHERE id = ?', [$mergeLogId]);
            self::version('entity', $keepId, 'unmerged', $e['name'], null, 'Merge undone');
            $db->pdo->commit();
        } catch (\Throwable $ex) {
            $db->pdo->rollBack();
            throw $ex;
        }
        $db->log('merge_undone', null, (int)$e['id'], $e['name']);
    }

    public function recentMerges(int $limit = 20): array
    {
        return $this->db->all('SELECT m.id, m.merged_name, m.created_at, m.undone_at, e.id AS kept_id, e.name AS kept_name
            FROM entity_merges m LEFT JOIN entities e ON e.id = m.kept_id ORDER BY m.id DESC LIMIT ' . $limit);
    }

    /** Finds likely duplicate concepts and queues them for review. */
    public function suggestMerges(float $threshold = 0.93): int
    {
        $rows = $this->db->all('SELECT id, name, norm_key, type, mention_count, vec FROM entities ORDER BY mention_count DESC LIMIT 1500');
        $added = 0;
        $vecs = [];
        foreach ($rows as $r) if ($r['vec'] !== null) $vecs[(int)$r['id']] = Vector::unpack($r['vec']);
        $n = count($rows);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $rows[$i]; $b = $rows[$j];
                $ka = $a['norm_key']; $kb = $b['norm_key'];
                $score = 0.0; $why = '';
                $short = mb_strlen($ka) <= mb_strlen($kb) ? $ka : $kb;
                $long = $short === $ka ? $kb : $ka;
                if (mb_strlen($short) >= 5 && str_contains($long, $short)) { $score = 0.9; $why = 'One name contains the other'; }
                elseif (mb_strlen($short) >= 6 && levenshtein(substr($ka, 0, 255), substr($kb, 0, 255)) <= 2) { $score = 0.92; $why = 'Nearly identical spelling'; }
                elseif (isset($vecs[(int)$a['id']], $vecs[(int)$b['id']]) && $a['type'] === $b['type']) {
                    $s = Vector::dot($vecs[(int)$a['id']], $vecs[(int)$b['id']]);
                    if ($s >= $threshold) { $score = $s; $why = 'Very similar meaning (' . round($s * 100) . '%)'; }
                }
                if ($score > 0) {
                    // keep the more-mentioned entity as ref_a (the one that stays)
                    [$keep, $merge] = (int)$a['mention_count'] >= (int)$b['mention_count'] ? [$a, $b] : [$b, $a];
                    $exists = $this->db->val("SELECT 1 FROM review_queue WHERE kind = 'merge' AND ((ref_a = ? AND ref_b = ?) OR (ref_a = ? AND ref_b = ?))", [$keep['id'], $merge['id'], $merge['id'], $keep['id']]);
                    if (!$exists) { $this->queue('merge', (int)$keep['id'], (int)$merge['id'], $why, round($score, 3)); $added++; }
                }
            }
        }
        return $added;
    }

    // ------------------------------------------------------------------ hypotheses

    /** Unlinked concept pairs sharing several neighbours (Adamic–Adar score). */
    public function discoverHypotheses(int $minShared = 2, int $max = 25): int
    {
        $adj = [];
        foreach ($this->db->all('SELECT source_id, target_id FROM relations') as $r) {
            $a = (int)$r['source_id']; $b = (int)$r['target_id'];
            $adj[$a][$b] = true; $adj[$b][$a] = true;
        }
        $scores = [];
        foreach ($adj as $z => $nbrs) {
            $deg = count($nbrs);
            if ($deg < 2 || $deg > 200) continue;
            $ids = array_keys($nbrs);
            $w = 1 / log($deg + 1);
            $c = count($ids);
            for ($i = 0; $i < $c; $i++) for ($j = $i + 1; $j < $c; $j++) {
                [$x, $y] = $ids[$i] < $ids[$j] ? [$ids[$i], $ids[$j]] : [$ids[$j], $ids[$i]];
                if (isset($adj[$x][$y])) continue;
                $scores["$x-$y"]['s'] = ($scores["$x-$y"]['s'] ?? 0) + $w;
                $scores["$x-$y"]['z'][] = $z;
            }
        }
        $scores = array_filter($scores, fn($v) => count($v['z']) >= $minShared);
        uasort($scores, fn($a, $b) => $b['s'] <=> $a['s']);
        $added = 0;
        foreach (array_slice($scores, 0, $max, true) as $k => $v) {
            [$x, $y] = array_map('intval', explode('-', $k));
            $st = $this->db->run('INSERT IGNORE INTO hypotheses (entity_a, entity_b, score, shared) VALUES (?,?,?,?)', [$x, $y, round($v['s'], 3), json_encode(array_slice($v['z'], 0, 12))]);
            $added += $st->rowCount();
        }
        return $added;
    }

    public function hypotheses(int $limit = 50): array
    {
        $rows = $this->db->all("SELECT h.id, h.score, h.shared, h.created_at, a.id AS a_id, a.name AS a_name, a.type AS a_type, b.id AS b_id, b.name AS b_name, b.type AS b_type
            FROM hypotheses h JOIN entities a ON a.id = h.entity_a JOIN entities b ON b.id = h.entity_b WHERE h.status = 'pending' ORDER BY h.score DESC LIMIT $limit");
        foreach ($rows as &$r) {
            $ids = array_map('intval', json_decode((string)$r['shared'], true) ?: []);
            $r['shared'] = $ids ? $this->db->all('SELECT id, name FROM entities WHERE id IN (' . implode(',', $ids) . ')') : [];
        }
        return $rows;
    }

    public function decideHypothesis(int $id, bool $accept): void
    {
        $h = $this->db->one('SELECT * FROM hypotheses WHERE id = ?', [$id]);
        if (!$h) throw new RuntimeException('Not found');
        $this->db->run('UPDATE hypotheses SET status = ? WHERE id = ?', [$accept ? 'accepted' : 'rejected', $id]);
        if ($accept) {
            Graph::addRelation((int)$h['entity_a'], (int)$h['entity_b'], 'related_to', 'user', 'Hypothesis accepted by you (shared neighbours)', null, 0.8);
            $this->db->log('hypothesis_accepted', null, (int)$h['entity_a'], "#$id");
        }
    }

    public function counts(): array
    {
        return [
            'contradictions' => (int)$this->db->val("SELECT COUNT(*) FROM contradictions WHERE status = 'open'"),
            'relations' => (int)$this->db->val("SELECT COUNT(*) FROM review_queue WHERE kind = 'relation' AND status = 'pending'"),
            'merges' => (int)$this->db->val("SELECT COUNT(*) FROM review_queue WHERE kind = 'merge' AND status = 'pending'"),
            'hypotheses' => (int)$this->db->val("SELECT COUNT(*) FROM hypotheses WHERE status = 'pending'"),
        ];
    }
}
