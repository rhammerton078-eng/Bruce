<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/** Thin client for the local Ollama HTTP API. */
final class Ollama
{
    private array $c;

    public function __construct()
    {
        $this->c = atlas_config()['ollama'];
    }

    public function model(string $role): string
    {
        return (string)($this->c[$role . '_model'] ?? '');
    }

    private function request(string $path, ?array $body = null, ?callable $onLine = null, int $timeout = 0): array
    {
        $ch = curl_init(rtrim($this->c['url'], '/') . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => $onLine === null,
            CURLOPT_TIMEOUT => $timeout ?: (int)$this->c['timeout'],
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ];
        if ($body !== null) {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE);
        }
        $buffer = '';
        if ($onLine !== null) {
            $opts[CURLOPT_WRITEFUNCTION] = function ($ch, string $data) use (&$buffer, $onLine) {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if ($line !== '') {
                        $j = json_decode($line, true);
                        if (is_array($j)) $onLine($j);
                    }
                }
                return strlen($data);
            };
        }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($res === false) throw new RuntimeException("Ollama unreachable: $err");
        if ($onLine !== null) return [];
        $j = json_decode((string)$res, true);
        if ($code >= 400 || !is_array($j)) {
            throw new RuntimeException('Ollama error ' . $code . ': ' . (is_array($j) ? ($j['error'] ?? '') : substr((string)$res, 0, 200)));
        }
        return $j;
    }

    public function status(): array
    {
        try {
            $tags = $this->request('/api/tags', null, null, 5);
            $names = array_map(fn($m) => $m['name'], $tags['models'] ?? []);
            $has = function (string $m) use ($names): bool {
                if ($m === '') return true;
                foreach ($names as $n) {
                    if ($n === $m || $n === "$m:latest" || str_starts_with($n, $m . ':')) return true;
                }
                return false;
            };
            return [
                'online' => true,
                'models' => $names,
                'chat' => ['name' => $this->model('chat'), 'installed' => $has($this->model('chat'))],
                'embed' => ['name' => $this->model('embed'), 'installed' => $has($this->model('embed'))],
                'vision' => ['name' => $this->model('vision'), 'installed' => $has($this->model('vision'))],
            ];
        } catch (\Throwable $e) {
            return ['online' => false, 'error' => $e->getMessage()];
        }
    }

    /** @return float[][] */
    public function embed(array $texts): array
    {
        $j = $this->request('/api/embed', ['model' => $this->model('embed'), 'input' => array_values($texts), 'truncate' => true]);
        return $j['embeddings'] ?? [];
    }

    public function chat(array $messages, array $opts = []): string
    {
        $body = [
            'model' => $opts['model'] ?? $this->model('chat'),
            'messages' => $messages,
            'stream' => false,
            'options' => ['temperature' => $opts['temperature'] ?? 0.2, 'num_ctx' => $opts['num_ctx'] ?? 8192],
        ];
        if (isset($opts['format'])) $body['format'] = $opts['format'];
        $j = $this->request('/api/chat', $body);
        return (string)($j['message']['content'] ?? '');
    }

    public function chatJson(array $messages, array $opts = []): array
    {
        $out = $this->chat($messages, $opts + ['format' => 'json', 'temperature' => 0.0]);
        $j = json_decode($out, true);
        if (!is_array($j) && preg_match('/\{.*\}/s', $out, $m)) $j = json_decode($m[0], true);
        return is_array($j) ? $j : [];
    }

    /** Streams tokens to $onToken; returns the full text. */
    public function chatStream(array $messages, callable $onToken, array $opts = []): string
    {
        $full = '';
        $this->request('/api/chat', [
            'model' => $this->model('chat'),
            'messages' => $messages,
            'stream' => true,
            'options' => ['temperature' => $opts['temperature'] ?? 0.3, 'num_ctx' => $opts['num_ctx'] ?? 8192],
        ], function (array $j) use (&$full, $onToken) {
            if (isset($j['error'])) throw new RuntimeException((string)$j['error']);
            $t = $j['message']['content'] ?? '';
            if ($t !== '') { $full .= $t; $onToken($t); }
        });
        return $full;
    }

    public function describeImage(string $path): string
    {
        if ($this->model('vision') === '') return '';
        return $this->chat([[
            'role' => 'user',
            'content' => 'Describe this image for a knowledge base. Include: what it shows, any visible text or labels, '
                . 'named things (people, places, products, concepts), and what ideas or data it conveys. Be factual and specific.',
            'images' => [base64_encode((string)file_get_contents($path))],
        ]], ['model' => $this->model('vision'), 'temperature' => 0.1]);
    }
}
