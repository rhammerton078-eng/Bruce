<?php
declare(strict_types=1);

namespace Atlas;

/** Knowledge-graph writes and reads (entities, aliases, relations). */
final class Graph
{
    public const TYPES = ['person', 'organization', 'place', 'concept', 'technology', 'event', 'work', 'product', 'metric', 'other'];

    public static function norm(string $name): string
    {
        $n = mb_strtolower(trim($name));
        $n = preg_replace('/^(the|a|an)\s+/u', '', $n) ?? $n;
        $n = preg_replace('/[^\p{L}\p{N}]+/u', '', $n) ?? $n;
        // naive singularisation for plain plurals ("networks" -> "network")
        if (mb_strlen($n) > 4 && str_ends_with($n, 's') && !str_ends_with($n, 'ss')) $n = mb_substr($n, 0, -1);
        return mb_substr($n, 0, 250);
    }

    /** Finds or creates an entity; returns [id, created]. */
    public static function upsertEntity(string $name, string $type = 'concept', ?string $desc = null, ?int $docId = null): array
    {
        $db = Db::get();
        $name = trim(mb_substr(preg_replace('/\s+/u', ' ', $name) ?? $name, 0, 250));
        $key = self::norm($name);
        if ($key === '') return [0, false];
        $type = in_array($type = mb_strtolower($type), self::TYPES, true) ? $type : 'other';

        $id = $db->val('SELECT id FROM entities WHERE norm_key = ?', [$key])
            ?? $db->val('SELECT entity_id FROM entity_aliases WHERE norm_key = ?', [$key]);
        if ($id) {
            $id = (int)$id;
            if ($desc) {
                // keep the richest description, and remember the old one
                $old = $db->val('SELECT description FROM entities WHERE id = ?', [$id]);
                if ($old === null || mb_strlen((string)$old) < mb_strlen($desc)) {
                    $db->run('UPDATE entities SET description = ? WHERE id = ?', [$desc, $id]);
                    if ($old !== null) Truth::version('entity', $id, 'description', (string)$old, $desc, $docId ? "Richer description from document #$docId" : null);
                }
            }
            $canonical = $db->val('SELECT name FROM entities WHERE id = ?', [$id]);
            if ($canonical !== $name) {
                $db->run('INSERT IGNORE INTO entity_aliases (entity_id, alias, norm_key) VALUES (?,?,?)', [$id, $name, $key]);
            }
            return [$id, false];
        }
        $db->run('INSERT INTO entities (name, norm_key, type, description) VALUES (?,?,?,?)', [$name, $key, $type, $desc]);
        $id = $db->id();
        $db->log('entity_created', $docId, $id, $name);
        return [$id, true];
    }

    public static function addRelation(int $src, int $tgt, string $type, string $origin = 'extracted', ?string $evidence = null, ?int $chunkId = null, float $conf = 0.6): bool
    {
        if ($src === $tgt || !$src || !$tgt) return false;
        $type = mb_substr(trim(preg_replace('/\s+/', '_', mb_strtolower($type)) ?? 'related_to', '_'), 0, 80) ?: 'related_to';
        $db = Db::get();
        $st = $db->run(
            'INSERT INTO relations (source_id, target_id, type, origin, evidence, chunk_id, confidence) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE weight = weight + 1, confidence = LEAST(0.99, confidence + 0.05),
             evidence = COALESCE(evidence, VALUES(evidence))',
            [$src, $tgt, $type, $origin, $evidence ? mb_substr($evidence, 0, 1000) : null, $chunkId, $conf]
        );
        return $st->rowCount() === 1; // 1 = inserted, 2 = updated
    }

    public static function mention(int $entityId, int $chunkId): void
    {
        $db = Db::get();
        if ($db->run('INSERT IGNORE INTO entity_mentions (entity_id, chunk_id) VALUES (?,?)', [$entityId, $chunkId])->rowCount()) {
            $db->run('UPDATE entities SET mention_count = mention_count + 1 WHERE id = ?', [$entityId]);
        }
    }

