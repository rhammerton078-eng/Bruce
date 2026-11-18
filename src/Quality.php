<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * Phase 6 — Self-improvement.
 * Golden test set (measurable accuracy over time), self-quiz on new material,
 * approved-answer memory and fine-tuning dataset export.
 */
final class Quality
{
    private Db $db;
    private Ollama $llm;

    public function __construct()
    {
        $this->db = Db::get();
        $this->llm = new Ollama();
    }

    /** LLM-as-judge: 0..1 score of an answer against the expected answer. Keyword fallback if the LLM fails. */
    public function judge(string $question, string $expected, string $answer): array
    {
        try {
            $j = $this->llm->chatJson([
                ['role' => 'system', 'content' => 'You grade answers. Compare the ANSWER to the EXPECTED answer for the QUESTION. Score 1.0 if it contains all key facts of the expected answer, 0.5 if partially, 0.0 if wrong, missing, or "I don\'t know". Extra correct detail is fine. Return JSON {"score": number between 0 and 1, "reason": "short"}'],
                ['role' => 'user', 'content' => "QUESTION: $question\nEXPECTED: $expected\nANSWER: " . mb_substr($answer, 0, 4000)],
            ]);
            if (isset($j['score']) && is_numeric($j['score'])) return ['score' => max(0, min(1, (float)$j['score'])), 'reason' => (string)($j['reason'] ?? '')];
        } catch (\Throwable) {}
        $words = array_unique(array_filter(preg_split('/\W+/u', mb_strtolower($expected)) ?: [], fn($w) => mb_strlen($w) > 3));
        if (!$words) return ['score' => 0.0, 'reason' => 'No keywords'];
        $a = mb_strtolower($answer);
        $hit = count(array_filter($words, fn($w) => str_contains($a, $w)));
        return ['score' => round($hit / count($words), 3), 'reason' => "Keyword overlap $hit/" . count($words)];
    }

    // ------------------------------------------------------------------ golden tests

    public function addTest(string $q, string $expected): int
    {
        if (trim($q) === '' || trim($expected) === '') throw new RuntimeException('Question and expected answer are required');
        $this->db->run('INSERT INTO golden_tests (question, expected) VALUES (?,?)', [trim($q), trim($expected)]);
        return $this->db->id();
    }

