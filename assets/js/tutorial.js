/* ATLAS tutorial mode: guided tours, Learn hub, self-checking checklist, demo knowledge pack. */
(() => {
'use strict';
const A = window.ATLAS;
if (!A) return;
const {api, toast, icon, esc, $, $$} = A;

// ---------------------------------------------------------------- chapters
// Each step: {view?, el?, title, body, place?, before?(), after?(), action?: {label, run}}
const closeModals = () => $$('.modal-bg.show').forEach(m => { if (m.id !== 'tutHub') m.classList.remove('show'); });
const CHAPTERS = [
  {id: 'welcome', icon: 'sparkle', title: 'Welcome & the workspace', time: '2 min', desc: 'A quick tour of the screen: where everything is and what it does.', steps: [
    {view: 'graph', title: 'Welcome to ATLAS 👋', body: `ATLAS is your <b>offline knowledge engine</b>. Its AI, <b>Mnemo</b>, starts with zero knowledge and learns from every document, image and fact you give it. Everything is stored in <b>your own MySQL database</b>, on your PC.<br><br>This tour takes about 2 minutes. Use <kbd>→</kbd> / <kbd>←</kbd> or the buttons. <kbd>Esc</kbd> exits.`},
    {el: '.rail', place: 'right', title: 'Navigation rail', body: 'Jump between every part of ATLAS. The dots are badges: something needs your attention there.'},
    {el: '.tabs', place: 'bottom', title: 'Views', body: '<b>Graph</b> · <b>Ask</b> · <b>Sources</b> · <b>Review</b> · <b>Practice</b> · <b>Quality</b> · <b>Timeline</b> · <b>Overview</b> · <b>Memory</b>.<br>Shortcut: keys <kbd>1</kbd>–<kbd>9</kbd>.'},
    {el: '.sidebar', place: 'right', title: 'Knowledge Explorer', body: 'Your knowledge as a tree: <b>collections</b> of documents, <b>topic clusters</b> Mnemo discovered, and every concept grouped by <b>type</b>. Click anything to inspect it.'},
    {el: '.graph-wrap', place: 'left', title: 'The knowledge graph', body: 'Every dot is a concept Mnemo learned, and every line is a relationship it found. The graph grows as you teach Mnemo more.'},
    {el: '.inspector', place: 'left', title: 'Inspector', body: 'Details of whatever you select: a concept\'s description, relationships, facts, evidence, sources and history. With nothing selected it shows an overview of Mnemo\'s memory.'},
    {el: '#globalSearch', place: 'bottom', title: 'Search everything', body: 'Find concepts and documents instantly (<kbd>Ctrl</kbd>+<kbd>K</kbd>). The <b>camera</b> icon searches with an <b>image</b>.'},
    {el: '#mnemoStatus', place: 'bottom', title: 'Mnemo\'s status', body: '<b style="color:var(--ok)">Green</b>: the local AI (Ollama) is running with its models. <b style="color:var(--warn)">Yellow</b>: a model is missing. <b style="color:var(--bad)">Red</b>: Ollama is off. Click it to open Settings.'},
    {el: '.statusbar', place: 'top', title: 'Status bar', body: 'Live counts, <b>database size</b> and <b>free disk space</b> (it turns yellow or red when storage runs low), learning progress, and the active AI model.'},
    {el: '#btnUpload', place: 'bottom', title: 'Teach button', body: 'The most important button: give Mnemo documents, images or audio to learn from. You can also drag files anywhere onto the window.'},
    {el: '#btnTutorial', place: 'right', title: 'Come back anytime', body: 'This button (or the <kbd>?</kbd> key) opens the <b>Learn</b> hub with all chapters, your checklist and the demo knowledge pack. Every screen also has a <b>?</b> button for its own chapter.'},
  ]},
  {id: 'teach', icon: 'upload', title: 'Teach Mnemo documents', time: '2 min', desc: 'Upload files, follow the learning progress, and use the demo knowledge pack.', steps: [
    {el: '#btnUpload', place: 'bottom', title: 'Open the Teach window', body: 'Click <b>Teach</b>, press <kbd>U</kbd>, or just drop files onto ATLAS.'},
    {el: '#dropZone', place: 'right', before: () => A.openUpload(), title: 'Drop your files here', body: 'Supported: <b>PDF, Word, PowerPoint, text, Markdown, HTML, CSV, JSON</b>, <b>images</b> (described by the vision model and read with OCR), and <b>audio/video</b> (transcribed offline with Whisper).'},
    {el: '#uploadCollection', place: 'right', title: 'Collections', body: 'Put each subject in its own collection, for example "Thesis", "Biology" or "Work". You can ask questions within one collection later.'},
    {el: '#uploadModal .modal-b .muted:last-child', place: 'right', title: 'The inbox folder', body: 'Files dropped into <code>htdocs/atlas/storage/inbox</code> are learned automatically, so you can even save straight from other programs.', after: closeModals},
    {view: 'documents', el: '#docTable', place: 'bottom', before: closeModals, title: 'Sources', body: 'Every source with its status: <b>Queued</b> → <b>Learning</b> (with a progress bar) → <b>Learned</b>. Here you can also move sources between collections, retry failed files or <b>forget</b> a source.'},
    {el: '#btnProcess', place: 'bottom', title: 'Learning runs by itself', body: 'While ATLAS is open, Mnemo learns automatically. This button restarts the queue if Ollama was off. The desktop launcher also keeps a background learner running.'},
    {view: 'documents', title: 'Try it with the demo pack', body: 'No documents of your own yet? Load 4 short astronomy documents. One is an <b>old 1995 textbook that says Pluto is the 9th planet</b>, so you\'ll also see how Mnemo catches <b>contradictions</b>.', action: {label: 'Load demo knowledge', run: loadDemo}},
  ]},
  {id: 'graph', icon: 'graph', title: 'Explore the knowledge graph', time: '2 min', desc: 'Navigate concepts, filter, find paths between ideas, switch to 3D.', steps: [
    {view: 'graph', el: '.graph-wrap', place: 'left', title: 'Reading the graph', body: '<b>Bigger dots</b> are mentioned more and have more links. <b>Colours</b> are topics Mnemo discovered. <b>Dashed purple lines</b> are links Mnemo inferred by itself during its sleep cycle.<br><br><b>Hover</b> a dot to light up its neighbours, <b>click</b> to inspect it, <b>scroll</b> to zoom, <b>drag</b> to pan.'},
    {el: '#graphMode', place: 'bottom', title: '2D or 3D', body: 'Switch to <b>3D</b> and orbit around your knowledge. Clicking a node flies the camera to it.'},
    {el: '#graphColor', place: 'bottom', title: 'Colour by topic or type', body: '<b>Topics</b> are clusters found automatically. <b>Types</b> are person, place, concept, technology, event and so on.'},
    {el: '#gfType', place: 'bottom', title: 'Filters', body: 'Show one type only, hide weak links with the <b>confidence</b> slider, or limit the number of nodes for big graphs.'},
    {el: '#btnPath', place: 'bottom', title: 'Path finder', body: '"How is <i>A</i> connected to <i>B</i>?" Pick two concepts and ATLAS highlights the chain of relationships between them.'},
    {el: '#legend', place: 'right', title: 'Legend', body: 'Click a topic or type to hide or show it.'},
    {el: '#tree', place: 'right', title: 'Topic clusters', body: 'Click a topic in the explorer to zoom the graph onto it.'},
    {el: '.inspector', place: 'left', title: 'Concept details', body: 'When you open a concept you see its <b>description</b>, <b>relationships</b>, <b>facts</b> (with ✓ support counts or disputed warnings), the original <b>evidence</b>, the <b>sources</b>, and its <b>history</b>.<br><br>Buttons let you ask about it, <b>merge</b> a duplicate, or <b>forget</b> it.', action: {label: 'Open the biggest concept', run: () => { const n = [...A.S.data.nodes].sort((a, b) => (b.mentions + b.degree) - (a.mentions + a.degree))[0]; if (n) A.openEntity(n.id, true); else toast('No concepts yet. Teach Mnemo something first.'); }}},
  ]},
  {id: 'ask', icon: 'chat', title: 'Ask & teach Mnemo', time: '3 min', desc: 'Cited answers, teaching commands, feedback, time travel and the watchlist.', steps: [
    {view: 'ask', el: '#chatInput', place: 'top', title: 'Ask anything', body: 'Mnemo answers <b>only from your sources</b>, so it doesn\'t make things up. If it doesn\'t know, it says so and suggests what to upload.', action: {label: 'Ask an example question', run: () => { A.$('#chatInput').value = 'Give me an overview of what you know so far.'; A.autoGrow(); A.sendChat(); }}},
    {el: '#chatScroll', place: 'left', title: 'Citations', body: 'Every claim carries a numbered chip like <span class="cite">1</span>. Click it, or a source card, to read the exact passage it came from.<br><br>A green banner means Mnemo reused an answer <b>you approved</b>. A yellow one means some facts in those sources are <b>disputed</b>.'},
    {el: '.composer-opts .hint-chip', place: 'top', title: 'Teach directly', body: '<code>remember:</code> stores a fact. <code>define:</code> stores a definition. <code>correct:</code> fixes something wrong.<br><br>What you teach has the <b>highest trust</b>: corrections automatically override conflicting facts from documents.'},
    {el: '#chatAsOf', place: 'top', title: 'Time travel', body: 'Pick a date and Mnemo answers using only what it knew <b>on that day</b>. It\'s a good way to see how its knowledge grew.'},
    {el: '#chatCollection', place: 'top', title: 'Ask within a collection', body: 'Limit the answer to one subject, for example only your thesis sources.'},
    {el: '#chatScroll', place: 'left', title: 'Feedback makes Mnemo smarter', body: 'Under each answer: <b>👍</b> stores it as an <b>approved answer</b> that Mnemo reuses for similar questions. <b>👎</b> removes it. <b>Watch</b> adds the question to your watchlist.'},
    {el: '#btnWatchlist', place: 'bottom', title: 'Watchlist', body: '<b>Standing questions</b>: Mnemo re-answers them every night and tells you when new knowledge changes the answer. Useful for "What\'s the latest on…?" questions.'},
  ]},
  {id: 'review', icon: 'scale', title: 'Truth & review', time: '2 min', desc: 'Contradictions, fact verification, duplicates and new connections.', steps: [
    {view: 'review', title: 'Mnemo fact-checks itself', body: 'Every fact Mnemo learns is stored as a <b>claim</b> and compared with what it already knows. When something looks wrong, it lands here for <b>your decision</b>. You are the final judge.'},
    {el: '#reviewTabs [data-rt=contradictions]', place: 'bottom', before: () => A.$('#reviewTabs [data-rt=contradictions]').click(), title: 'Contradictions', body: 'Two sources disagree. Choose <b>Left is right</b>, <b>Right is right</b>, <b>Both valid</b> (for example, different years) or <b>Ignore</b>. Mnemo stops trusting the losing fact everywhere.<br><br>With the demo pack: the 1995 textbook\'s "nine planets" vs the IAU 2006 reclassification.'},
    {el: '#reviewTabs [data-rt=relations]', place: 'bottom', before: () => A.$('#reviewTabs [data-rt=relations]').click(), title: 'Facts to verify', body: 'Links whose quoted evidence <b>isn\'t actually in the text</b>. These are likely AI mistakes. Approve the true ones and delete the rest.'},
    {el: '#reviewTabs [data-rt=merges]', place: 'bottom', before: () => A.$('#reviewTabs [data-rt=merges]').click(), title: 'Duplicates', body: 'Concepts that look like the same thing ("NASA" and "National Aeronautics…"). Merging combines all their links and can be <b>undone</b>.'},
    {el: '#reviewTabs [data-rt=hypotheses]', place: 'bottom', before: () => A.$('#reviewTabs [data-rt=hypotheses]').click(), title: 'New connections', body: 'Concepts that are never linked directly but share several neighbours. Mnemo suggests that they may be related. Accept, reject, or ask Mnemo about it.'},
    {el: '#btnTruthScan', place: 'bottom', title: 'Scan now', body: 'Runs the checks immediately. They also run automatically during learning and in every sleep cycle.'},
  ]},
  {id: 'practice', icon: 'cards', title: 'Practice with flashcards', time: '1 min', desc: 'Mnemo teaches you back using spaced repetition.', steps: [
    {view: 'practice', title: 'Mnemo teaches you back', body: 'ATLAS turns your knowledge into <b>flashcards</b> and schedules them using <b>spaced repetition</b>: you see each card just before you would forget it.'},
    {el: '#btnCardsConcepts', place: 'bottom', title: 'Make cards', body: '<b>Cards from concepts</b> is instant and uses concept descriptions. <b>AI cards from sources</b> has Mnemo write questions from your passages. New concept cards are also created every night.'},
    {el: '#practice', place: 'top', title: 'Study', body: 'Click the card or press <kbd>Space</kbd> to flip it, then grade yourself: <kbd>1</kbd> Again · <kbd>2</kbd> Hard · <kbd>3</kbd> Good · <kbd>4</kbd> Easy. The mastery boxes show your progress.'},
  ]},
  {id: 'quality', icon: 'target', title: 'Quality & self-improvement', time: '2 min', desc: 'Measure whether Mnemo is getting smarter, with real numbers.', steps: [
    {view: 'quality', title: 'Is Mnemo getting smarter?', body: 'This page <b>measures</b> it. That matters for trusting Mnemo, and it gives you measurable results for a thesis or report.'},
    {el: '#gtQ', place: 'bottom', title: 'Benchmark questions', body: 'Add questions <b>you know the answers to</b>. 20–50 is ideal. The demo pack adds 3 examples.'},
    {el: '#btnRunTests', place: 'bottom', title: 'Run the benchmark', body: 'Mnemo answers every question and a grader scores each answer. It runs nightly, and the chart shows your score over time as knowledge grows.'},
    {el: '#btnSelfQuiz', place: 'bottom', title: 'Self-quiz', body: 'Mnemo writes questions from what it learned, answers them, and grades itself. Low scores and ⚠ "passage not found" marks show its weak spots. Add good ones to the benchmark with <b>+ Test</b>.'},
    {el: '.view-head a[href*=training_export]', place: 'bottom', title: 'Training data & fine-tuning', body: 'Export your verified knowledge (approved answers, tests, taught facts) to <b>fine-tune</b> the model itself. This step is optional; the guide explains how.'},
  ]},
  {id: 'memory', icon: 'database', title: 'Memory, storage & safety', time: '2 min', desc: 'Storage warnings, downloading the database, restoring, and deleting memory.', steps: [
    {view: 'memory', title: 'Mnemo\'s memory lives in MySQL', body: 'Everything Mnemo knows (documents, passages, the graph, facts, chats and history) is in the <b>atlas</b> MySQL database. This page lets you watch it, save it and control it.'},
    {el: '#memCards', place: 'bottom', title: 'Memory cards', body: 'Like a memory card in a phone. Each card is its own MySQL database. <b>Eject</b> saves a card to one file (optionally password-protected) and can remove it from this PC. <b>Insert</b> a card file and Mnemo instantly knows everything on it. <b>New blank card</b> gives you a newborn Mnemo. One card is active at a time.'},
    {el: '#cardPill', place: 'bottom', title: 'Quick card switcher', body: 'The active card is always shown here. Click it to switch, insert or eject from any screen.'},
    {el: '.gauges', place: 'bottom', title: 'Storage gauges', body: 'Database size, free disk space, original files and backups. You set the <b>warning levels</b>, and ATLAS warns you in the status bar and pauses uploads before the disk is full.'},
    {el: '#bkDb', place: 'bottom', title: 'Download the memory anytime', body: '<b>Back up database</b> saves one .sql.gz file. <b>Full backup</b> also includes your original files (.zip). Keep a copy on a USB drive. An automatic backup is also made every night.'},
    {el: '#bkRestore', place: 'bottom', title: 'Restore', body: 'Load any backup, even on another PC. A safety copy of the current memory is saved first, so a restore can always be undone.'},
    {el: '.limit-row.h', place: 'top', title: 'Limits', body: 'MySQL has no practical size limit. The real limits are PHP upload size and MySQL packet size. This table shows your current values next to the recommended ones.'},
    {el: '.danger-zone', place: 'top', title: 'Delete memory', body: 'Delete chat history, taught facts, self-learning data, or the graph (Mnemo re-learns it from your sources), or reset Mnemo to <b>zero knowledge</b>. You must type DELETE, and a safety backup is made first.'},
  ]},
  {id: 'grow', icon: 'sprout', title: 'Grow: raise Mnemo like a baby', time: '2 min', desc: 'Levels without end, Mnemo\'s questions for you, lessons with exams, and the diary.', steps: [
    {view: 'grow', title: 'Mnemo starts as a newborn', body: 'Mnemo is born knowing <b>nothing</b>. In <b>strict mode</b> it only answers from what you taught it, and says “I don\'t know that yet” otherwise.<br><br>Everything you feed it adds <b>experience</b>. It grows from Newborn to Infant, Toddler, Child, Student and beyond. <b>The levels never end</b>.'},
    {el: '.grow-hero', place: 'bottom', title: 'Level and stage', body: 'The current stage, the XP bar to the next level, and benchmark accuracy. The same badge is always visible in the top bar.'},
    {el: '#btnWonder', place: 'bottom', title: 'Mnemo asks you', body: 'A curious child asks questions. Mnemo asks about things it has seen but nobody explained: “What is X? I\'ve come across it 5 times…”. Your answer becomes top-trust knowledge. New questions also appear after each sleep cycle.'},
    {el: '#lsTitle', place: 'right', title: 'Lessons', body: 'Teach like a parent or teacher: a title and one simple fact per line. Start with words and definitions, then facts, then how things relate.'},
    {el: '#lsTeach', place: 'right', title: 'Then examine it', body: 'After a lesson, click <b>Examine Mnemo</b>: it is asked questions about the lesson and gets a school grade from A to F. Re-teach what it gets wrong.'},
    {el: '#babyStrict', place: 'left', title: 'How Mnemo learns', body: '<b>Strict mode</b> keeps Mnemo honest: no knowledge except yours. <b>Forgetting</b> is optional. When it is off, Mnemo never forgets unless you delete something.'},
  ]},
  {id: 'routine', icon: 'moon', title: 'Daily routine & offline app', time: '2 min', desc: 'The sleep cycle, overview, timeline, settings and the desktop launcher.', steps: [
    {el: '#btnSleep', place: 'top', view: 'graph', title: 'The sleep cycle', body: 'Like sleep for a brain, this is where Mnemo <b>connects ideas</b>: it infers new links, discovers topics, fact-checks, finds duplicates, makes flashcards, updates the watchlist, quizzes itself, runs the benchmark and <b>backs up</b>. Schedule <code>worker\\run-nightly.bat</code> or click this button.'},
    {view: 'dashboard', el: '#dashboard', place: 'top', title: 'Overview', body: 'Brain-growth chart, the daily digest, the most-connected concepts, your learning streak and <b>knowledge gaps</b>: topics mentioned often but never explained. Upload material about those next.'},
    {view: 'timeline', el: '#timeline', place: 'top', title: 'Timeline', body: 'Everything Mnemo learned, day by day: sources, concepts, contradictions, merges, benchmarks and backups.'},
    {el: '#btnSettings', place: 'right', title: 'Settings', body: 'Choose the AI models (chat, embeddings, vision) and see which optional tools are installed (OCR, Whisper and so on).'},
    {title: 'An offline desktop app', body: 'Run <code>launcher\\Create Desktop Shortcut.bat</code> once. The <b>ATLAS</b> icon then starts MySQL, Apache, Ollama and the background learner, and opens ATLAS in its own window. You can also use your browser menu → <b>Install ATLAS</b>.<br><br><b>Daily habit:</b> upload → ask → resolve Review items → practice → let the sleep cycle run. 🎓'},
  ]},
];
const VIEW_CHAPTER = {grow: 'grow', graph: 'graph', ask: 'ask', documents: 'teach', review: 'review', practice: 'practice', quality: 'quality', memory: 'memory', dashboard: 'routine', timeline: 'routine'};

// ---------------------------------------------------------------- state
let state = {done: [], welcomed: false, flags: {}};
let tour = null; // {chapter, i}

async function loadState() {
  try { const t = await api('tutorial'); state = t.state; return t; } catch { return null; }
}
const save = patch => api('tutorial_save', patch).then(s => { state = s; updateBadge(); }).catch(() => {});

function updateBadge(t) {
  const left = CHAPTERS.filter(c => !state.done.includes(c.id)).length;
  $('#tutBadge').hidden = left === 0 || state.done.length > 0;
}

// ---------------------------------------------------------------- tour engine
function start(id, at = 0) {
  const ch = CHAPTERS.find(c => c.id === id);
  if (!ch) return;
  $('#tutHub').classList.remove('show');
  tour = {chapter: ch, i: at};
  document.body.classList.add('touring');
  show();
}

async function show() {
  const {chapter, i} = tour;
  const st = chapter.steps[i];
  if (st.view && A.S.view !== st.view) { A.setView(st.view); await wait(450); }
  if (st.before) { try { await st.before(); } catch {} await wait(350); }
  let el = st.el ? $(st.el) : null;
  if (el && (el.offsetParent === null && getComputedStyle(el).position !== 'fixed')) el = null;
  if (el) { el.scrollIntoView({block: 'nearest', inline: 'nearest'}); await wait(80); }
  const pop = $('#tourPop'), spot = $('#tourSpot');
  $('#tourBlock').hidden = false;
  const total = chapter.steps.length;
  pop.innerHTML = `<div class="tp-head"><span class="tp-ch">${icon(chapter.icon, 'sm')}${esc(chapter.title)}</span><span class="tp-n">${i + 1} / ${total}</span></div>
    <div class="tp-title">${st.title}</div><div class="tp-body">${st.body}</div>
    ${st.action ? `<button class="btn sm" id="tpAction" style="margin-top:10px">${icon('play', 'sm')}${esc(st.action.label)}</button>` : ''}
    <div class="tp-dots">${chapter.steps.map((_, k) => `<i class="${k === i ? 'on' : k < i ? 'past' : ''}"></i>`).join('')}</div>
    <div class="tp-foot"><button class="btn ghost sm" id="tpSkip">Exit tour</button><span style="flex:1"></span>
      ${i > 0 ? `<button class="btn sm" id="tpBack">${icon('back', 'sm')}Back</button>` : ''}
      <button class="btn primary sm" id="tpNext">${i === total - 1 ? 'Finish ✓' : 'Next →'}</button></div>`;
  pop.hidden = false;
  $('#tpNext').onclick = next; $('#tpSkip').onclick = () => end(false);
  if ($('#tpBack')) $('#tpBack').onclick = back;
  if ($('#tpAction')) $('#tpAction').onclick = async () => { $('#tpAction').disabled = true; try { await st.action.run(); } catch (e) { toast(esc(e.message), 'bad'); } setTimeout(position, 600); };
  tour.el = el;
  position();
  $('#tpNext').focus({preventScroll: true});
}

function position() {
  if (!tour) return;
  const pop = $('#tourPop'), spot = $('#tourSpot'), el = tour.el;
  const st = tour.chapter.steps[tour.i];
  const vw = innerWidth, vh = innerHeight, pw = Math.min(380, vw - 24);
  pop.style.width = pw + 'px';
  if (!el) {
    spot.hidden = true;
    pop.className = 'tour-pop center';
    pop.style.left = (vw - pw) / 2 + 'px'; pop.style.top = Math.max(20, (vh - pop.offsetHeight) / 2) + 'px';
    return;
  }
  const r = el.getBoundingClientRect(), pad = 6;
  const R = {l: Math.max(4, r.left - pad), t: Math.max(4, r.top - pad), r: Math.min(vw - 4, r.right + pad), b: Math.min(vh - 4, r.bottom + pad)};
  spot.hidden = false;
  Object.assign(spot.style, {left: R.l + 'px', top: R.t + 'px', width: (R.r - R.l) + 'px', height: (R.b - R.t) + 'px'});
  const ph = pop.offsetHeight, gap = 14;
  const fits = {right: vw - R.r > pw + gap, left: R.l > pw + gap, bottom: vh - R.b > ph + gap, top: R.t > ph + gap};
  let place = st.place && fits[st.place] ? st.place : ['bottom', 'right', 'left', 'top'].find(p => fits[p]) || 'inside';
  let x, y;
  if (place === 'right') { x = R.r + gap; y = R.t; }
  if (place === 'left') { x = R.l - pw - gap; y = R.t; }
  if (place === 'bottom') { x = R.l; y = R.b + gap; }
  if (place === 'top') { x = R.l; y = R.t - ph - gap; }
  if (place === 'inside') { x = R.l + 16; y = R.t + 16; }
  x = Math.max(12, Math.min(vw - pw - 12, x)); y = Math.max(12, Math.min(vh - ph - 12, y));
  pop.className = 'tour-pop ' + place;
  pop.style.left = x + 'px'; pop.style.top = y + 'px';
}

const wait = ms => new Promise(r => setTimeout(r, ms));
async function next() {
  const st = tour.chapter.steps[tour.i];
  if (st.after) { try { await st.after(); } catch {} }
  if (tour.i >= tour.chapter.steps.length - 1) return end(true);
  tour.i++; show();
}
async function back() { if (tour.i > 0) { tour.i--; closeModals(); show(); } }
function end(completed) {
  if (!tour) return;
  const ch = tour.chapter;
  tour = null;
  closeModals();
  document.body.classList.remove('touring');
  ['#tourPop', '#tourSpot', '#tourBlock'].forEach(s => $(s).hidden = true);
  if (completed) {
    save({complete: ch.id});
    const nextCh = CHAPTERS.find(c => c.id !== ch.id && !state.done.includes(c.id));
    toast(`Chapter complete: <b>${esc(ch.title)}</b>${nextCh ? ` · Next up: ${esc(nextCh.title)}` : ' · You finished every chapter! 🎓'}`, 'ok');
    setTimeout(openHub, 700);
  }
}
document.addEventListener('keydown', e => {
  if (!tour) return;
  if (e.key === 'Escape') { e.preventDefault(); e.stopImmediatePropagation(); end(false); }
  if (e.key === 'ArrowRight' || (e.key === 'Enter' && !e.target.matches('input,textarea'))) { e.preventDefault(); e.stopImmediatePropagation(); next(); }
  if (e.key === 'ArrowLeft') { e.preventDefault(); e.stopImmediatePropagation(); back(); }
}, true);
addEventListener('resize', () => tour && position());
$('#tourBlock').addEventListener('click', () => { $('#tourPop').classList.add('shake'); setTimeout(() => $('#tourPop').classList.remove('shake'), 400); });

// ---------------------------------------------------------------- demo pack
async function loadDemo() {
  try {
    const r = await api('demo_load', {});
    toast(r.added ? `Demo knowledge loaded: ${r.added} documents. Mnemo is learning them now (watch the status bar).` : 'Demo knowledge is already loaded.', 'ok');
    A.loadTree(); A.refreshStatus(); setTimeout(() => A.kickProcessing(), 300);
    if ($('#tutHub').classList.contains('show')) openHub();
  } catch (e) { toast('Could not load the demo: ' + esc(e.message), 'bad'); }
}
async function removeDemo() {
  if (!confirm('Remove the demo documents, everything learned only from them, and the demo benchmark questions?')) return;
  try { const r = await api('demo_remove', {}); toast(`Demo removed (${r.removed} documents).`, 'ok'); A.refreshAll(); openHub(); }
  catch (e) { toast(esc(e.message), 'bad'); }
}

// ---------------------------------------------------------------- Learn hub
async function openHub(welcome = false) {
  const t = await loadState();
  if (!t) return;
  const done = new Set(state.done);
  const items = t.checklist, nDone = items.filter(i => i.done).length;
  const chDone = CHAPTERS.filter(c => done.has(c.id)).length;
  const pct = Math.round((nDone + chDone) / (items.length + CHAPTERS.length) * 100);
  const C = 2 * Math.PI * 26;
  $('#tutHubBody').innerHTML = `
    <div class="modal-h"><span style="display:flex;gap:8px;align-items:center">${icon('school')}Learn ATLAS</span><button class="btn ghost sm icon" data-close>${icon('x', 'sm')}</button></div>
    <div class="modal-b" style="gap:16px">
      ${welcome ? `<div class="tut-welcome"><div class="orb"><img src="assets/icon.svg" alt="Mnemo"></div><div><b>Welcome! I'm Mnemo.</b><br>I start with zero knowledge and learn from everything you teach me, offline, stored in your own MySQL database. Let me show you around.</div></div>` : ''}
      <div class="tut-top">
        <div class="tut-ring"><svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="26" stroke="var(--panel-3)"/><circle cx="32" cy="32" r="26" stroke="var(--accent)" stroke-dasharray="${C}" stroke-dashoffset="${C * (1 - pct / 100)}" transform="rotate(-90 32 32)"/></svg><b>${pct}%</b></div>
        <div style="flex:1"><div style="font-weight:600;font-size:15px">${pct === 100 ? 'You\'re an ATLAS expert 🎓' : pct === 0 ? 'Start here' : 'Keep going!'}</div>
          <div class="muted" style="font-size:12.5px">${chDone}/${CHAPTERS.length} chapters · ${nDone}/${items.length} hands-on tasks</div></div>
        <button class="btn primary" id="tutStart">${icon('play', 'sm')}${chDone ? 'Continue tour' : 'Start the tour'}</button>
      </div>
      <div class="tut-grid">
        <div>
          <div class="tut-h">Chapters</div>
          ${CHAPTERS.map((c, k) => `<div class="tut-ch ${done.has(c.id) ? 'done' : ''}" data-ch="${c.id}">
              <span class="tut-ic">${done.has(c.id) ? icon('check', 'sm') : icon(c.icon, 'sm')}</span>
              <div style="flex:1;min-width:0"><b>${k + 1}. ${esc(c.title)}</b><div class="muted">${esc(c.desc)}</div></div>
              <span class="muted" style="font-size:11px;white-space:nowrap">${c.time}</span></div>`).join('')}
        </div>
        <div>
          <div class="tut-h">Getting started checklist <span class="muted" style="font-weight:400;text-transform:none;letter-spacing:0">(ticks itself when you do it)</span></div>
          <div class="card" style="padding:6px 12px">${items.map(i => `<div class="tut-task ${i.done ? 'done' : ''}"><span class="tut-box">${i.done ? icon('check', 'sm') : ''}</span><span style="flex:1">${esc(i.title)}</span>
            ${i.done ? '' : `<button class="btn ghost sm" data-show="${i.chapter}">Show me</button>`}</div>`).join('')}</div>
          <div class="tut-h" style="margin-top:14px">Demo knowledge pack</div>
          <div class="card tut-demo">${icon('book', 'lg')}<div style="flex:1;font-size:12.5px"><b style="color:var(--text)">Astronomy sample: 4 documents</b><br>
            <span class="muted">Includes an outdated 1995 textbook, so you can practise resolving a real contradiction. ${t.demo.loaded ? `<b style="color:var(--ok)">Loaded (${t.demo.documents} docs)</b>` : ''}</span></div>
            ${t.demo.loaded ? `<button class="btn sm danger-outline" id="tutDemoRm">${icon('trash', 'sm')}Remove</button>` : `<button class="btn sm primary" id="tutDemo">${icon('download', 'sm')}Load</button>`}</div>
          <div class="tut-h" style="margin-top:14px">Keyboard shortcuts</div>
          <div class="card tut-keys">
            <span><kbd>Ctrl</kbd>+<kbd>K</kbd> search</span><span><kbd>U</kbd> upload</span><span><kbd>1</kbd>–<kbd>9</kbd> views</span><span><kbd>?</kbd> this hub</span>
            <span><kbd>Enter</kbd> send question</span><span><kbd>Shift</kbd>+<kbd>Enter</kbd> new line</span><span><kbd>Space</kbd> flip card</span><span><kbd>1</kbd>–<kbd>4</kbd> grade card</span>
          </div>
        </div>
      </div>
    </div>
    <div class="modal-f"><button class="btn ghost sm" id="tutReset">Reset progress</button><span style="flex:1"></span><a class="btn sm ghost" href="docs/plan.html" target="_blank">${icon('book', 'sm')}Project plan</a><button class="btn" data-close>Close</button></div>`;
  $('#tutHub').classList.add('show');
  const firstOpen = CHAPTERS.find(c => !done.has(c.id)) || CHAPTERS[0];
  $('#tutStart').onclick = () => start(firstOpen.id);
  $$('#tutHubBody [data-ch]').forEach(el => el.onclick = () => start(el.dataset.ch));
  $$('#tutHubBody [data-show]').forEach(el => el.onclick = () => start(el.dataset.show));
  if ($('#tutDemo')) $('#tutDemo').onclick = loadDemo;
  if ($('#tutDemoRm')) $('#tutDemoRm').onclick = removeDemo;
  $('#tutReset').onclick = async () => { if (confirm('Reset tutorial progress? (Your knowledge is not touched.)')) { await save({reset: true}); openHub(); } };
  $$('#tutHubBody [data-close]').forEach(b => b.onclick = () => $('#tutHub').classList.remove('show'));
  if (welcome) save({welcomed: true});
}
$('#tutHub').addEventListener('click', e => { if (e.target.id === 'tutHub') $('#tutHub').classList.remove('show'); });
$('#btnTutorial').onclick = () => openHub();

// "?" help button on every view header → that view's chapter
$$('.view').forEach(v => {
  const id = VIEW_CHAPTER[v.id.replace('view-', '')];
  const head = $('.view-head', v);
  if (!id) return;
  const b = document.createElement('button');
  b.className = 'btn ghost sm icon help-btn'; b.title = 'How does this screen work?'; b.innerHTML = icon('help', 'sm');
  b.onclick = () => start(id);
  if (head) head.appendChild(b);
  else { b.classList.add('glass'); v.querySelector('.graph-bar')?.appendChild(b); }
});

// first run: welcome
(async () => {
  const t = await loadState();
  updateBadge();
  let seen = false; try { seen = localStorage.getItem('atlas.welcomed') === '1'; } catch {}
  if (t && !t.state.welcomed && t.empty && !seen) { try { localStorage.setItem('atlas.welcomed', '1'); } catch {} setTimeout(() => openHub(true), 900); }
})();

window.Tutorial = {openHub, start, loadDemo, chapters: CHAPTERS};
})();
