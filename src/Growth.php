<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * Baby mode. Mnemo starts as a Newborn with zero knowledge and grows without limit:
 * experience (XP) comes from everything it is taught, and levels never end.
 * Curiosity questions, lessons with exams, a growth diary and optional forgetting.
 */
final class Growth
{
    /** Named stages for the first levels; beyond these Mnemo keeps levelling forever. */
    public const STAGES = [0 => 'Newborn', 1 => 'Infant', 3 => 'Toddler', 6 => 'Child', 10 => 'Student', 15 => 'Graduate', 21 => 'Scholar', 28 => 'Expert', 36 => 'Master', 45 => 'Sage', 60 => 'Polymath'];

    private Db $db;

    public function __construct()
    {
        $this->db = Db::get();
    }

    public static function stageFor(int $level): string
    {
        $name = 'Newborn';
        foreach (self::STAGES as $min => $n) if ($level >= $min) $name = $n;
        if ($level >= 60) $name .= ' ' . self::roman(intdiv($level - 60, 15) + 1); // Polymath I, II, III … without end
        return $name;
    }

    private static function roman(int $n): string
    {
        $map = ['M' => 1000, 'CM' => 900, 'D' => 500, 'CD' => 400, 'C' => 100, 'XC' => 90, 'L' => 50, 'XL' => 40, 'X' => 10, 'IX' => 9, 'V' => 5, 'IV' => 4, 'I' => 1];
        $out = '';
        foreach ($map as $r => $v) while ($n >= $v) { $out .= $r; $n -= $v; }
        return $out;
    }

    /** XP needed to reach a level: 25·L² (level 1 = 25 XP, level 10 = 2 500, level 100 = 250 000 …). */
    public static function xpFor(int $level): int { return 25 * $level * $level; }

    public function status(): array
    {
        $v = fn(string $sql) => (int)$this->db->val($sql);
        $parts = [
            'Sources read' => [$v("SELECT COUNT(*) FROM documents WHERE status = 'ready' AND sha256 <> 'taught-knowledge'"), 10],
            'Passages' => [$v('SELECT COUNT(*) FROM chunks'), 1],
            'Concepts' => [$v('SELECT COUNT(*) FROM entities'), 2],
            'Relationships' => [$v("SELECT COUNT(*) FROM relations WHERE origin <> 'inferred'"), 1],
            'Facts verified' => [$v('SELECT COUNT(*) FROM claims WHERE support > 0'), 2],
            'Taught by you' => [$v('SELECT COUNT(*) FROM user_facts'), 5],
            'Questions answered for Mnemo' => [$v("SELECT COUNT(*) FROM curiosity_questions WHERE status = 'answered'"), 8],
            'Lessons' => [$v('SELECT COUNT(*) FROM lessons'), 15],
            'Decisions you made' => [$v("SELECT (SELECT COUNT(*) FROM contradictions WHERE status <> 'open') + (SELECT COUNT(*) FROM review_queue WHERE status <> 'pending')"), 4],
            'Approved answers' => [$v('SELECT COUNT(*) FROM qa_memory'), 4],
            'Sleep cycles' => [$v('SELECT COUNT(*) FROM daily_digest'), 5],
        ];
        $xp = 0;
        $breakdown = [];
        foreach ($parts as $label => [$n, $w]) { $xp += $n * $w; $breakdown[] = ['label' => $label, 'count' => $n, 'xp' => $n * $w]; }
        $level = (int)floor(sqrt($xp / 25));
        $cur = self::xpFor($level); $next = self::xpFor($level + 1);
        $bench = $this->db->one('SELECT avg_score FROM golden_runs WHERE total > 0 ORDER BY id DESC LIMIT 1');
        $nextStage = null;
        foreach (self::STAGES as $min => $n) if ($min > $level) { $nextStage = ['name' => $n, 'level' => $min]; break; }
        return [
            'level' => $level, 'stage' => self::stageFor($level), 'xp' => $xp, 'xp_level' => $cur, 'xp_next' => $next,
            'progress' => $next > $cur ? round(($xp - $cur) / ($next - $cur), 4) : 0,
            'next_stage' => $nextStage, 'breakdown' => $breakdown,
            'accuracy' => $bench ? (float)$bench['avg_score'] : null,
            'questions_pending' => $v("SELECT COUNT(*) FROM curiosity_questions WHERE status = 'pending'"),
            'strict' => Maintenance::setting('baby.strict', '1') === '1',
            'forgetting' => Maintenance::setting('baby.forgetting', '0') === '1',
        ];
    }

