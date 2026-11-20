<?php
declare(strict_types=1);
require __DIR__ . '/src/bootstrap.php';
$installed = true;
try { Atlas\Db::get()->val('SELECT 1 FROM documents LIMIT 1'); } catch (Throwable) { $installed = false; }
if (!$installed) { header('Location: setup.php'); exit; }
atlas_migrate();
$v = (string)filemtime(__DIR__ . '/assets/js/app.js');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ATLAS · Mnemo</title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="icon" href="assets/icon-192.png" sizes="192x192" type="image/png">
<link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#0b0e14">
<link rel="apple-touch-icon" href="assets/icon-192.png">
</head>
<body>
<div class="app" id="app">

  <!-- Icon rail -->
  <nav class="rail">
    <div class="logo" title="ATLAS · Mnemo"><img src="assets/icon.svg" alt="ATLAS · Mnemo"></div>
    <button data-view="graph" data-tip="Knowledge graph" class="active"><i data-icon="graph"></i></button>
    <button data-view="ask" data-tip="Ask Mnemo"><i data-icon="chat"></i></button>
    <button data-view="documents" data-tip="Sources"><i data-icon="files"></i></button>
    <button data-view="review" data-tip="Review & truth"><i data-icon="scale"></i><span class="rail-badge" data-badge="review" hidden></span></button>
    <button data-view="practice" data-tip="Practice (flashcards)"><i data-icon="cards"></i><span class="rail-badge" data-badge="cards_due" hidden></span></button>
    <button data-view="quality" data-tip="Quality & self-tests"><i data-icon="target"></i></button>
    <button data-view="grow" data-tip="Grow (baby mode)"><i data-icon="sprout"></i><span class="rail-badge" data-badge="questions" hidden></span></button>
    <button data-view="timeline" data-tip="Timeline"><i data-icon="clock"></i></button>
    <button data-view="dashboard" data-tip="Brain overview"><i data-icon="pulse"></i></button>
    <button data-view="memory" data-tip="Memory & storage"><i data-icon="database"></i></button>
    <div class="spacer"></div>
    <button id="btnTutorial" data-tip="Tutorial & help (?)"><i data-icon="school"></i><span class="rail-badge" id="tutBadge" hidden></span></button>
    <button id="btnToggleSide" data-tip="Toggle explorer"><i data-icon="sidebar"></i></button>
    <button id="btnToggleInsp" data-tip="Toggle inspector"><i data-icon="panel"></i></button>
    <button id="btnSettings" data-tip="Settings"><i data-icon="gear"></i></button>
  </nav>

  <!-- Top bar -->
  <header class="topbar">
    <div class="brand">ATLAS <small>Mnemo AI</small></div>
    <div class="tabs">
      <button class="tab active" data-view="graph"><i data-icon="graph"></i><span>Graph</span></button>
      <button class="tab" data-view="ask"><i data-icon="chat"></i><span>Ask Mnemo</span></button>
      <button class="tab" data-view="documents"><i data-icon="files"></i><span>Sources</span></button>
      <button class="tab" data-view="review"><i data-icon="scale"></i><span>Review</span><b class="tab-count" data-badge="review" hidden></b></button>
      <button class="tab" data-view="practice"><i data-icon="cards"></i><span>Practice</span><b class="tab-count" data-badge="cards_due" hidden></b></button>
      <button class="tab" data-view="quality"><i data-icon="target"></i><span>Quality</span></button>
      <button class="tab" data-view="grow"><i data-icon="sprout"></i><span>Grow</span><b class="tab-count" data-badge="questions" hidden></b></button>
      <button class="tab" data-view="timeline"><i data-icon="clock"></i><span>Timeline</span></button>
      <button class="tab" data-view="dashboard"><i data-icon="pulse"></i><span>Overview</span></button>
      <button class="tab" data-view="memory"><i data-icon="database"></i><span>Memory</span><span class="tab-alert" id="memAlert" hidden></span></button>
    </div>
    <div class="search">
      <i data-icon="search"></i>
      <input id="globalSearch" placeholder="Search concepts, people, documents…" autocomplete="off">
      <button class="search-img" id="btnImageSearch" title="Search with an image"><i data-icon="camera"></i></button>
      <input type="file" id="imageSearchFile" accept="image/*" hidden>
      <kbd>Ctrl K</kbd>
      <div class="search-results" id="searchResults" hidden></div>
    </div>
    <div class="top-actions">
      <div class="pill-status" id="cardPill" title="Memory card: click to switch, insert or eject"><i data-icon="sdcard"></i><span id="cardName">Memory</span><i data-icon="chevDown" class="sm"></i></div>
      <div class="card-menu glass" id="cardMenu" hidden></div>
      <div class="pill-status" id="growPill" title="Mnemo's growth"><i data-icon="sprout"></i><span id="growText">Newborn</span></div>
      <div class="pill-status" id="mnemoStatus" title="Local AI status"><span class="dot"></span><span>Mnemo</span></div>
      <button class="btn" id="btnDownloadAI" title="Download my AI as software"><i data-icon="download"></i>Download my AI</button>
      <button class="btn primary" id="btnUpload"><i data-icon="upload"></i>Teach</button>
    </div>
  </header>

  <!-- Explorer -->
  <aside class="sidebar">
    <div class="side-head">Knowledge Explorer <button class="btn ghost sm icon" id="btnRefreshTree" title="Refresh"><i data-icon="refresh"></i></button></div>
    <div class="side-scroll" id="tree"></div>
    <div class="side-foot">
      <button class="btn sm" id="btnNewCollection"><i data-icon="folderPlus"></i>Collection</button>
      <button class="btn sm" id="btnSleep" title="Run Mnemo's consolidation cycle now"><i data-icon="moon"></i>Sleep cycle</button>
    </div>
  </aside>

  <!-- Main views -->
  <main class="main">
    <section class="view active" id="view-graph">
      <div class="graph-wrap">
        <div id="graph"></div>
        <div class="graph-bar">
          <div class="glass seg" id="graphMode">
            <button data-mode="2d" class="on"><i data-icon="graph"></i>2D</button>
            <button data-mode="3d"><i data-icon="cube"></i>3D</button>
          </div>
          <div class="glass seg" id="graphColor">
            <button data-color="cluster" class="on">Topics</button>
            <button data-color="type">Types</button>
          </div>
          <div class="glass pad">
            <i data-icon="filter"></i>
            <select class="select" id="gfType" style="height:24px;border:0;background:transparent"><option value="">All types</option></select>
          </div>
          <div class="glass pad" title="Hide links below this confidence">
            Confidence <input type="range" id="gfConf" min="0" max="0.95" step="0.05" value="0" style="width:90px"><span id="gfConfV" class="muted">0%</span>
          </div>
          <div class="glass pad" title="Max nodes">
            Nodes <select class="select" id="gfLimit" style="height:24px;border:0;background:transparent"><option>200</option><option selected>600</option><option>1500</option><option>3000</option></select>
          </div>
          <div style="flex:1"></div>
          <button class="btn sm glass" id="btnPath"><i data-icon="route"></i>Path finder</button>
          <button class="btn sm icon glass" id="btnLabels" title="Toggle labels"><i data-icon="type"></i></button>
          <button class="btn sm icon glass" id="btnFit" title="Zoom to fit"><i data-icon="fit"></i></button>
          <button class="btn sm icon glass" id="btnReloadGraph" title="Reload"><i data-icon="refresh"></i></button>
        </div>
        <div class="glass path-panel" id="pathPanel">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px"><b style="font-size:12.5px">How are these connected?</b><button class="btn ghost sm icon" id="pathClose"><i data-icon="x"></i></button></div>
          <div style="display:flex;flex-direction:column;gap:6px">
            <input class="input" id="pathFrom" placeholder="From concept…" list="entityList">
            <input class="input" id="pathTo" placeholder="To concept…" list="entityList">
            <button class="btn primary sm" id="pathGo"><i data-icon="route"></i>Find path</button>
          </div>
          <div id="pathResult" style="margin-top:10px;font-size:12.5px"></div>
          <datalist id="entityList"></datalist>
        </div>
        <div class="glass graph-legend" id="legend"></div>
        <div class="glass graph-stats" id="graphStats"></div>
        <div class="glass graph-tip" id="graphTip"></div>
        <div class="graph-empty" id="graphEmpty" hidden>
          <div class="card glass">
            <div class="drop" style="border:0;background:none;padding:0;cursor:default">
              <div class="big"><i data-icon="sparkle" class="lg"></i></div>
            </div>
            <h3 style="margin:0 0 6px;color:var(--text)">Mnemo's memory is empty</h3>
            <p style="margin:0 0 16px">Upload documents or images and Mnemo will build an interconnected knowledge graph from them, growing every day.</p>
            <button class="btn primary" data-action="upload"><i data-icon="upload"></i>Upload your first documents</button>
          </div>
        </div>
      </div>
    </section>

    <section class="view" id="view-ask">
      <div class="view-head">
        <h2><i data-icon="chat"></i>Ask Mnemo</h2>
        <select class="select" id="convSelect" style="max-width:280px"><option value="">New conversation</option></select>
        <button class="btn sm ghost" id="btnNewChat"><i data-icon="plus"></i>New</button>
        <button class="btn sm" id="btnWatchlist"><i data-icon="eye"></i>Watchlist<b class="tab-count" data-badge="watch_unread" hidden></b></button>
        <div class="grow"></div>
        <span class="muted" style="font-size:12px">Answers come only from your sources, with citations</span>
      </div>
      <div class="chat">
        <div class="chat-scroll" id="chatScroll"><div class="chat-inner" id="chatInner"></div></div>
        <div class="composer">
          <div class="composer-inner">
            <div class="composer-box">
              <textarea id="chatInput" rows="1" placeholder="Ask anything about your knowledge…  (or: remember: …  define: …  correct: …)"></textarea>
              <button class="btn primary icon" id="chatSend" title="Send (Enter)"><i data-icon="send"></i></button>
            </div>
            <div class="composer-opts">
              <span class="hint-chip" data-ins="remember: ">remember:</span>
              <span class="hint-chip" data-ins="define: ">define:</span>
              <span class="hint-chip" data-ins="correct: ">correct:</span>
              <div style="flex:1"></div>
              <label style="display:flex;align-items:center;gap:6px" title="Answer using only knowledge Mnemo had on this date">Time travel <input type="date" class="input" id="chatAsOf"></label>
              <select class="select" id="chatCollection"><option value="">All collections</option></select>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="view" id="view-documents">
      <div class="view-head">
        <h2><i data-icon="files"></i>Sources</h2>
        <input class="input" id="docFilter" placeholder="Filter…" style="width:220px">
        <select class="select" id="docStatus"><option value="">All statuses</option><option value="ready">Ready</option><option value="processing">Learning</option><option value="queued">Queued</option><option value="failed">Failed</option></select>
        <div class="grow"></div>
        <button class="btn sm" id="btnProcess"><i data-icon="play"></i>Process queue</button>
        <button class="btn sm primary" data-action="upload"><i data-icon="upload"></i>Upload</button>
      </div>
      <div class="view-body" style="padding:0">
        <table class="table" id="docTable"><thead><tr><th>Source</th><th>Collection</th><th>Status</th><th>Chunks</th><th>Concepts</th><th>Trust</th><th>Added</th><th></th></tr></thead><tbody></tbody></table>
      </div>
    </section>

    <section class="view" id="view-review">
      <div class="view-head"><h2><i data-icon="scale"></i>Review &amp; truth</h2>
        <div class="glass seg" id="reviewTabs" style="background:var(--panel)">
          <button data-rt="contradictions" class="on">Contradictions <b class="tab-count" data-rc="contradictions"></b></button>
          <button data-rt="relations">Facts to verify <b class="tab-count" data-rc="relations"></b></button>
          <button data-rt="merges">Duplicates <b class="tab-count" data-rc="merges"></b></button>
          <button data-rt="hypotheses">New connections <b class="tab-count" data-rc="hypotheses"></b></button>
        </div>
        <div class="grow"></div>
        <button class="btn sm" id="btnTruthScan" title="Fact-check new claims, find duplicates and possible connections now"><i data-icon="sparkle"></i>Scan now</button></div>
      <div class="view-body" id="review"></div>
    </section>

    <section class="view" id="view-practice">
      <div class="view-head"><h2><i data-icon="cards"></i>Practice</h2><span class="muted" style="font-size:12px">Mnemo teaches you back with spaced repetition</span><div class="grow"></div>
        <button class="btn sm" id="btnCardsConcepts"><i data-icon="bulb"></i>Cards from concepts</button>
        <button class="btn sm" id="btnCardsSources"><i data-icon="sparkle"></i>AI cards from sources</button></div>
      <div class="view-body" id="practice"></div>
    </section>

    <section class="view" id="view-quality">
      <div class="view-head"><h2><i data-icon="target"></i>Quality &amp; self-improvement</h2><div class="grow"></div>
        <a class="btn sm ghost" href="api/index.php?action=training_export"><i data-icon="download"></i>Training data (.jsonl)</a>
        <a class="btn sm ghost" href="docs/finetune.html" target="_blank"><i data-icon="book"></i>Fine-tune guide</a>
        <button class="btn sm" id="btnSelfQuiz"><i data-icon="sparkle"></i>Self-quiz now</button>
        <button class="btn sm primary" id="btnRunTests"><i data-icon="play"></i>Run benchmark</button></div>
      <div class="view-body" id="quality"></div>
    </section>

    <section class="view" id="view-grow">
      <div class="view-head"><h2><i data-icon="sprout"></i>Grow</h2><span class="muted" style="font-size:12px">Mnemo starts as a newborn and grows with everything you teach it, without limit</span><div class="grow"></div>
        <button class="btn sm" id="btnWonder"><i data-icon="help"></i>What are you curious about?</button></div>
      <div class="view-body" id="growBody"></div>
    </section>

    <section class="view" id="view-timeline">
      <div class="view-head"><h2><i data-icon="clock"></i>Learning timeline</h2><div class="grow"></div><span class="muted" style="font-size:12px">Everything Mnemo learned, in order</span></div>
      <div class="view-body"><div class="tl" id="timeline"></div></div>
    </section>

    <section class="view" id="view-dashboard">
      <div class="view-head"><h2><i data-icon="pulse"></i>Brain overview</h2><div class="grow"></div>
        <button class="btn sm" data-action="sleep"><i data-icon="moon"></i>Run sleep cycle</button></div>
      <div class="view-body" id="dashboard"></div>
    </section>
    <section class="view" id="view-memory">
      <div class="view-head"><h2><i data-icon="database"></i>Memory &amp; storage</h2><div class="grow"></div>
        <span class="muted" style="font-size:12px">Everything Mnemo knows lives in your MySQL database</span>
        <button class="btn sm" id="btnMemRefresh"><i data-icon="refresh"></i>Refresh</button></div>
      <div class="view-body" id="memory"></div>
    </section>
  </main>

  <!-- Inspector -->
  <aside class="inspector">
    <div class="insp-head"><span id="inspTitle">Inspector</span><button class="btn ghost sm icon" id="inspBack" title="Back" hidden><i data-icon="back"></i></button></div>
    <div class="insp-body" id="insp"></div>
  </aside>

  <footer class="statusbar">
    <span class="sb" id="sbCounts">—</span>
    <span class="sb sb-storage" id="sbStorage" title="Database size / free disk"></span>
    <span class="grow"></span>
    <button class="sb sb-dl" id="sbDl" hidden title="Download my AI"></button>
    <span class="sb" id="sbWork"><span class="dot"></span><span>Idle</span></span>
    <span class="sb sb-prog" id="sbProgWrap" hidden><div class="progress"><i id="sbProg"></i></div><b id="sbPct">0%</b><span id="sbEta" class="muted"></span></span>
    <button class="sb sb-sys" id="sbSys" title="Background parts: click for details and logs"><span class="dot" data-sys="ai"></span>AI<span class="dot" data-sys="memory"></span>Memory<span class="dot" data-sys="learner"></span>Learner</button>
    <span class="sb" id="sbModel"></span>
  </footer>
