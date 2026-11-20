<?php
declare(strict_types=1);

namespace Atlas;

/** Mnemo: retrieval-augmented, source-citing assistant over the knowledge base. */
final class Mnemo
{
    public const PERSONA = <<<TXT
You are Mnemo, the memory of the ATLAS knowledge system. You answer ONLY from the numbered sources and graph facts provided.
Rules:
- Cite every claim with source numbers in square brackets, like [1] or [2][4]. Never cite a number that was not provided.
- If the sources do not contain the answer, say "I don't know that yet." and suggest what kind of document the user could upload.
- If sources disagree, point out the disagreement and cite both sides.
- Be clear and well structured. Use short paragraphs or bullet lists. No filler.
- You have NO knowledge of your own. You were born knowing nothing; everything you know is in the sources below. Never use outside or general knowledge, even for famous facts.
TXT;

    public const DONT_KNOW = "I don't know that yet. Nobody has taught me about it. Upload a document about it, or teach me directly with `remember:` or `define:`.";

    /** Strict (baby) mode: with nothing relevant in memory, Mnemo must not answer from the model's own knowledge. */
    /** The AI's display name (set when an AI is downloaded as software). The model is always Mnemo. */
    public static function aiName(): string
    {
        $n = trim((string)(atlas_config()['app']['ai_name'] ?? ''));
        return $n !== '' ? $n : 'Mnemo';
    }

    private static function persona(): string
    {
        $n = self::aiName();
        return ($n !== 'Mnemo' ? "Your name is $n, powered by Mnemo (the AI inside ATLAS). If asked who you are, say you are $n, powered by Mnemo.\n" : '') . self::PERSONA;
    }

    /** Direct answer to "who are you?" questions (needs no sources, also in strict mode). */
    public static function identityAnswer(string $q): ?string
    {
        if (!preg_match('/\b(who are you|what(\'s| is) your name|what are you|introduce yourself|tell me about yourself|sino ka|ano ka|ano ang pangalan mo)\b/i', $q)) return null;
        $n = self::aiName();
        $who = $n === 'Mnemo' ? "I'm Mnemo, the AI inside ATLAS" : "I'm $n, powered by Mnemo, the AI inside ATLAS";
        return "$who. I started knowing nothing, and I learn only from what I'm taught: files, pictures and facts. I always show where my answers come from, and everything runs offline on this computer.";
    }

    public static function strict(): bool
    {
        return Maintenance::setting('baby.strict', '1') === '1';
    }

    private Db $db;
    private Ollama $llm;

    public function __construct()
    {
        $this->db = Db::get();
        $this->llm = new Ollama();
    }