    public function tests(): array
    {
        return $this->db->all('SELECT t.id, t.question, t.expected, t.active, t.created_at,
              (SELECT r.score FROM golden_results r WHERE r.test_id = t.id ORDER BY r.id DESC LIMIT 1) AS last_score,
              (SELECT r.answer FROM golden_results r WHERE r.test_id = t.id ORDER BY r.id DESC LIMIT 1) AS last_answer
            FROM golden_tests t ORDER BY t.id DESC');
    }

    public function runTests(): array
    {
        @set_time_limit(0);
        $tests = $this->db->all('SELECT * FROM golden_tests WHERE active = 1');
        if (!$tests) return ['total' => 0];
        $size = (int)$this->db->val('SELECT COUNT(*) FROM chunks');
        $this->db->run('INSERT INTO golden_runs (knowledge_size) VALUES (?)', [$size]);
        $runId = $this->db->id();
        $mnemo = new Mnemo();
        $sum = 0; $passed = 0;
        foreach ($tests as $t) {
            try {
                $ans = $mnemo->answer($t['question'], null, null, false)['text']; // no approved-answer shortcut: test real knowledge
                $g = $this->judge($t['question'], $t['expected'], $ans);
            } catch (\Throwable $e) {
                $ans = '⚠️ ' . $e->getMessage(); $g = ['score' => 0, 'reason' => 'Error'];
            }
            $this->db->run('INSERT INTO golden_results (run_id, test_id, answer, score, reason) VALUES (?,?,?,?,?)', [$runId, $t['id'], $ans, $g['score'], $g['reason']]);
            $sum += $g['score'];
            if ($g['score'] >= 0.7) $passed++;
        }
        $avg = $sum / count($tests);
        $this->db->run('UPDATE golden_runs SET total = ?, passed = ?, avg_score = ? WHERE id = ?', [count($tests), $passed, $avg, $runId]);
        $this->db->log('golden_run', null, null, sprintf('%d/%d passed · %.0f%%', $passed, count($tests), $avg * 100));
        return ['run_id' => $runId, 'total' => count($tests), 'passed' => $passed, 'avg_score' => round($avg, 4)];
    }

    public function runs(int $limit = 60): array
    {
        return array_reverse($this->db->all('SELECT id, total, passed, avg_score, knowledge_size, created_at FROM golden_runs WHERE total > 0 ORDER BY id DESC LIMIT ' . $limit));
    }

    // ------------------------------------------------------------------ self-quiz

    /** Mnemo writes questions from passages it learned, answers them, and grades itself. */
    public function selfQuiz(int $n = 5): array
    {
        @set_time_limit(0);
        $chunks = $this->db->all('SELECT c.id, c.content, d.title FROM chunks c JOIN documents d ON d.id = c.document_id
            LEFT JOIN self_quiz q ON q.chunk_id = c.id WHERE q.id IS NULL AND CHAR_LENGTH(c.content) > 200 AND d.status = \'ready\'
            ORDER BY c.id DESC LIMIT ' . max(1, $n));
        $mnemo = new Mnemo();
        $out = [];
        foreach ($chunks as $c) {
            try {
                $qa = $this->llm->chatJson([
                    ['role' => 'system', 'content' => 'Write ONE specific question that can be answered from the passage alone, plus its short correct answer. Do not mention "the passage". Return JSON {"question":"...","answer":"..."}'],
                    ['role' => 'user', 'content' => "Document: {$c['title']}\n\n" . mb_substr($c['content'], 0, 3500)],
                ]);
                if (empty($qa['question']) || empty($qa['answer'])) continue;
                $res = $mnemo->answer((string)$qa['question'], null, null, false);
                $g = $this->judge((string)$qa['question'], (string)$qa['answer'], $res['text']);
                $retrieved = in_array((int)$c['id'], array_column($res['sources'], 'chunk_id'), true) ? 1 : 0;
                $this->db->run('INSERT INTO self_quiz (chunk_id, question, expected, answer, score, retrieved) VALUES (?,?,?,?,?,?)',
                    [$c['id'], $qa['question'], $qa['answer'], $res['text'], $g['score'], $retrieved]);
                $out[] = ['question' => $qa['question'], 'score' => $g['score'], 'retrieved' => (bool)$retrieved];
            } catch (\Throwable $e) {
                $out[] = ['error' => $e->getMessage()];
                if (str_contains($e->getMessage(), 'unreachable')) break;
            }
        }
        if ($out) $this->db->log('self_quiz', null, null, count($out) . ' questions, avg ' . round(array_sum(array_column($out, 'score')) / max(1, count($out)) * 100) . '%');
        return $out;
    }

    public function quizResults(int $limit = 50): array
    {
        return $this->db->all('SELECT q.id, q.question, q.expected, q.answer, q.score, q.retrieved, q.created_at, q.chunk_id, d.title
            FROM self_quiz q LEFT JOIN chunks c ON c.id = q.chunk_id LEFT JOIN documents d ON d.id = c.document_id ORDER BY q.id DESC LIMIT ' . $limit);
    }

    public function quizStats(): array
    {
        $r = $this->db->one('SELECT COUNT(*) n, AVG(score) avg_score, AVG(retrieved) retrieval FROM self_quiz');
        return ['n' => (int)$r['n'], 'avg_score' => round((float)$r['avg_score'], 3), 'retrieval' => round((float)$r['retrieval'], 3)];
    }

    public function promoteQuiz(int $id): int
    {
        $q = $this->db->one('SELECT question, expected FROM self_quiz WHERE id = ?', [$id]);
        if (!$q) throw new RuntimeException('Not found');
        return $this->addTest($q['question'], $q['expected']);
    }

    // ------------------------------------------------------------------ approved answers (qa_memory)

    /** Called when the user rates an answer. 👍 stores it as an approved answer; 👎 removes it. */
    public function feedback(int $messageId, int $value): void
    {
        if ($value < 1) { $this->db->run('DELETE FROM qa_memory WHERE message_id = ?', [$messageId]); return; }
        $msg = $this->db->one("SELECT id, conversation_id, content, sources FROM messages WHERE id = ? AND role = 'assistant'", [$messageId]);
        if (!$msg) return;
        $q = $this->db->val("SELECT content FROM messages WHERE conversation_id = ? AND role = 'user' AND id < ? ORDER BY id DESC LIMIT 1", [$msg['conversation_id'], $messageId]);
        if (!$q || preg_match('/^\s*(remember|define|correct)\s*:/i', (string)$q)) return;
        $vec = null;
        try { $v = $this->llm->embed([$q])[0] ?? null; if ($v) $vec = Vector::pack($v); } catch (\Throwable) {}
        $this->db->run('INSERT INTO qa_memory (message_id, question, answer, sources, vec) VALUES (?,?,?,?,?)
                        ON DUPLICATE KEY UPDATE answer = VALUES(answer), vec = VALUES(vec)', [$messageId, $q, $msg['content'], $msg['sources'], $vec]);
        $this->db->log('answer_approved', null, null, mb_substr((string)$q, 0, 200));
    }

    public function approved(int $limit = 100): array
    {
        return $this->db->all('SELECT id, question, SUBSTRING(answer, 1, 400) AS answer, uses, created_at FROM qa_memory ORDER BY id DESC LIMIT ' . $limit);
    }

    // ------------------------------------------------------------------ fine-tuning export

    /** JSONL chat dataset from verified knowledge (approved answers, golden tests, taught facts, flashcards). */
    public function trainingData(): string
    {
        $sys = 'You are Mnemo, a helpful assistant that answers from the user\'s personal knowledge base.';
        $lines = [];
        $add = function (string $u, string $a) use (&$lines, $sys) {
            $lines[] = json_encode(['messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $u], ['role' => 'assistant', 'content' => $a]]], JSON_UNESCAPED_UNICODE);
        };
        foreach ($this->db->all('SELECT question, answer FROM qa_memory') as $r) $add($r['question'], preg_replace('/\s*\[\d+\]/', '', $r['answer']) ?? $r['answer']);
        foreach ($this->db->all('SELECT question, expected FROM golden_tests WHERE active = 1') as $r) $add($r['question'], $r['expected']);
        foreach ($this->db->all("SELECT kind, content FROM user_facts WHERE active = 1") as $r) {
            $add($r['kind'] === 'definition' ? 'Give me a definition you learned from me.' : 'Tell me something I taught you.', $r['content']);
        }
        foreach ($this->db->all('SELECT question, answer FROM flashcards WHERE reviews > 0') as $r) $add($r['question'], $r['answer']);
        return implode("\n", $lines) . "\n";
    }
}