</div>

<!-- Upload modal -->
<div class="modal-bg" id="uploadModal">
  <div class="modal">
    <div class="modal-h">Teach Mnemo <button class="btn ghost sm icon" data-close><i data-icon="x"></i></button></div>
    <div class="modal-b">
      <div class="drop" id="dropZone">
        <div class="big"><i data-icon="upload" class="lg"></i></div>
        <b style="color:var(--text)">Drop files here or click to browse</b>
        <div class="muted" style="margin-top:4px">PDF · DOCX · PPTX · TXT · MD · HTML · CSV · JSON · PNG · JPG · WEBP · MP3 · WAV · MP4 (audio/video need Whisper)</div>
        <input type="file" id="fileInput" multiple hidden accept=".pdf,.docx,.pptx,.txt,.md,.markdown,.csv,.html,.htm,.json,.xml,.log,.png,.jpg,.jpeg,.webp,.gif,.bmp,.mp3,.wav,.m4a,.ogg,.flac,.aac,.wma,.mp4,.mkv,.webm,.mov,.avi">
      </div>
      <label class="fld">Collection <select class="select" id="uploadCollection"></select></label>
      <div id="uploadList"></div>
      <div class="muted" style="font-size:12px">Tip: you can also drop files into <code>htdocs/atlas/storage/inbox</code>. They're picked up automatically.</div>
    </div>
    <div class="modal-f"><button class="btn" data-close>Close</button></div>
  </div>
