<?php
declare(strict_types=1);

namespace Atlas;

use RuntimeException;

/** Extracts plain text from uploaded files. Returns a list of pages: [['page'=>int,'text'=>string], ...] */
final class DocParser
{
    public const DOC_EXT = ['pdf', 'docx', 'pptx', 'txt', 'md', 'markdown', 'csv', 'html', 'htm', 'json', 'xml', 'log'];
    public const IMG_EXT = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'bmp'];

    public static function kindFor(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, self::DOC_EXT, true)) return 'document';
        if (in_array($ext, self::IMG_EXT, true)) return 'image';
        if (in_array($ext, Media::AUDIO_EXT, true)) return 'audio';
        return null;
    }

    public static function parse(string $path, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'pdf' => self::pdf($path),
            'docx' => [['page' => 1, 'text' => self::officeXml($path, ['word/document.xml'], 'w:p')]],
            'pptx' => self::pptx($path),
            'html', 'htm' => [['page' => 1, 'text' => self::html((string)file_get_contents($path))]],
            default => [['page' => 1, 'text' => self::clean((string)file_get_contents($path))]],
        };
    }

    public static function tool(string $name): ?string
    {
        $cfg = atlas_config()['tools'][$name] ?? '';
        if ($cfg !== '' && is_file($cfg)) return $cfg;
        $which = PHP_OS_FAMILY === 'Windows' ? "where $name 2>NUL" : "command -v $name 2>/dev/null";
        $out = trim((string)@shell_exec($which));
        return $out !== '' ? strtok($out, "\r\n") : null;
    }

    private static function pdf(string $path): array
    {
        $pages = [];
        if ($bin = self::tool('pdftotext')) {
            $tmp = tempnam(sys_get_temp_dir(), 'atl');
            @shell_exec(escapeshellarg($bin) . ' -layout -enc UTF-8 ' . escapeshellarg($path) . ' ' . escapeshellarg($tmp));
            $txt = (string)@file_get_contents($tmp);
            @unlink($tmp);
            foreach (explode("\f", $txt) as $i => $p) {
                if (trim($p) !== '') $pages[] = ['page' => $i + 1, 'text' => self::clean($p)];
            }
        }
        if (!$pages && class_exists(\Smalot\PdfParser\Parser::class)) {
            $pdf = (new \Smalot\PdfParser\Parser())->parseFile($path);
            foreach ($pdf->getPages() as $i => $p) {
                $t = self::clean($p->getText());
                if ($t !== '') $pages[] = ['page' => $i + 1, 'text' => $t];
            }
        }
        // Scanned PDF fallback: OCR is only possible if pdftoppm + tesseract exist
        if (!$pages && ($ocr = self::tool('tesseract')) && ($ppm = self::tool('pdftoppm'))) {
            $dir = sys_get_temp_dir() . '/atl_' . bin2hex(random_bytes(4));
            @mkdir($dir);
            @shell_exec(escapeshellarg($ppm) . ' -r 200 -png ' . escapeshellarg($path) . ' ' . escapeshellarg("$dir/p"));
            $files = glob("$dir/p*.png") ?: [];
            sort($files, SORT_NATURAL);
            foreach ($files as $i => $img) {
                $t = self::ocr($img);
                if ($t !== '') $pages[] = ['page' => $i + 1, 'text' => $t];
                @unlink($img);
            }
            @rmdir($dir);
        }
        if (!$pages) throw new RuntimeException('No text found in PDF (scanned? install Tesseract + Poppler for OCR).');
        return $pages;
    }

    public static function ocr(string $imagePath): string
    {
        $bin = self::tool('tesseract');
        if (!$bin) return '';
        $out = @shell_exec(escapeshellarg($bin) . ' ' . escapeshellarg($imagePath) . ' stdout 2>' . (PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null'));
        return self::clean((string)$out);
    }

    private static function pptx(string $path): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Cannot open PPTX');
        $slides = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n = $zip->getNameIndex($i);
            if (preg_match('#^ppt/slides/slide(\d+)\.xml$#', $n, $m)) $slides[(int)$m[1]] = $n;
        }
        ksort($slides);
        $pages = [];
        foreach ($slides as $num => $name) {
            $xml = (string)$zip->getFromName($name);
            $xml = preg_replace('#</a:p>#', "\n", $xml);
            $t = self::clean(html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8'));
            if ($t !== '') $pages[] = ['page' => $num, 'text' => $t];
        }
        $zip->close();
        return $pages;
    }

    private static function officeXml(string $path, array $parts, string $paraTag): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Cannot open Office file');
        $out = '';
        foreach ($parts as $p) {
            $xml = (string)$zip->getFromName($p);
            $xml = str_replace(["</$paraTag>", '<w:tab/>', '<w:br/>'], ["\n\n", "\t", "\n"], $xml);
            $out .= html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        $zip->close();
        return self::clean($out);
    }

    private static function html(string $html): string
    {
        $html = preg_replace('#<(script|style|nav|footer|noscript)[^>]*>.*?</\1>#is', ' ', $html);
        $html = preg_replace('#</(p|div|h[1-6]|li|tr|br)>#i', "\n", (string)$html);
        return self::clean(html_entity_decode(strip_tags((string)$html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public static function clean(string $t): string
    {
        if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'Windows-1252');
        $t = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $t) ?? $t;
        $t = preg_replace("/[ \t]+/u", ' ', $t) ?? $t;
        $t = preg_replace("/\n{3,}/", "\n\n", $t) ?? $t;
        return trim($t);
    }
}
