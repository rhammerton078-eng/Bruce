<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';

use Atlas\{Db, DocParser, Ollama};

$cfg = require __DIR__ . '/config.php';
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', ['127.0.0.1', '::1'], true)) { http_response_code(403); exit('Setup is only available from localhost.'); }
$msg = null;
$err = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'install') {
    try {
        Atlas\Installer::run();   // also repairs leftover database files
        $msg = 'Database installed. You are ready to go!';
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['do'] ?? '') === 'dbuser') {
    try {
        $u = preg_replace('/[^a-z0-9_]/i', '', (string)($_POST['user'] ?? 'atlas')) ?: 'atlas';
        $pw = (string)($_POST['pass'] ?? '');
        if (strlen($pw) < 8) throw new RuntimeException('Use a password of at least 8 characters.');
        $pdo = Db::server()->pdo;
        $q = $pdo->quote($pw);
        $pdo->exec("CREATE USER IF NOT EXISTS '$u'@'localhost' IDENTIFIED BY $q");
        $pdo->exec("CREATE USER IF NOT EXISTS '$u'@'127.0.0.1' IDENTIFIED BY $q");
        $pdo->exec("ALTER USER '$u'@'localhost' IDENTIFIED BY $q");
        $pdo->exec("ALTER USER '$u'@'127.0.0.1' IDENTIFIED BY $q");
        foreach (['localhost', '127.0.0.1'] as $h) {
            $pdo->exec("GRANT ALL PRIVILEGES ON `{$cfg['db']['name']}`.* TO '$u'@'$h'");
            // memory cards are separate databases named <db>_c_<id>
            $pdo->exec("GRANT ALL PRIVILEGES ON `" . str_replace('_', '\\_', $cfg['db']['name']) . "\\_c\\_%`.* TO '$u'@'$h'");
        }
        $pdo->exec('FLUSH PRIVILEGES');
        $file = __DIR__ . '/config.local.php';   // local settings: never committed to GitHub
        $local = is_file($file) ? (array)(require $file) : [];
        $local['db'] = array_merge($local['db'] ?? [], ['user' => $u, 'pass' => $pw]);
        if (file_put_contents($file, "<?php\n// Local ATLAS settings (not committed to GitHub).\nreturn " . var_export($local, true) . ";\n") === false) {
            throw new RuntimeException("User created, but config.local.php could not be written. Create it yourself with 'user' => '$u' and the password.");
        }
        $msg = "Dedicated MySQL user '$u' created and saved in config.local.php. ATLAS no longer uses root.";
        $cfg = require __DIR__ . '/config.php';
    } catch (Throwable $e) { $err = $e->getMessage(); }
}

$checks = [];
$add = function (string $name, bool $ok, string $detail, bool $required = true) use (&$checks) { $checks[] = compact('name', 'ok', 'detail', 'required'); };

$add('PHP 8.0+', version_compare(PHP_VERSION, '8.0.0', '>='), 'Running PHP ' . PHP_VERSION);
foreach (['pdo_mysql', 'curl', 'mbstring', 'zip', 'fileinfo'] as $ext) $add("PHP extension: $ext", extension_loaded($ext), extension_loaded($ext) ? 'Loaded' : "Enable extension=$ext in php.ini");
$add('PDF parser library', class_exists(\Smalot\PdfParser\Parser::class), class_exists(\Smalot\PdfParser\Parser::class) ? 'Bundled in vendor/' : 'Run: composer install');
$up = ini_get('upload_max_filesize'); $post = ini_get('post_max_size');
$add('Upload size limits', (int)$up >= 32, "upload_max_filesize=$up, post_max_size=$post (recommended: 128M both, in php.ini)", false);
$add('Storage folder writable', is_writable($cfg['storage']), $cfg['storage']);