    /** Hybrid retrieval: vector + full-text, fused with reciprocal rank fusion. */
    public function retrieve(string $q, int $k = 8, ?string $asOf = null, ?int $collection = null): array
    {
        $cfg = atlas_config()['retrieval'];
        $ranks = [];
        try {
            $vec = $this->llm->embed([$q])[0] ?? null;
            if ($vec) {
                $i = 0;
                foreach (Vector::searchChunks($vec, $k * 3, (float)$cfg['min_score']) as $id => $s) $ranks[$id]['v'] = ++$i;
            }
        } catch (\Throwable) { /* fall back to keyword search */ }

        $i = 0;
        foreach ($this->db->all('SELECT id FROM chunks WHERE MATCH(content) AGAINST (?) LIMIT ' . ($k * 3), [$q]) as $r) {
            $ranks[(int)$r['id']]['t'] = ++$i;
        }
        if (!$ranks) return [];
        $scores = [];
        foreach ($ranks as $id => $r) $scores[$id] = (isset($r['v']) ? 1 / (60 + $r['v']) : 0) + (isset($r['t']) ? 0.8 / (60 + $r['t']) : 0);
        arsort($scores);

        $ids = array_keys($scores);
        $in = implode(',', array_map('intval', $ids));
        $where = "c.id IN ($in)";
        $p = [];
        if ($asOf) { $where .= ' AND d.created_at <= ?'; $p[] = $asOf . ' 23:59:59'; }
        if ($collection) { $where .= ' AND d.collection_id = ?'; $p[] = $collection; }
        $rows = $this->db->all("SELECT c.id, c.document_id, c.page, c.heading, c.content, d.title, d.kind, d.trust
                                FROM chunks c JOIN documents d ON d.id = c.document_id WHERE $where", $p);
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r + ['score' => round($scores[(int)$r['id']] * 1000, 2)];
        $out = [];
        foreach ($ids as $id) if (isset($byId[$id])) $out[] = $byId[$id];
        return array_slice($out, 0, $k);
    }

    /** Entities named in the question plus their strongest relations. */
    public function graphFacts(string $q, int $limit = 12): array
    {
        $ents = $this->db->all('SELECT id, name FROM entities WHERE MATCH(name, description) AGAINST (?) ORDER BY mention_count DESC LIMIT 5', [$q]);
        if (!$ents) return ['entities' => [], 'facts' => []];
        $in = implode(',', array_map(fn($e) => (int)$e['id'], $ents));
        $facts = $this->db->all("SELECT s.name AS s, r.type, t.name AS t, r.confidence FROM relations r
                                 JOIN entities s ON s.id = r.source_id JOIN entities t ON t.id = r.target_id
                                 WHERE r.source_id IN ($in) OR r.target_id IN ($in)
                                 ORDER BY r.weight DESC, r.confidence DESC LIMIT $limit");
        return ['entities' => $ents, 'facts' => $facts];
    }

    /** Teaching commands: remember:, define:, correct: */
    public function parseCommand(string $msg): ?array
    {
        if (preg_match('/^\s*(remember|define|correct)\s*:\s*(.+)$/is', $msg, $m)) {
            return ['cmd' => strtolower($m[1]), 'text' => trim($m[2])];
        }
        return null;
    }

    public function teach(string $cmd, string $text): string
    {
        $kind = ['remember' => 'fact', 'define' => 'definition', 'correct' => 'correction'][$cmd];
        $docId = $this->db->val("SELECT id FROM documents WHERE sha256 = 'taught-knowledge'");
        if (!$docId) {
            $this->db->run("INSERT INTO documents (title, filename, stored_path, kind, sha256, status, trust, summary)
                            VALUES ('Taught by you', 'taught', '', 'document', 'taught-knowledge', 'ready', 95,
                            'Facts, definitions and corrections taught directly to Mnemo.')");
            $docId = $this->db->id();
        }
        $docId = (int)$docId;
        $label = ['fact' => 'Fact', 'definition' => 'Definition', 'correction' => 'Correction (overrides earlier sources)'][$kind];
        $content = "$label: $text";
        $seq = (int)$this->db->val('SELECT COALESCE(MAX(seq), -1) + 1 FROM chunks WHERE document_id = ?', [$docId]);
        $this->db->run('INSERT INTO chunks (document_id, seq, page, content, token_estimate, extracted) VALUES (?,?,1,?,?,1)',
            [$docId, $seq, $content, (int)ceil(mb_strlen($content) / 4)]);
        $chunkId = $this->db->id();
        $this->db->run('INSERT INTO user_facts (kind, content, chunk_id) VALUES (?,?,?)', [$kind, $text, $chunkId]);
        $learned = 0;
        try {
            $vec = $this->llm->embed([$content])[0] ?? null;
            if ($vec) Vector::store($chunkId, $this->llm->model('embed'), $vec);
            $out = (new Extractor($this->llm))->extract($text, 'Taught by the user (' . $kind . ')');
            $map = [];
            foreach ($out['entities'] as $e) {
                [$id] = Graph::upsertEntity($e['name'], $e['type'], $e['description'], $docId);
                if ($id) { $map[Graph::norm($e['name'])] = $id; Graph::mention($id, $chunkId); $learned++; }
            }
            foreach ($out['relations'] as $r) {
                $s = $map[Graph::norm($r['source'])] ?? 0; $t = $map[Graph::norm($r['target'])] ?? 0;
                if ($s && $t) Graph::addRelation($s, $t, $r['type'], 'user', $r['evidence'], $chunkId, 0.95);
            }
            // What you teach becomes a top-trust claim, checked right away against existing knowledge
            $claims = $out['claims'] ?: [['subject' => $out['entities'][0]['name'] ?? 'unknown', 'predicate' => $kind, 'object' => '', 'statement' => $text]];
            $truth = new Truth($this->llm);
            $truth->saveClaims($claims, $map, $chunkId, $docId, 'user', 0.95);
            $overridden = 0;
            foreach ($this->db->all('SELECT * FROM claims WHERE chunk_id = ? AND checked = 0', [$chunkId]) as $c) {
                foreach ($truth->checkClaim($c) as $res) if ($res['relation'] === 'contradicts') $overridden++;
                $this->db->run('UPDATE claims SET checked = 1 WHERE id = ?', [$c['id']]);
            }
        } catch (\Throwable $e) {
            return "Saved, but I couldn't analyse it yet (" . $e->getMessage() . '). It is stored and searchable by keyword.';
        }
        $this->db->log('user_taught', $docId, null, mb_substr($content, 0, 200));
        $extra = $overridden ? " It overrides $overridden conflicting fact(s) from your sources (see Review → Contradictions → Resolved)." : '';
        return match ($kind) {
            'fact' => "Got it — I'll remember that. Linked $learned concept(s) in my graph.$extra",
            'definition' => "Definition saved. Linked $learned concept(s).$extra",
            'correction' => "Correction saved with top trust. It will outrank older sources when they disagree.$extra",
        };
    }

    /** A previously approved (👍) answer to a very similar question, if any. */
    public function approvedAnswer(string $q, float $min = 0.9): ?array
    {
        $rows = $this->db->all('SELECT id, question, answer, vec FROM qa_memory WHERE vec IS NOT NULL');
        if (!$rows) return null;
        try { $v = $this->llm->embed([$q])[0] ?? null; } catch (\Throwable) { return null; }
        if (!$v) return null;
        $v = Vector::unpack(Vector::pack($v));
        $best = null; $bestS = $min;
        foreach ($rows as $r) {
            $s = Vector::dot($v, Vector::unpack($r['vec']));
            if ($s >= $bestS) { $bestS = $s; $best = $r; }
        }
        if (!$best) return null;
        $this->db->run('UPDATE qa_memory SET uses = uses + 1 WHERE id = ?', [$best['id']]);
        return ['id' => (int)$best['id'], 'question' => $best['question'], 'answer' => $best['answer'], 'similarity' => round($bestS, 3)];
    }

    /** Complete non-streaming answer (used by quality tests, self-quiz and the watchlist). */
    public function answer(string $q, ?string $asOf = null, ?int $collection = null, bool $useApproved = true): array
    {
        $chunks = $this->retrieve($q, (int)atlas_config()['retrieval']['top_k'], $asOf, $collection);
        $graph = $this->graphFacts($q);
        $approved = $useApproved ? $this->approvedAnswer($q) : null;
        if (!$chunks && !$approved && self::strict()) return ['text' => self::DONT_KNOW, 'sources' => [], 'approved' => null];
        $text = $this->llm->chat($this->buildMessages($q, $chunks, $graph, [], $approved), ['temperature' => 0.2]);
        $sources = [];
        foreach ($chunks as $i => $c) $sources[] = ['n' => $i + 1, 'chunk_id' => (int)$c['id'], 'document_id' => (int)$c['document_id'], 'title' => $c['title'], 'page' => $c['page'] ? (int)$c['page'] : null];
        return ['text' => $text, 'sources' => $sources, 'approved' => $approved];
    }

    /** Builds the prompt messages for an answer. */
    public function buildMessages(string $q, array $chunks, array $graph, array $history = [], ?array $approved = null): array
    {
        $ctx = '';
        foreach ($chunks as $i => $c) {
            $n = $i + 1;
            $loc = $c['title'] . ($c['page'] ? ', p.' . $c['page'] : '') . ($c['trust'] >= 90 ? ', taught by user (highest trust)' : '');
            $ctx .= "[$n] ($loc)\n" . mb_substr($c['content'], 0, 2200) . "\n\n";
        }
        if ($graph['facts']) {
            $ctx .= "Graph facts (supporting context, cite the numbered source that states them when possible):\n";
            foreach ($graph['facts'] as $f) $ctx .= "- {$f['s']} —{$f['type']}→ {$f['t']}\n";
        }
        $notes = (new Truth($this->llm))->notesForChunks(array_map(fn($c) => (int)$c['id'], $chunks));
        if ($notes) {
            $ctx .= "\nFact-check notes (do NOT present these as true):\n";
            foreach ($notes as $nt) $ctx .= '- ' . ($nt['status'] === 'disputed' ? 'DISPUTED (sources disagree): ' : 'REJECTED/OUTDATED: ') . $nt['statement'] . "\n";
        }
        if ($approved) {
            $ctx .= "\nA previous answer the user APPROVED for a similar question (\"{$approved['question']}\"). Reuse it where it fits, but still cite sources:\n" . mb_substr($approved['answer'], 0, 2500) . "\n";
        }
        if ($ctx === '') $ctx = '(no sources found)';
        $msgs = [['role' => 'system', 'content' => self::persona()]];
        foreach (array_slice($history, -6) as $h) $msgs[] = ['role' => $h['role'], 'content' => mb_substr($h['content'], 0, 1500)];
        $msgs[] = ['role' => 'user', 'content' => "Sources:\n$ctx\nQuestion: $q"];
        return $msgs;
    }
}