    /** Records a level-up (timeline + returns true) when the level rose since the last check. */
    public function checkLevelUp(): ?array
    {
        $s = $this->status();
        $last = (int)Maintenance::setting('baby.level', '0');
        if ($s['level'] > $last) {
            $this->db->run("REPLACE INTO settings (k, v) VALUES ('baby.level', ?)", [(string)$s['level']]);
            $this->db->log('level_up', null, null, "Level {$s['level']} · {$s['stage']}");
            return ['level' => $s['level'], 'stage' => $s['stage'], 'stage_changed' => self::stageFor($last) !== $s['stage']];
        }
        if ($s['level'] < $last) $this->db->run("REPLACE INTO settings (k, v) VALUES ('baby.level', ?)", [(string)$s['level']]);
        return null;
    }

    // ------------------------------------------------------------------ curiosity: Mnemo asks YOU

    /** Creates questions about things Mnemo has heard of but does not understand. */
    public function wonder(int $max = 8): int
    {
        // a curious child, not an interrogation: never keep more than 12 open questions
        $max = min($max, 12 - (int)$this->db->val("SELECT COUNT(*) FROM curiosity_questions WHERE status = 'pending'"));
        if ($max <= 0) return 0;
        $rows = $this->db->all("SELECT e.id, e.name, e.type, e.mention_count,
                (SELECT COUNT(*) FROM relations r WHERE r.source_id = e.id OR r.target_id = e.id) AS degree
            FROM entities e LEFT JOIN curiosity_questions q ON q.entity_id = e.id AND q.kind = 'what_is'
            WHERE q.id IS NULL AND (e.description IS NULL OR CHAR_LENGTH(e.description) < 40 OR e.description LIKE '%is mentioned in the passage%')
            ORDER BY (e.mention_count + (SELECT COUNT(*) FROM relations r2 WHERE r2.source_id = e.id OR r2.target_id = e.id)) DESC LIMIT " . max(1, $max));
        $n = 0;
        foreach ($rows as $e) {
            $nb = array_column($this->db->all('SELECT o.name FROM relations r JOIN entities o ON o.id = IF(r.source_id = ?, r.target_id, r.source_id)
                WHERE r.source_id = ? OR r.target_id = ? ORDER BY r.weight DESC LIMIT 3', [$e['id'], $e['id'], $e['id']]), 'name');
            $docs = array_column($this->db->all('SELECT DISTINCT d.title FROM entity_mentions m JOIN chunks c ON c.id = m.chunk_id JOIN documents d ON d.id = c.document_id WHERE m.entity_id = ? LIMIT 2', [$e['id']]), 'title');
            $q = match ($e['type']) { 'person' => "Who is {$e['name']}?", 'place' => "Where or what is {$e['name']}?", 'event' => "What happened at {$e['name']}?", default => "What is {$e['name']}?" };
            $ctx = "I've come across it {$e['mention_count']} time(s)" . ($docs ? ' in ' . implode(' and ', array_map(fn($d) => "“{$d}”", $docs)) : '')
                 . ($nb ? '. It seems connected to ' . implode(', ', $nb) : '') . ", but nobody has explained it to me yet.";
            $n += $this->db->run('INSERT IGNORE INTO curiosity_questions (entity_id, kind, question, context) VALUES (?,?,?,?)', [$e['id'], 'what_is', $q, $ctx])->rowCount();
        }
        return $n;
    }

    public function questions(string $status = 'pending', int $limit = 30): array
    {
        return $this->db->all('SELECT q.id, q.question, q.context, q.answer, q.status, q.created_at, q.answered_at, q.entity_id, e.name AS entity
            FROM curiosity_questions q LEFT JOIN entities e ON e.id = q.entity_id WHERE q.status = ? ORDER BY q.id DESC LIMIT ' . $limit, [$status]);
    }

    /** Your answer becomes a top-trust definition and the concept's description. */
    public function answer(int $id, string $text): string
    {
        $q = $this->db->one('SELECT q.*, e.name FROM curiosity_questions q LEFT JOIN entities e ON e.id = q.entity_id WHERE q.id = ?', [$id]);
        if (!$q) throw new RuntimeException('Question not found');
        $text = trim($text);
        if ($text === '') throw new RuntimeException('Write an answer first');
        $msg = (new Mnemo())->teach('define', ($q['name'] ? $q['name'] . ': ' : '') . $text);
        if ($q['entity_id']) {
            $old = $this->db->val('SELECT description FROM entities WHERE id = ?', [$q['entity_id']]);
            $this->db->run('UPDATE entities SET description = ?, confidence = GREATEST(confidence, 0.9) WHERE id = ?', [mb_substr($text, 0, 600), $q['entity_id']]);
            Truth::version('entity', (int)$q['entity_id'], 'description', $old !== null ? (string)$old : null, $text, 'You answered Mnemo\'s question');
        }
        $this->db->run("UPDATE curiosity_questions SET status = 'answered', answer = ?, answered_at = NOW() WHERE id = ?", [$text, $id]);
        return $msg;
    }

    public function skip(int $id): void
    {
        $this->db->run("UPDATE curiosity_questions SET status = 'skipped' WHERE id = ?", [$id]);
    }

    // ------------------------------------------------------------------ lessons & exams

    /** One fact per line. "Term = meaning" or "Term: meaning" lines are stored as definitions. */
    public function teachLesson(string $title, string $content): array
    {
        @set_time_limit(0);
        $title = trim($title);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $content) ?: []), fn($l) => mb_strlen($l) >= 4));
        if ($title === '' || !$lines) throw new RuntimeException('Give the lesson a title and at least one line');
        if (count($lines) > 40) throw new RuntimeException('Keep a lesson to 40 lines or fewer; split bigger topics into several lessons');
        $mnemo = new Mnemo();
        $taught = 0;
        foreach ($lines as $l) {
            $l = ltrim($l, "-*• \t");
            $isDef = (bool)preg_match('/^[^=:]{2,60}\s*(=|:)\s+\S/u', $l);
            $mnemo->teach($isDef ? 'define' : 'remember', $l);
            $taught++;
        }
        $this->db->run('INSERT INTO lessons (title, content, items) VALUES (?,?,?)', [mb_substr($title, 0, 250), implode("\n", $lines), $taught]);
        $id = $this->db->id();
        $this->db->log('lesson_taught', null, null, "$title ($taught items)");
        return ['id' => $id, 'items' => $taught];
    }

