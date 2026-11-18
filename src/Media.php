<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/**
 * Phase 7/8 — Media: offline audio/video transcription (ffmpeg + whisper.cpp),
 * perceptual image hashing, image-to-image links and search-by-image.
 */
final class Media
{
    public const AUDIO_EXT = ['mp3', 'wav', 'm4a', 'ogg', 'flac', 'aac', 'wma', 'mp4', 'mkv', 'webm', 'mov', 'avi'];

    // ------------------------------------------------------------------ transcription

    public static function tools(): array
    {
        $cfg = atlas_config()['tools'];
        $whisper = ($cfg['whisper'] ?? '') !== '' && is_file($cfg['whisper']) ? $cfg['whisper']
            : (DocParser::tool('whisper-cli') ?? DocParser::tool('whisper') ?? DocParser::tool('main'));
        $model = (string)($cfg['whisper_model'] ?? '');
        return ['ffmpeg' => DocParser::tool('ffmpeg'), 'whisper' => $whisper, 'whisper_model' => $model !== '' && is_file($model) ? $model : null];
    }

    /** Returns pages of transcript text, split into ~5-minute segments when timestamps are available. */
    public static function transcribe(string $path): array
    {
        $t = self::tools();
        if (!$t['ffmpeg']) throw new RuntimeException('Audio/video needs ffmpeg. Install it and add it to PATH (or set tools.ffmpeg in config.php).');
        if (!$t['whisper'] || !$t['whisper_model']) throw new RuntimeException('Audio/video needs whisper.cpp: set tools.whisper (whisper-cli.exe) and tools.whisper_model (e.g. ggml-base.bin) in config.php.');
        @set_time_limit(0);
        $tmp = sys_get_temp_dir() . '/atlas_' . bin2hex(random_bytes(5));
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        @shell_exec(escapeshellarg($t['ffmpeg']) . ' -y -i ' . escapeshellarg($path) . ' -vn -ar 16000 -ac 1 -c:a pcm_s16le ' . escapeshellarg("$tmp.wav") . " 2>$null");
        if (!is_file("$tmp.wav") || filesize("$tmp.wav") < 1000) throw new RuntimeException('ffmpeg could not extract audio from this file.');
        @shell_exec(escapeshellarg($t['whisper']) . ' -m ' . escapeshellarg($t['whisper_model']) . ' -f ' . escapeshellarg("$tmp.wav") . ' -l auto -osrt -otxt -of ' . escapeshellarg($tmp) . " 2>$null");
        @unlink("$tmp.wav");
        $pages = [];
        if (is_file("$tmp.srt")) {
            $srt = (string)file_get_contents("$tmp.srt");
            $seg = []; $page = 1;
            foreach (preg_split("/\r?\n\r?\n/", trim($srt)) ?: [] as $block) {
                $lines = preg_split("/\r?\n/", $block) ?: [];
                if (count($lines) < 3) continue;
                if (preg_match('/^(\d+):(\d+):(\d+)/', $lines[1], $m)) {
                    $sec = $m[1] * 3600 + $m[2] * 60 + $m[3];
                    $p = intdiv($sec, 300) + 1; // 5-minute "pages"
                    if ($p !== $page && $seg) { $pages[] = ['page' => $page, 'text' => self::label($page) . implode(' ', $seg)]; $seg = []; }
                    $page = $p;
                }
                $seg[] = trim(implode(' ', array_slice($lines, 2)));
            }
            if ($seg) $pages[] = ['page' => $page, 'text' => self::label($page) . implode(' ', $seg)];
            @unlink("$tmp.srt");
        }
        if (!$pages && is_file("$tmp.txt")) $pages[] = ['page' => 1, 'text' => DocParser::clean((string)file_get_contents("$tmp.txt"))];
        @unlink("$tmp.txt");
        if (!$pages) throw new RuntimeException('Transcription produced no text.');
        return $pages;
    }

    private static function label(int $page): string
    {
        $from = ($page - 1) * 5;
        return sprintf("[Transcript %02d:%02d–%02d:%02d]\n", intdiv($from, 60), $from % 60, intdiv($from + 5, 60), ($from + 5) % 60);
    }

    // ------------------------------------------------------------------ images