$dbOk = false; $tablesOk = false;
try { Db::server(); $dbOk = true; } catch (Throwable $e) { $dbErr = $e->getMessage(); }
$add('MySQL / MariaDB connection', $dbOk, $dbOk ? "Connected to {$cfg['db']['host']}:{$cfg['db']['port']} as {$cfg['db']['user']}" : 'Start MySQL in the XAMPP Control Panel. ' . ($dbErr ?? ''));
if ($dbOk) {
    try { Db::get()->val('SELECT COUNT(*) FROM documents'); $tablesOk = true; } catch (Throwable) {}
    $add('ATLAS database tables', $tablesOk, $tablesOk ? "Database `{$cfg['db']['name']}` is installed" : 'Click “Install database” below');
}

$st = (new Ollama())->status();
$add('Ollama running', $st['online'], $st['online'] ? 'Reachable at ' . $cfg['ollama']['url'] : 'Install from ollama.com and start it (it runs in the system tray).');
if ($st['online']) {
    foreach (['chat' => 'Chat model', 'embed' => 'Embedding model', 'vision' => 'Vision model'] as $k => $label) {
        $m = $st[$k]['name'];
        if ($m === '') { $add($label, true, 'Disabled', false); continue; }
        $add("$label: $m", $st[$k]['installed'], $st[$k]['installed'] ? 'Installed' : "Open a terminal and run:  ollama pull $m", $k !== 'vision');
    }
}
$pdftotext = DocParser::tool('pdftotext'); $tess = DocParser::tool('tesseract');
$add('Poppler pdftotext (optional)', (bool)$pdftotext, $pdftotext ?: 'Optional: better PDF text extraction + scanned-PDF OCR', false);
$add('Tesseract OCR (optional)', (bool)$tess, $tess ?: 'Optional: reads text inside images and scanned PDFs', false);

$requiredOk = !array_filter($checks, fn($c) => $c['required'] && !$c['ok']);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>ATLAS · Mnemo setup</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/app.css">
<style>body{overflow:auto}.wrap{max-width:760px;margin:40px auto;padding:0 20px}.row{display:flex;gap:12px;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--line)}.row:last-child{border:0}
.ic{width:22px;height:22px;border-radius:50%;display:grid;place-items:center;flex:none;font-size:12px;font-weight:700}.ok{background:rgba(52,211,153,.15);color:var(--ok)}.no{background:rgba(248,113,113,.15);color:var(--bad)}.opt{background:var(--panel-3);color:var(--text-3)}
code{font:12px var(--mono);background:var(--panel-3);padding:2px 6px;border-radius:4px}</style></head>
<body><div class="wrap">
  <div style="display:flex;align-items:center;gap:12px;margin-bottom:24px">
    <img src="assets/icon.svg" alt="" style="width:46px;height:46px;border-radius:12px;box-shadow:0 0 24px rgba(34,211,238,.4)">
    <div><div style="font-size:20px;font-weight:650">ATLAS · Mnemo setup</div><div class="muted">Autonomous Knowledge Reconstruction Engine · Mnemo</div></div>
  </div>
  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.4);margin-bottom:14px">✅ <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(248,113,113,.4);margin-bottom:14px">❌ <?= htmlspecialchars($err) ?></div><?php endif; ?>
  <div class="card">
    <h4>System check</h4>
    <?php foreach ($checks as $c): ?>
      <div class="row"><span class="ic <?= $c['ok'] ? 'ok' : ($c['required'] ? 'no' : 'opt') ?>"><?= $c['ok'] ? '✓' : ($c['required'] ? '✗' : '–') ?></span>
        <div style="flex:1"><div style="font-weight:500"><?= htmlspecialchars($c['name']) ?><?= $c['required'] ? '' : ' <span class="muted">(optional)</span>' ?></div><div class="muted" style="font-size:12.5px"><?= htmlspecialchars($c['detail']) ?></div></div></div>
    <?php endforeach; ?>
  </div>
  <div style="display:flex;gap:10px;margin-top:16px;align-items:center">
    <?php if ($dbOk && !$tablesOk): ?>
      <form method="post"><input type="hidden" name="do" value="install"><button class="btn primary">Install database</button></form>
    <?php endif; ?>
    <a class="btn" href="setup.php">Re-check</a>
    <?php if ($tablesOk): ?><a class="btn <?= $requiredOk ? 'primary' : '' ?>" href="index.php">Open ATLAS →</a><?php endif; ?>
    <?php if ($tablesOk && !$requiredOk): ?><span class="muted" style="font-size:12.5px">You can open ATLAS now; uploads will be processed once Ollama and its models are ready.</span><?php endif; ?>
  </div>
  <?php if ($tablesOk): ?>
  <div class="card" style="margin-top:20px"><h4>Security · dedicated database user <?= $cfg['db']['user'] === 'root' ? '<span class="badge warn">recommended</span>' : '<span class="badge ok">done</span>' ?></h4>
    <?php if ($cfg['db']['user'] === 'root'): ?>
    <p class="muted" style="margin:0 0 10px;font-size:12.5px">ATLAS currently connects as <code>root</code>. Create a user that can only access the ATLAS database:</p>
    <form method="post" style="display:flex;gap:8px;flex-wrap:wrap"><input type="hidden" name="do" value="dbuser">
      <input class="input" name="user" value="atlas" style="width:140px"><input class="input" type="password" name="pass" placeholder="New password (8+ chars)" style="width:220px">
      <button class="btn">Create &amp; use it</button></form>
    <?php else: ?><p class="muted" style="margin:0;font-size:12.5px">Connected as <code><?= htmlspecialchars($cfg['db']['user']) ?></code>.</p><?php endif; ?>
  </div>
  <div class="card" style="margin-top:12px"><h4>Recommended XAMPP settings</h4>
    <div class="muted" style="font-size:12.5px;margin-bottom:6px"><code>C:\xampp\mysql\bin\my.ini</code>, under <code>[mysqld]</code>:</div>