    /** Examines Mnemo on a lesson: a question per line, graded like a school test. */
    public function exam(int $lessonId, int $max = 10): array
    {
        @set_time_limit(0);
        $lesson = $this->db->one('SELECT * FROM lessons WHERE id = ?', [$lessonId]);
        if (!$lesson) throw new RuntimeException('Lesson not found');
        $lines = explode("\n", $lesson['content']);
        shuffle($lines);
        $llm = new Ollama(); $mnemo = new Mnemo(); $quality = new Quality();
        $results = [];
        foreach (array_slice($lines, 0, $max) as $line) {
            $qa = $llm->chatJson([
                ['role' => 'system', 'content' => 'Write ONE specific question that can be answered from the passage alone, plus its short correct answer. Do not mention "the passage". Return JSON {"question":"...","answer":"..."}'],
                ['role' => 'user', 'content' => "Lesson: {$lesson['title']}\n\n$line"],
            ]);
            if (empty($qa['question']) || empty($qa['answer'])) continue;
            $ans = $mnemo->answer((string)$qa['question'], null, null, false)['text'];
            $g = $quality->judge((string)$qa['question'], (string)$qa['answer'], $ans);
            $results[] = ['question' => (string)$qa['question'], 'expected' => (string)$qa['answer'], 'answer' => mb_substr($ans, 0, 600), 'score' => $g['score']];
        }
        if (!$results) throw new RuntimeException('Could not create exam questions (is the AI running?)');
        $score = array_sum(array_column($results, 'score')) / count($results);
        $this->db->run('UPDATE lessons SET last_score = ?, exams = exams + 1, last_exam = ?, last_exam_at = NOW() WHERE id = ?', [$score, json_encode($results, JSON_UNESCAPED_UNICODE), $lessonId]);
        $this->db->log('lesson_exam', null, null, $lesson['title'] . ': ' . round($score * 100) . '%');
        return ['score' => round($score, 3), 'grade' => self::grade($score), 'results' => $results];
    }

