<?php
// ATLAS default configuration (shared, safe to commit to GitHub).
// Put YOUR settings (port, password, paths) in config.local.php: it overrides this file,
// is never committed, and is not overwritten when you pull updates.
// Copy config.local.example.php to config.local.php to start.
$cfg = [
    'app' => [
        'name'    => 'ATLAS · Mnemo',
        'version' => '1.0.0',
        'author'  => 'Robert Bruce Jr. C. Hammerton',
    ],
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'atlas',
        'user' => 'root',
        'pass' => '',          // XAMPP default is empty; set one for safety
    ],
    'ollama' => [
        'url'         => 'http://127.0.0.1:11434',
        'chat_model'  => 'qwen2.5:7b',        // 8 GB RAM: qwen2.5:3b  | 32 GB+: qwen2.5:14b
        'embed_model' => 'nomic-embed-text',  // or bge-m3
        'vision_model'=> 'qwen2.5vl:3b',      // 32 GB+: qwen2.5vl:7b ; '' to disable image captions
        'timeout'     => 600,
    ],
    'tools' => [
        // Optional. Leave '' to auto-detect from PATH, or set full paths on Windows:
        'pdftotext' => '',   // e.g. 'C:\\poppler\\Library\\bin\\pdftotext.exe'
        'tesseract' => '',   // e.g. 'C:\\Program Files\\Tesseract-OCR\\tesseract.exe'
        // Audio / video (optional, offline): ffmpeg + whisper.cpp
        'ffmpeg'        => '',   // e.g. 'C:\\ffmpeg\\bin\\ffmpeg.exe'
        'whisper'       => '',   // e.g. 'C:\\whisper\\whisper-cli.exe'
        'whisper_model' => '',   // e.g. 'C:\\whisper\\models\\ggml-base.bin'
    ],
    'chunk' => [
        'target_chars' => 1800,
        'overlap_chars' => 250,
    ],
    'retrieval' => [
        'top_k' => 8,
        'min_score' => 0.25,
    ],
    'storage' => __DIR__ . '/storage',
];

if (is_file(__DIR__ . '/config.local.php')) {
    $cfg = array_replace_recursive($cfg, (array)(require __DIR__ . '/config.local.php'));
}
return $cfg;
