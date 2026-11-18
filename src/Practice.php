<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/** Phase 7 — Quiz mode: Mnemo teaches you back with spaced-repetition flashcards (Leitner boxes). */
final class Practice
{
    /** Days until the next review for each box (1..6). */
    public const INTERVALS = [1 => 0, 2 => 1, 3 => 3, 4 => 7, 5 => 16, 6 => 35];

    private Db $db;

    public function __construct()
    {
        $this->db = Db::get();
    }

    /** Fast cards from concepts that have a good description (no AI needed). */
    public function generateFromConcepts(int $max = 30): int
    {
        $rows = $this->db->all("SELECT e.id, e.name, e.type, e.description,
                (SELECT em.chunk_id FROM entity_mentions em WHERE em.entity_id = e.id LIMIT 1) AS chunk_id
            FROM entities e LEFT JOIN flashcards f ON f.entity_id = e.id
            WHERE f.id IS NULL AND CHAR_LENGTH(e.description) >= 40 AND e.description NOT LIKE '%is mentioned in the passage%'
            ORDER BY e.mention_count DESC LIMIT $max");
        foreach ($rows as $r) {
            $q = match ($r['type']) {
                'person' => "Who is {$r['name']}?",
                'place' => "Where / what is {$r['name']}?",
                'event' => "What happened at / during {$r['name']}?",
                default => "What is {$r['name']}?",
            };
            $this->db->run('INSERT IGNORE INTO flashcards (entity_id, chunk_id, question, answer) VALUES (?,?,?,?)', [$r['id'], $r['chunk_id'], $q, $r['description']]);
        }
        return count($rows);
    }

    /** Richer cards written by the local AI from passages. */
    public function generateFromSources(int $chunks = 3): int
    {
        $llm = new Ollama();
        $rows = $this->db->all('SELECT c.id, c.content, d.title FROM chunks c JOIN documents d ON d.id = c.document_id
            LEFT JOIN flashcards f ON f.chunk_id = c.id AND f.entity_id IS NULL WHERE f.id IS NULL AND CHAR_LENGTH(c.content) > 200
            ORDER BY RAND() LIMIT ' . max(1, $chunks));
        $n = 0;
        foreach ($rows as $c) {
            $j = $llm->chatJson([
                ['role' => 'system', 'content' => 'Create up to 3 study flashcards from the passage: clear question, short precise answer. Return JSON {"cards":[{"q":"...","a":"..."}]}'],
                ['role' => 'user', 'content' => "Document: {$c['title']}\n\n" . mb_substr($c['content'], 0, 3500)],
            ]);
            foreach (array_slice($j['cards'] ?? [], 0, 3) as $card) {
                if (empty($card['q']) || empty($card['a'])) continue;
                $this->db->run('INSERT INTO flashcards (chunk_id, question, answer) VALUES (?,?,?)', [$c['id'], mb_substr((string)$card['q'], 0, 1000), mb_substr((string)$card['a'], 0, 2000)]);
                $n++;
            }
        }
        return $n;
    }

    public function due(int $limit = 30): array
    {
        return $this->db->all('SELECT f.id, f.question, f.answer, f.box, f.reviews, f.entity_id, f.chunk_id, d.title AS source, c.page
            FROM flashcards f LEFT JOIN chunks c ON c.id = f.chunk_id LEFT JOIN documents d ON d.id = c.document_id
            WHERE f.due_at <= NOW() ORDER BY f.box, f.due_at LIMIT ' . $limit);
    }

    public function stats(): array
    {
        $boxes = array_fill(1, 6, 0);
        foreach ($this->db->all('SELECT box, COUNT(*) n FROM flashcards GROUP BY box') as $r) $boxes[(int)$r['box']] = (int)$r['n'];
        return [
            'total' => array_sum($boxes),
            'due' => (int)$this->db->val('SELECT COUNT(*) FROM flashcards WHERE due_at <= NOW()'),
            'boxes' => $boxes,
            'reviewed_today' => (int)$this->db->val("SELECT COUNT(*) FROM ingest_log WHERE event = 'card_reviewed' AND created_at >= CURDATE()"),
        ];
    }

    /** grade: again | hard | good | easy */
    public function review(int $id, string $grade): array
    {
        $c = $this->db->one('SELECT * FROM flashcards WHERE id = ?', [$id]);
        if (!$c) throw new RuntimeException('Card not found');
        $box = (int)$c['box'];
        $box = match ($grade) { 'again' => 1, 'hard' => max(1, $box), 'good' => min(6, $box + 1), 'easy' => min(6, $box + 2), default => throw new RuntimeException('Invalid grade') };
        $days = $grade === 'again' ? 0 : self::INTERVALS[$box];
        $due = $grade === 'again' ? date('Y-m-d H:i:s', time() + 60) : date('Y-m-d H:i:s', strtotime("+$days days"));
        $this->db->run('UPDATE flashcards SET box = ?, due_at = ?, reviews = reviews + 1, lapses = lapses + ? WHERE id = ?', [$box, $due, $grade === 'again' ? 1 : 0, $id]);
        $this->db->log('card_reviewed', null, $c['entity_id'] ? (int)$c['entity_id'] : null, $grade);
        return ['box' => $box, 'due_at' => $due];
    }

    public function delete(int $id): void
    {
        $this->db->run('DELETE FROM flashcards WHERE id = ?', [$id]);
    }
}
