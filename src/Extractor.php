<?php
declare(strict_types=1);

namespace Atlas;

/** Uses the local LLM to pull entities and relations out of a chunk. */
final class Extractor
{
    private const SYSTEM = <<<TXT
You are a precise knowledge-graph extraction engine. From the passage, extract only what is explicitly stated.
Return JSON exactly in this shape:
{"entities":[{"name":"...","type":"person|organization|place|concept|technology|event|work|product|metric|other","description":"one factual sentence from the passage"}],
 "relations":[{"source":"entity name","target":"entity name","type":"short_verb_phrase","evidence":"short exact quote from passage"}],
 "claims":[{"subject":"entity name","predicate":"short verb phrase","object":"value or entity","statement":"one self-contained factual sentence, with dates/numbers if stated"}]}
Rules:
- Use the most complete canonical name (e.g. "Albert Einstein" not "he").
- 3 to 15 entities, only important ones. Skip generic words ("system", "data", "paper").
- Relation types are lowercase snake_case verbs such as: is_a, part_of, created_by, causes, uses, located_in, depends_on, contradicts, supports, defined_as, related_to.
- Every relation's source and target must appear in entities.
- 0 to 8 claims: checkable facts (numbers, dates, definitions, causes, properties). Each statement must make sense on its own.
- Never invent facts not in the passage.
TXT;

    public function __construct(private Ollama $llm) {}

    public function extract(string $text, ?string $context = null): array
    {
        $user = ($context ? "Document: $context\n\n" : '') . "Passage:\n\"\"\"\n" . mb_substr($text, 0, 6000) . "\n\"\"\"";
        $j = $this->llm->chatJson([
            ['role' => 'system', 'content' => self::SYSTEM],
            ['role' => 'user', 'content' => $user],
        ]);
        $entities = [];
        foreach (($j['entities'] ?? []) as $e) {
            if (!is_array($e) || empty($e['name']) || !is_string($e['name'])) continue;
            $name = trim($e['name']);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 120) continue;
            $entities[] = ['name' => $name, 'type' => (string)($e['type'] ?? 'concept'), 'description' => isset($e['description']) ? mb_substr((string)$e['description'], 0, 600) : null];
        }
        $relations = [];
        foreach (($j['relations'] ?? []) as $r) {
            if (!is_array($r) || empty($r['source']) || empty($r['target']) || !is_string($r['source']) || !is_string($r['target'])) continue;
            $relations[] = ['source' => trim($r['source']), 'target' => trim($r['target']), 'type' => (string)($r['type'] ?? 'related_to'), 'evidence' => isset($r['evidence']) ? (string)$r['evidence'] : null];
        }
        $claims = [];
        foreach (($j['claims'] ?? []) as $c) {
            if (!is_array($c) || empty($c['statement']) || !is_string($c['statement'])) continue;
            $claims[] = ['subject' => trim((string)($c['subject'] ?? '')) ?: 'unknown', 'predicate' => trim((string)($c['predicate'] ?? 'states')) ?: 'states',
                         'object' => trim((string)($c['object'] ?? '')), 'statement' => mb_substr(trim($c['statement']), 0, 1000)];
        }
        return ['entities' => $entities, 'relations' => $relations, 'claims' => array_slice($claims, 0, 8)];
    }

    public function summarize(string $text, string $title): string
    {
        return trim($this->llm->chat([
            ['role' => 'system', 'content' => 'Summarize documents for a knowledge base in 3-5 factual sentences. No preamble.'],
            ['role' => 'user', 'content' => "Title: $title\n\n" . mb_substr($text, 0, 9000)],
        ], ['temperature' => 0.1]));
    }
}