    /** 64-bit difference hash (dHash) as 16 hex chars, or null if GD is unavailable. */
    public static function dhash(string $path): ?string
    {
        if (!function_exists('imagecreatefromstring')) return null;
        $img = @imagecreatefromstring((string)file_get_contents($path));
        if (!$img) return null;
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 9, 8, imagesx($img), imagesy($img));
        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $l = imagecolorat($small, $x, $y); $r = imagecolorat($small, $x + 1, $y);
                $gl = (($l >> 16) & 255) * .3 + (($l >> 8) & 255) * .59 + ($l & 255) * .11;
                $gr = (($r >> 16) & 255) * .3 + (($r >> 8) & 255) * .59 + ($r & 255) * .11;
                $bits .= $gl > $gr ? '1' : '0';
            }
        }
        $hex = '';
        foreach (str_split($bits, 4) as $nib) $hex .= dechex(bindec($nib));
        return $hex;
    }

    public static function hamming(string $a, string $b): int
    {
        $d = 0;
        for ($i = 0; $i < 16; $i++) $d += substr_count(decbin(hexdec($a[$i]) ^ hexdec($b[$i])), '1');
        return $d;
    }

    /** Mean vector of an image document's description chunks. */
    private static function imageVector(int $docId): ?array
    {
        $rows = Db::get()->all('SELECT e.vec FROM chunk_embeddings e JOIN chunks c ON c.id = e.chunk_id WHERE c.document_id = ?', [$docId]);
        if (!$rows) return null;
        $sum = null;
        foreach ($rows as $r) {
            $v = Vector::unpack($r['vec']);
            if ($sum === null) $sum = $v; else foreach ($v as $i => $x) $sum[$i] = ($sum[$i] ?? 0) + $x;
        }
        return Vector::unpack(Vector::pack($sum));
    }

    /** Recomputes image-to-image links from meaning (description vectors) and visual similarity (dHash). */
    public static function linkImages(float $minSemantic = 0.8, int $maxHamming = 10): int
    {
        $db = Db::get();
        $imgs = $db->all("SELECT id, phash FROM documents WHERE kind = 'image' AND status IN ('ready','processing')");
        $vecs = [];
        foreach ($imgs as $i) $vecs[(int)$i['id']] = self::imageVector((int)$i['id']);
        $db->pdo->exec('DELETE FROM image_links');
        $n = 0;
        $c = count($imgs);
        for ($i = 0; $i < $c; $i++) for ($j = $i + 1; $j < $c; $j++) {
            $a = $imgs[$i]; $b = $imgs[$j];
            $best = null;
            if ($a['phash'] && $b['phash']) {
                $h = self::hamming($a['phash'], $b['phash']);
                if ($h <= $maxHamming) $best = [round(1 - $h / 64, 3), 'visual'];
            }
            $va = $vecs[(int)$a['id']]; $vb = $vecs[(int)$b['id']];
            if ($va && $vb) {
                $s = Vector::dot($va, $vb);
                if ($s >= $minSemantic && (!$best || $s > $best[0])) $best = [round($s, 3), 'meaning'];
            }
            if ($best) {
                $db->run('INSERT INTO image_links (image_a, image_b, score, method) VALUES (?,?,?,?)', [$a['id'], $b['id'], $best[0], $best[1]]);
                $n++;
            }
        }
        return $n;
    }

    public static function relatedImages(int $docId): array
    {
        return Db::get()->all('SELECT d.id, d.title, l.score, l.method FROM image_links l
            JOIN documents d ON d.id = IF(l.image_a = ?, l.image_b, l.image_a) WHERE l.image_a = ? OR l.image_b = ? ORDER BY l.score DESC LIMIT 12', [$docId, $docId, $docId]);
    }

    /** Search the knowledge base with an image (it is not stored). */
    public static function searchByImage(string $path): array
    {
        $llm = new Ollama();
        $desc = '';
        try { $desc = $llm->describeImage($path); } catch (\Throwable) {}
        $ocr = DocParser::ocr($path);
        $text = trim($desc . "\n" . $ocr);
        $out = ['description' => $desc, 'ocr' => $ocr, 'passages' => [], 'entities' => [], 'images' => []];
        if ($text !== '') {
            $mnemo = new Mnemo();
            foreach ($mnemo->retrieve($text, 8) as $c) {
                $out['passages'][] = ['chunk_id' => (int)$c['id'], 'document_id' => (int)$c['document_id'], 'title' => $c['title'], 'kind' => $c['kind'], 'page' => $c['page'], 'snippet' => mb_substr($c['content'], 0, 240)];
            }
            $out['entities'] = $mnemo->graphFacts($text)['entities'];
        }
        if ($h = self::dhash($path)) {
            foreach (Db::get()->all("SELECT id, title, phash FROM documents WHERE kind = 'image' AND phash IS NOT NULL") as $img) {
                $d = self::hamming($h, $img['phash']);
                if ($d <= 14) $out['images'][] = ['id' => (int)$img['id'], 'title' => $img['title'], 'score' => round(1 - $d / 64, 3)];
            }
            usort($out['images'], fn($a, $b) => $b['score'] <=> $a['score']);
        }
        return $out;
    }
}