</div>

<!-- Insert memory card -->
<div class="modal-bg" id="insertModal">
  <div class="modal">
    <div class="modal-h"><span style="display:flex;gap:8px;align-items:center"><i data-icon="sdcard"></i>Insert memory card</span><button class="btn ghost sm icon" data-close><i data-icon="x"></i></button></div>
    <div class="modal-b">
      <div class="drop" id="cardDrop"><div class="big"><i data-icon="sdcard" class="lg"></i></div><b style="color:var(--text)">Choose a card file</b><div class="muted" style="margin-top:4px">.atlascard, or a full backup .zip</div>
        <input type="file" id="cardFile" accept=".atlascard,.zip" hidden></div>
      <div id="cardLabel"></div>
    </div>
    <div class="modal-f"><button class="btn" data-close>Cancel</button><button class="btn primary" id="cardInsertGo" disabled><i data-icon="sdcard"></i>Insert card</button></div>
  </div>
</div>

<!-- Eject memory card -->
<div class="modal-bg" id="ejectModal">
  <div class="modal">
    <div class="modal-h"><span style="display:flex;gap:8px;align-items:center"><i data-icon="download"></i>Eject memory card</span><button class="btn ghost sm icon" data-close><i data-icon="x"></i></button></div>
    <div class="modal-b">
      <div id="ejectWhat" style="font-size:13.5px"></div>
      <label class="fld"><span>Password (optional, encrypts the card file)</span><input class="input" type="password" id="ejectPass" autocomplete="new-password" placeholder="Leave empty for no password"></label>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px"><input type="checkbox" id="ejectRemove"> After saving, remove this memory from this PC (Mnemo forgets it until you insert the card again)</label>
      <div class="muted" style="font-size:12px">The card file contains the whole MySQL memory and the original files. Keep it on a USB drive. There is no way to recover a forgotten password.</div>
    </div>
    <div class="modal-f"><button class="btn" data-close>Cancel</button><button class="btn primary" id="ejectGo"><i data-icon="download"></i>Save card file</button></div>
  </div>
