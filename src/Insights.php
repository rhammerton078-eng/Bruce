<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/** Phase 7 — Standing questions (watchlist) that are re-answered when knowledge changes. */
final class Insights
{
    private Db $db;

    public function __construct()
    {
        $this->db = Db::get();
    }

    public function add(string $q): int
    {
        $q = trim($q);
        if ($q === '') throw new RuntimeException('Question required');
        $this->db->run('INSERT INTO standing_questions (question) VALUES (?)', [mb_substr($q, 0, 2000)]);
        $id = $this->db->id();
        try { $this->check($id, true); } catch (\Throwable) {}
        return $id;
    }

    public function list(): array
    {
        return $this->db->all('SELECT id, question, answer, sources, unread, versions, checked_at, changed_at, created_at FROM standing_questions ORDER BY unread DESC, changed_at DESC, id DESC');
    }

    public function remove(int $id): void
    {
        $this->db->run('DELETE FROM standing_questions WHERE id = ?', [$id]);
    }

    public function markRead(int $id): void
    {
        $this->db->run('UPDATE standing_questions SET unread = 0 WHERE id = ?', [$id]);
    }

    public function unread(): int
    {
        return (int)$this->db->val('SELECT COUNT(*) FROM standing_questions WHERE unread = 1');
    }

    /** Re-answers if the set of relevant sources changed. Returns true when the answer was updated. */
    public function check(int $id, bool $force = false): bool
    {
        $sq = $this->db->one('SELECT * FROM standing_questions WHERE id = ?', [$id]);
        if (!$sq) throw new RuntimeException('Not found');
        $mnemo = new Mnemo();
        $chunks = $mnemo->retrieve($sq['question'], (int)atlas_config()['retrieval']['top_k']);
        $ids = array_map(fn($c) => (int)$c['id'], $chunks);
        sort($ids);
        $hash = sha1(implode(',', $ids));
        $this->db->run('UPDATE standing_questions SET checked_at = NOW() WHERE id = ?', [$id]);
        if (!$force && $hash === $sq['source_hash']) return false;
        $res = $mnemo->answer($sq['question']);
        $first = $sq['source_hash'] === null;
        $this->db->run('UPDATE standing_questions SET answer = ?, sources = ?, source_hash = ?, unread = ?, versions = versions + 1, changed_at = NOW() WHERE id = ?',
            [$res['text'], json_encode($res['sources'], JSON_UNESCAPED_UNICODE), $hash, $first ? 0 : 1, $id]);
        if (!$first) $this->db->log('standing_updated', null, null, mb_substr($sq['question'], 0, 200));
        return !$first;
    }

    public function checkAll(): int
    {
        $n = 0;
        foreach ($this->db->all('SELECT id FROM standing_questions') as $r) {
            try { if ($this->check((int)$r['id'])) $n++; } catch (\Throwable) {}
        }
        return $n;
    }
}