    /** Graph payload for the explorer. */
    public static function export(array $f = []): array
    {
        $db = Db::get();
        $limit = max(10, min(3000, (int)($f['limit'] ?? 600)));
        $where = ['1=1'];
        $p = [];
        if (!empty($f['type'])) { $where[] = 'e.type = ?'; $p[] = $f['type']; }
        if (!empty($f['collection'])) {
            $where[] = 'e.id IN (SELECT em.entity_id FROM entity_mentions em JOIN chunks c ON c.id = em.chunk_id JOIN documents d ON d.id = c.document_id WHERE d.collection_id = ?)';
            $p[] = (int)$f['collection'];
        }
        if (!empty($f['document'])) {
            $where[] = 'e.id IN (SELECT em.entity_id FROM entity_mentions em JOIN chunks c ON c.id = em.chunk_id WHERE c.document_id = ?)';
            $p[] = (int)$f['document'];
        }
        if (!empty($f['since'])) { $where[] = 'e.first_seen >= ?'; $p[] = $f['since']; }
        $nodes = $db->all(
            'SELECT e.id, e.name, e.type, e.mention_count AS mentions, e.cluster_id AS cluster,
                    (SELECT COUNT(*) FROM relations r WHERE r.source_id = e.id OR r.target_id = e.id) AS degree
             FROM entities e WHERE ' . implode(' AND ', $where) . '
             ORDER BY (e.mention_count + (SELECT COUNT(*) FROM relations r2 WHERE r2.source_id = e.id OR r2.target_id = e.id)) DESC
             LIMIT ' . $limit, $p);
        $ids = array_map(fn($n) => (int)$n['id'], $nodes);
        $links = [];
        if ($ids) {
            $in = implode(',', $ids);
            $minConf = (float)($f['min_conf'] ?? 0);
            $links = $db->all("SELECT id, source_id AS source, target_id AS target, type, origin, weight, confidence
                               FROM relations WHERE source_id IN ($in) AND target_id IN ($in) AND confidence >= ?", [$minConf]);
        }
        foreach ($nodes as &$n) { $n['id'] = (int)$n['id']; $n['mentions'] = (int)$n['mentions']; $n['degree'] = (int)$n['degree']; $n['cluster'] = $n['cluster'] !== null ? (int)$n['cluster'] : null; }
        foreach ($links as &$l) { $l['source'] = (int)$l['source']; $l['target'] = (int)$l['target']; $l['weight'] = (int)$l['weight']; $l['confidence'] = (float)$l['confidence']; }
        return ['nodes' => $nodes, 'links' => $links];
    }

    public static function entityDetail(int $id): ?array
    {
        $db = Db::get();
        $e = $db->one('SELECT id, name, type, description, mention_count, cluster_id, confidence, first_seen, updated_at FROM entities WHERE id = ?', [$id]);
        if (!$e) return null;
        $e['aliases'] = array_column($db->all('SELECT alias FROM entity_aliases WHERE entity_id = ? ORDER BY alias', [$id]), 'alias');
        $e['relations'] = $db->all(
            "SELECT r.id, r.type, r.origin, r.weight, r.confidence, r.evidence,
                    IF(r.source_id = ?, 'out', 'in') AS direction,
                    o.id AS other_id, o.name AS other_name, o.type AS other_type,
                    c.document_id, c.page, d.title AS doc_title
             FROM relations r
             JOIN entities o ON o.id = IF(r.source_id = ?, r.target_id, r.source_id)
             LEFT JOIN chunks c ON c.id = r.chunk_id
             LEFT JOIN documents d ON d.id = c.document_id
             WHERE r.source_id = ? OR r.target_id = ?
             ORDER BY r.weight DESC, r.confidence DESC LIMIT 80", [$id, $id, $id, $id]);
        $e['sources'] = $db->all(
            'SELECT d.id AS document_id, d.title, d.kind, COUNT(*) AS mentions, MIN(c.page) AS first_page
             FROM entity_mentions em JOIN chunks c ON c.id = em.chunk_id JOIN documents d ON d.id = c.document_id
             WHERE em.entity_id = ? GROUP BY d.id, d.title, d.kind ORDER BY mentions DESC LIMIT 30', [$id]);
        $e['snippets'] = $db->all(
            'SELECT c.id AS chunk_id, c.page, d.id AS document_id, d.title, SUBSTRING(c.content, 1, 420) AS text
             FROM entity_mentions em JOIN chunks c ON c.id = em.chunk_id JOIN documents d ON d.id = c.document_id
             WHERE em.entity_id = ? ORDER BY d.trust DESC, c.id LIMIT 6', [$id]);
        return $e;
    }

    /** Shortest path between two entities (BFS over relations, max depth 5). */
    public static function path(int $from, int $to, int $maxDepth = 5): array
    {
        $db = Db::get();
        $prev = [$from => null];
        $frontier = [$from];
        for ($d = 0; $d < $maxDepth && $frontier && !isset($prev[$to]); $d++) {
            $in = implode(',', array_map('intval', $frontier));
            $rows = $db->all("SELECT source_id, target_id, type FROM relations WHERE source_id IN ($in) OR target_id IN ($in)");
            $next = [];
            foreach ($rows as $r) {
                foreach ([[$r['source_id'], $r['target_id']], [$r['target_id'], $r['source_id']]] as [$a, $b]) {
                    $a = (int)$a; $b = (int)$b;
                    if (in_array($a, $frontier, true) && !array_key_exists($b, $prev)) { $prev[$b] = [$a, $r['type']]; $next[] = $b; }
                }
            }
            $frontier = $next;
        }
        if (!array_key_exists($to, $prev)) return [];
        $steps = [];
        for ($cur = $to; $prev[$cur] !== null; $cur = $prev[$cur][0]) $steps[] = ['from' => $prev[$cur][0], 'to' => $cur, 'type' => $prev[$cur][1]];
        $steps = array_reverse($steps);
        $ids = array_unique(array_merge([$from], array_column($steps, 'to')));
        $names = [];
        foreach ($db->all('SELECT id, name FROM entities WHERE id IN (' . implode(',', $ids) . ')') as $r) $names[(int)$r['id']] = $r['name'];
        foreach ($steps as &$s) { $s['from_name'] = $names[$s['from']] ?? ''; $s['to_name'] = $names[$s['to']] ?? ''; }
        return $steps;
    }
}