</div>

<!-- Watchlist -->
<div class="modal-bg" id="watchModal">
  <div class="modal" style="width:min(760px,94vw)">
    <div class="modal-h"><span style="display:flex;gap:8px;align-items:center"><i data-icon="eye"></i>Watchlist · standing questions</span><button class="btn ghost sm icon" data-close><i data-icon="x"></i></button></div>
    <div class="modal-b">
      <div class="muted" style="font-size:12.5px">Mnemo re-answers these questions every sleep cycle and tells you when new knowledge changes the answer.</div>
      <div style="display:flex;gap:8px"><input class="input" id="watchInput" placeholder="e.g. What is the latest research on memory consolidation?" style="flex:1"><button class="btn primary" id="watchAdd"><i data-icon="plus"></i>Watch</button></div>
      <div id="watchList"></div>
    </div>
    <div class="modal-f"><button class="btn" id="watchCheckAll"><i data-icon="refresh"></i>Check all now</button><button class="btn" data-close>Close</button></div>
  </div>
</div>

<!-- Merge -->
<div class="modal-bg" id="mergeModal">
  <div class="modal">
    <div class="modal-h">Merge duplicate concept <button class="btn ghost sm icon" data-close><i data-icon="x"></i></button></div>
    <div class="modal-b">
      <div id="mergeWhat" style="font-size:13.5px"></div>
      <label class="fld"><span>Merge it into (the concept that stays)</span><input class="input" id="mergeTarget" list="entityList" placeholder="Start typing a concept name…"></label>
      <div class="muted" style="font-size:12px">All mentions, links and facts move to the kept concept, and the old name becomes an alias. You can undo it later.</div>
    </div>
    <div class="modal-f"><button class="btn" data-close>Cancel</button><button class="btn primary" id="mergeGo"><i data-icon="merge"></i>Merge</button></div>
  </div>