<pre style="background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:10px;font:12px var(--mono);margin:0 0 10px">innodb_buffer_pool_size  = 1G
max_allowed_packet       = 64M
innodb_log_file_size     = 256M
innodb_ft_min_token_size = 2
character-set-server     = utf8mb4
collation-server         = utf8mb4_unicode_ci</pre>
    <div class="muted" style="font-size:12.5px;margin-bottom:6px"><code>C:\xampp\php\php.ini</code>:</div>
<pre style="background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:10px;font:12px var(--mono);margin:0">upload_max_filesize = 128M
post_max_size       = 128M
max_execution_time  = 300
memory_limit        = 512M</pre>
    <div class="muted" style="font-size:12px;margin-top:8px">Restart Apache and MySQL afterwards. Then in ATLAS → Memory → Maintenance, click “Rebuild search index”.</div>
  </div>
  <?php endif; ?>
  <div class="card" style="margin-top:20px"><h4>Quick start</h4><ol style="margin:0;padding-left:18px;line-height:1.9;color:var(--text-2)">
    <li>Start <b>Apache</b> and <b>MySQL</b> in the XAMPP Control Panel.</li>
    <li>Install <b>Ollama</b> (ollama.com), then in a terminal: <code>ollama pull <?= htmlspecialchars($cfg['ollama']['chat_model']) ?></code> and <code>ollama pull <?= htmlspecialchars($cfg['ollama']['embed_model']) ?></code><?= $cfg['ollama']['vision_model'] ? ' and <code>ollama pull ' . htmlspecialchars($cfg['ollama']['vision_model']) . '</code>' : '' ?></li>
    <li>Click <b>Install database</b> above, then open ATLAS and upload documents.</li>
    <li>Double-click <code>launcher\Create Desktop Shortcut.bat</code>. From then on, the <b>ATLAS</b> desktop icon starts everything and opens ATLAS as an app.</li>
    <li>Optional: schedule <code>worker\run-nightly.bat</code> daily in Windows Task Scheduler (sleep cycle + automatic backup).</li></ol></div>
</div></body></html>