    public static function grade(float $s): string
    {
        return $s >= 0.9 ? 'A' : ($s >= 0.8 ? 'B' : ($s >= 0.7 ? 'C' : ($s >= 0.6 ? 'D' : 'F')));
    }

    public function lessons(): array
    {
        $rows = $this->db->all('SELECT id, title, items, last_score, exams, last_exam, last_exam_at, created_at FROM lessons ORDER BY id DESC');
        foreach ($rows as &$r) { $r['grade'] = $r['last_score'] !== null ? self::grade((float)$r['last_score']) : null; $r['last_exam'] = $r['last_exam'] ? json_decode($r['last_exam'], true) : null; }
        return $rows;
    }

    public function deleteLesson(int $id): void
    {
        $this->db->run('DELETE FROM lessons WHERE id = ?', [$id]); // the taught facts stay in memory
    }

    // ------------------------------------------------------------------ diary & forgetting

    /** Writes today's diary entry in Mnemo's own voice (no AI needed). */
    public function writeDiary(): array
    {
        $s = $this->status();
        $since = date('Y-m-d H:i:s', time() - 86400);
        $n = fn(string $sql) => (int)$this->db->val($sql, [$since]);
        $docs = $n("SELECT COUNT(*) FROM documents WHERE created_at >= ? AND sha256 <> 'taught-knowledge'");
        $ents = $n('SELECT COUNT(*) FROM entities WHERE first_seen >= ?');
        $rels = $n('SELECT COUNT(*) FROM relations WHERE created_at >= ?');
        $taught = $n('SELECT COUNT(*) FROM user_facts WHERE created_at >= ?');
        $cons = $n('SELECT COUNT(*) FROM contradictions WHERE created_at >= ?');
        $parts = [];
        if ($docs + $ents + $rels + $taught === 0) $parts[] = "A quiet day: I didn't learn anything new. Feed me something tomorrow!";
        else {
            $bits = [];
            if ($docs) $bits[] = "read $docs new source" . ($docs > 1 ? 's' : '');
            if ($ents) $bits[] = "learned $ents new concept" . ($ents > 1 ? 's' : '');
            if ($rels) $bits[] = "made $rels new connection" . ($rels > 1 ? 's' : '');
            if ($taught) $bits[] = "was taught $taught thing" . ($taught > 1 ? 's' : '') . ' by you';
            $parts[] = 'Today I ' . implode(', ', $bits) . '.';
        }
        if ($cons) $parts[] = "I noticed $cons disagreement" . ($cons > 1 ? 's' : '') . ' between my sources and need your judgement.';
        if ($s['questions_pending']) $parts[] = "I have {$s['questions_pending']} question" . ($s['questions_pending'] > 1 ? 's' : '') . ' for you.';
        if ($s['accuracy'] !== null) $parts[] = 'My benchmark score is ' . round($s['accuracy'] * 100) . '%.';
        $parts[] = "I am a level {$s['level']} {$s['stage']} with " . number_format($s['xp']) . ' XP.';
        $entry = implode(' ', $parts);
        $this->db->run('REPLACE INTO growth_diary (day, level, stage, xp, entry) VALUES (CURDATE(), ?, ?, ?, ?)', [$s['level'], $s['stage'], $s['xp'], $entry]);
        return ['entry' => $entry, 'level' => $s['level']];
    }

    public function diary(int $limit = 30): array
    {
        return $this->db->all('SELECT day, level, stage, xp, entry FROM growth_diary ORDER BY day DESC LIMIT ' . $limit);
    }

    /** Optional: weak, unconfirmed links slowly fade (like forgetting). Off by default. */
    public function forget(): int
    {
        if (Maintenance::setting('baby.forgetting', '0') !== '1') return 0;
        $this->db->run("UPDATE relations SET confidence = confidence - 0.05
            WHERE origin IN ('extracted','inferred') AND weight = 1 AND confidence < 0.4 AND created_at < NOW() - INTERVAL 30 DAY");
        $gone = $this->db->all('SELECT id, source_id, target_id, type FROM relations WHERE confidence < 0.1 AND origin <> \'user\'');
        foreach ($gone as $r) {
            Truth::version('relation', (int)$r['id'], 'forgotten', $r['source_id'] . ' ' . $r['type'] . ' ' . $r['target_id'], null, 'Faded: never confirmed');
            $this->db->run('DELETE FROM relations WHERE id = ?', [$r['id']]);
        }
        return count($gone);
    }
}