</div>

<!-- Wipe confirmation -->
<div class="modal-bg" id="wipeModal">
  <div class="modal">
    <div class="modal-h"><span style="color:var(--bad);display:flex;gap:8px;align-items:center"><i data-icon="alert"></i>Delete memory</span><button class="btn ghost sm icon" data-close><i data-icon="x"></i></button></div>
    <div class="modal-b">
      <div id="wipeWhat" style="font-size:13.5px"></div>
      <label style="display:flex;gap:8px;align-items:center;font-size:12.5px"><input type="checkbox" id="wipeBackup" checked> Save a safety backup first (recommended, lets you undo)</label>
      <label class="fld"><span>Type <b style="color:var(--text)">DELETE</b> to confirm</span><input class="input" id="wipeConfirm" autocomplete="off" placeholder="DELETE"></label>
    </div>
    <div class="modal-f"><button class="btn" data-close>Cancel</button><button class="btn danger-solid" id="wipeGo" disabled><i data-icon="trash"></i>Delete permanently</button></div>
  </div>
</div>

<!-- Settings modal -->
<div class="modal-bg" id="settingsModal">
  <div class="modal">
    <div class="modal-h">Settings <button class="btn ghost sm icon" data-close><i data-icon="x"></i></button></div>
    <div class="modal-b" id="settingsBody"></div>
    <div class="modal-f"><button class="btn" data-close>Cancel</button><button class="btn primary" id="settingsSave">Save</button></div>
  </div>
</div>

<div class="global-drop" id="globalDrop"><div class="drop"><div class="big"><i data-icon="upload" class="lg"></i></div><b style="font-size:16px;color:var(--text)">Drop to teach Mnemo</b></div></div>
<!-- Tutorial -->
<div class="modal-bg" id="tutHub"><div class="modal tut-hub" id="tutHubBody"></div></div>
<div class="tour-block" id="tourBlock" hidden></div>
<div class="tour-spot" id="tourSpot" hidden></div>
<div class="tour-pop" id="tourPop" hidden></div>
<div class="toasts" id="toasts"></div>

<script src="assets/vendor/force-graph.min.js"></script>
<script src="assets/vendor/3d-force-graph.min.js"></script>
<script src="assets/js/app.js?v=<?= $v ?>"></script>
<script src="assets/js/tutorial.js?v=<?= (string)@filemtime(__DIR__ . '/assets/js/tutorial.js') ?>"></script>
</body>
</html>
