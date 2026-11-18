<?php
declare(strict_types=1);

namespace Atlas;

/** Splits page text into overlapping, paragraph-aware chunks. */
final class Chunker
{
    public static function chunk(array $pages, int $target = 1800, int $overlap = 250): array
    {
        $chunks = [];
        $buf = '';
        $bufPage = null;
        $heading = null;

        $flush = function () use (&$chunks, &$buf, &$bufPage, &$heading, $overlap) {
            $t = trim($buf);
            if (mb_strlen($t) >= 40) {
                $chunks[] = ['page' => $bufPage, 'heading' => $heading, 'content' => $t];
            }
            // keep a tail for overlap, starting at a sentence boundary when possible
            $tail = mb_substr($t, -$overlap);
            if (($p = mb_strpos($tail, '. ')) !== false) $tail = mb_substr($tail, $p + 2);
            $buf = mb_strlen($t) > $overlap ? $tail . "\n" : '';
            $bufPage = null;
        };

        foreach ($pages as $pg) {
            $paras = preg_split("/\n\s*\n/u", (string)$pg['text']) ?: [];
            foreach ($paras as $para) {
                $para = trim($para);
                if ($para === '') continue;
                if (self::looksLikeHeading($para)) $heading = mb_substr($para, 0, 250);

                // very long paragraph: split on sentences
                $pieces = mb_strlen($para) > $target ? self::sentences($para, $target) : [$para];
                foreach ($pieces as $piece) {
                    if (mb_strlen($buf) + mb_strlen($piece) > $target && mb_strlen(trim($buf)) > 0) $flush();
                    $bufPage ??= (int)$pg['page'];
                    $buf .= $piece . "\n\n";
                }
            }
        }
        if (trim($buf) !== '') { $overlap = 0; $flush(); }
        return $chunks;
    }

    private static function looksLikeHeading(string $p): bool
    {
        return mb_strlen($p) < 90 && !str_ends_with($p, '.') && substr_count($p, "\n") === 0
            && preg_match('/^([0-9IVX]+[.)]\s+|#+\s+)?[\p{Lu}0-9]/u', $p) === 1;
    }

    private static function sentences(string $text, int $max): array
    {
        $sent = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];
        $out = [];
        $cur = '';
        foreach ($sent as $s) {
            if (mb_strlen($cur) + mb_strlen($s) > $max && $cur !== '') { $out[] = trim($cur); $cur = ''; }
            if (mb_strlen($s) > $max) { foreach (mb_str_split($s, $max) as $part) $out[] = $part; continue; }
            $cur .= $s . ' ';
        }
        if (trim($cur) !== '') $out[] = trim($cur);
        return $out;
    }
}
