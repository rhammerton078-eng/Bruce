/* ATLAS front-end — vanilla JS, no build step, fully offline. */
(() => {
'use strict';

// ---------------------------------------------------------------- helpers
const $ = (s, el = document) => el.querySelector(s);
const $$ = (s, el = document) => [...el.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const fmtNum = n => Number(n || 0).toLocaleString();
const fmtBytes = b => b >= 1073741824 ? (b / 1073741824).toFixed(1) + ' GB' : b >= 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
const ago = s => { if (!s) return ''; const d = (Date.now() - new Date(s.replace(' ', 'T')).getTime()) / 1000;
  if (d < 60) return 'just now'; if (d < 3600) return Math.floor(d / 60) + 'm ago'; if (d < 86400) return Math.floor(d / 3600) + 'h ago';
  if (d < 604800) return Math.floor(d / 86400) + 'd ago'; return s.slice(0, 10); };
const debounce = (fn, ms) => { let t; return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); }; };

const ICONS = {
  graph:'<circle cx="12" cy="12" r="2.5"/><circle cx="5" cy="6" r="1.8"/><circle cx="19" cy="7" r="1.8"/><circle cx="6" cy="18" r="1.8"/><circle cx="18" cy="18" r="1.8"/><path d="M6.5 7.2l3.6 3.3M17.4 7.9l-3.4 2.7M7.5 17l2.8-3.3M16.5 17l-2.8-3.3"/>',
  chat:'<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/><path d="M8.5 10.5h7M8.5 13.5h4.5"/>',
  files:'<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h4"/>',
  clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  pulse:'<path d="M3 12h4l2.5-6 4 12 2.5-6H21"/>',
  sidebar:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M9 4v16"/>',
  panel:'<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M15 4v16"/>',
  gear:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  search:'<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
  upload:'<path d="M12 16V4M7 9l5-5 5 5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/>',
  refresh:'<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
  folder:'<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
  folderPlus:'<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M12 11v5M9.5 13.5h5"/>',
  moon:'<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
  cube:'<path d="M12 2l9 5v10l-9 5-9-5V7z"/><path d="M12 22V12M21 7l-9 5-9-5"/>',
  filter:'<path d="M3 5h18l-7 8v6l-4 2v-8z"/>',
  route:'<circle cx="6" cy="19" r="2.5"/><circle cx="18" cy="5" r="2.5"/><path d="M8.5 19H17a3.5 3.5 0 0 0 0-7H7a3.5 3.5 0 0 1 0-7h8.5"/>',
  type:'<path d="M4 7V5h16v2M9 19h6M12 5v14"/>',
  fit:'<path d="M4 9V5a1 1 0 0 1 1-1h4M15 4h4a1 1 0 0 1 1 1v4M20 15v4a1 1 0 0 1-1 1h-4M9 20H5a1 1 0 0 1-1-1v-4"/>',
  x:'<path d="M18 6L6 18M6 6l12 12"/>',
  sparkle:'<path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z"/>',
  plus:'<path d="M12 5v14M5 12h14"/>',
  send:'<path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/>',
  play:'<path d="M7 4l13 8-13 8z"/>',
  back:'<path d="M15 18l-6-6 6-6"/>',
  chev:'<path d="M9 18l6-6-6-6"/>',
  chevDown:'<path d="M6 9l6 6 6-6"/>',
  user:'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  org:'<path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-4h6v4M9 10h.01M15 10h.01M9 14h.01M15 14h.01"/>',
  pin:'<path d="M12 21s-7-5.5-7-11a7 7 0 0 1 14 0c0 5.5-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
  bulb:'<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10.5c.8.8 1 1.5 1 2.5h6c0-1 .2-1.7 1-2.5A6 6 0 0 0 12 3z"/>',
  cpu:'<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 2v3M15 2v3M9 19v3M15 19v3M2 9h3M2 15h3M19 9h3M19 15h3"/>',
  calendar:'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
  book:'<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20V3H6.5A2.5 2.5 0 0 0 4 5.5z"/><path d="M4 19.5A2.5 2.5 0 0 0 6.5 22H20v-5"/>',
  box:'<path d="M21 8l-9-5-9 5 9 5z"/><path d="M3 8v8l9 5 9-5V8"/>',
  chart:'<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
  tag:'<path d="M20.6 13.4l-7.2 7.2a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
  image:'<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/>',
  doc:'<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
  trash:'<path d="M3 6h18M8 6V4h8v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
  retry:'<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>',
  thumbUp:'<path d="M7 10v11H4V10zM7 10l4-8a3 3 0 0 1 3 3v4h5.5a2 2 0 0 1 2 2.3l-1.3 8A2 2 0 0 1 18.2 21H7"/>',
  thumbDown:'<path d="M17 14V3h3v11zM17 14l-4 8a3 3 0 0 1-3-3v-4H4.5a2 2 0 0 1-2-2.3l1.3-8A2 2 0 0 1 5.8 3H17"/>',
  copy:'<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
  link:'<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
  alert:'<path d="M10.3 3.9L1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
  check:'<path d="M20 6L9 17l-5-5"/>',
  layers:'<path d="M12 2l10 5-10 5L2 7z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/>',
  database:'<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v6c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 11v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
  download:'<path d="M12 4v12M7 11l5 5 5-5"/><path d="M20 16v3a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-3"/>',
  hdd:'<rect x="3" y="13" width="18" height="7" rx="2"/><path d="M5 13l2.5-8h9L19 13M7 16.5h.01"/>',
  shield:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
  wrench:'<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2.4-.6-.6-2.4z"/>',
  scale:'<path d="M12 3v18M7 21h10M5 7h14M5 7l-3 7a3.5 3.5 0 0 0 6 0zM19 7l-3 7a3.5 3.5 0 0 0 6 0z"/>',
  cards:'<rect x="3" y="6" width="14" height="14" rx="2"/><path d="M7 6V5a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2h-2"/>',
  target:'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
  camera:'<path d="M3 8a2 2 0 0 1 2-2h2l2-2h6l2 2h2a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><circle cx="12" cy="13" r="3.5"/>',
  eye:'<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  merge:'<circle cx="6" cy="5" r="2"/><circle cx="6" cy="19" r="2"/><circle cx="18" cy="12" r="2"/><path d="M6 7v10M6 7c0 3 5 5 10 5"/>',
  undo:'<path d="M9 14L4 9l5-5"/><path d="M4 9h11a5 5 0 0 1 0 10h-3"/>',
  history:'<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
  school:'<path d="M22 9L12 4 2 9l10 5 10-5z"/><path d="M6 11v5c0 1.7 2.7 3 6 3s6-1.3 6-3v-5M22 9v6"/>',
  help:'<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 0 1 4.9.8c0 1.7-2.4 2.2-2.4 3.7M12 17h.01"/>',
  sdcard:'<path d="M7 3h8l4 4v12a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M9 3v4M12 3v4M15 4v3"/>',
  sprout:'<path d="M12 21v-9"/><path d="M12 12c0-4-3-6-7-6 0 4 3 6 7 6z"/><path d="M12 15c0-3.5 2.5-5.5 7-5.5 0 3.5-2.5 5.5-7 5.5z"/>',
  flame:'<path d="M12 22c4 0 7-3 7-7 0-3-2-5-3-7-1 2-2 3-3 3 0-3-1-6-4-9 0 4-4 7-4 13 0 4 3 7 7 7z"/>',
};
const TYPE_ICON = {person:'user', organization:'org', place:'pin', concept:'bulb', technology:'cpu', event:'calendar', work:'book', product:'box', metric:'chart', other:'tag'};
const icon = (n, cls = '') => `<svg viewBox="0 0 24 24" class="i ${cls}">${ICONS[n] || ICONS.tag}</svg>`;
const hydrateIcons = (root = document) => $$('i[data-icon]', root).forEach(el => { el.outerHTML = icon(el.dataset.icon, el.className); });

const PALETTE = ['#38bdf8','#a78bfa','#34d399','#f472b6','#fbbf24','#60a5fa','#f87171','#2dd4bf','#c084fc','#fb923c','#4ade80','#e879f9','#facc15','#22d3ee','#818cf8'];
const TYPE_COLOR = {person:'#f472b6', organization:'#a78bfa', place:'#34d399', concept:'#38bdf8', technology:'#22d3ee', event:'#fbbf24', work:'#fb923c', product:'#60a5fa', metric:'#4ade80', other:'#94a3b8'};

async function api(action, params = {}, method) {
  const isPost = method === 'POST' || (method === undefined && ['card_create','card_switch','card_rename','card_remove','card_export','curiosity_answer','curiosity_skip','wonder','lesson_teach','lesson_exam','lesson_delete','baby_settings','tutorial_save','demo_load','demo_remove','contradiction_resolve','review_decide','merge','merge_undo','hypothesis_decide','truth_scan','golden_add','golden_delete','golden_toggle','golden_run','self_quiz_run','quiz_promote','approved_delete','watch_add','watch_delete','watch_check','watch_read','card_review','cards_generate','card_delete','vector_native','backup_create','backup_delete','restore','wipe','entity_forget','reembed','rebuild_index','optimize','storage_settings_save','upload','process','teach','nightly','feedback','settings_save','document_delete','document_retry','collection_create','document_update','conversation_delete'].includes(action));
  let url = 'api/index.php?action=' + encodeURIComponent(action);
  const opt = {method: isPost ? 'POST' : 'GET', headers: {}};
  if (isPost) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(params); }
  else for (const [k, v] of Object.entries(params)) if (v !== undefined && v !== null && v !== '') url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(v);
  const r = await fetch(url, opt);
  let j; try { j = await r.json(); } catch { throw new Error('Server returned an invalid response (' + r.status + ')'); }
  if (!r.ok || j.error) throw new Error(j.error || ('HTTP ' + r.status));
  return j;
}

function toast(msg, kind = '') {
  const el = document.createElement('div');
  el.className = 'toast ' + kind;
  el.innerHTML = icon(kind === 'bad' ? 'alert' : kind === 'ok' ? 'check' : 'sparkle', 'sm') + '<div>' + msg + '</div>';
  $('#toasts').appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = '.3s'; setTimeout(() => el.remove(), 300); }, kind === 'bad' ? 7000 : 3800);
}

// ---------------------------------------------------------------- state
const S = {
  view: 'graph', graph: null, mode: '2d', colorBy: 'cluster', labels: true, data: {nodes: [], links: []},
  hiddenGroups: new Set(), hover: null, highlight: new Set(), selectedId: null,
  inspStack: [], status: null, processing: false, conv: 0, collections: [], tree: null,
};

// ---------------------------------------------------------------- views
function setView(v) {
  S.view = v;
  $$('[data-view]').forEach(b => b.classList.toggle('active', b.dataset.view === v));
  $$('.view').forEach(el => el.classList.toggle('active', el.id === 'view-' + v));
  if (v === 'graph') { requestAnimationFrame(resizeGraph); if (!S.graph) loadGraph(); }
  if (v === 'documents') loadDocuments();
  if (v === 'timeline') loadTimeline();
  if (v === 'dashboard') loadDashboard();
  if (v === 'memory') loadMemory();
  if (v === 'review') loadReview();
  if (v === 'grow') loadGrow();
  if (v === 'practice') loadPractice();
  if (v === 'quality') loadQuality();
  if (v === 'ask') { loadConversations(); setTimeout(() => $('#chatInput').focus(), 50); }
  try { localStorage.setItem('atlas.view', v); } catch {}
}

// ---------------------------------------------------------------- explorer tree
async function loadTree() {
  let t;
  try { t = await api('tree'); } catch (e) { $('#tree').innerHTML = `<div class="empty">${esc(e.message)}</div>`; return; }
  S.tree = t; S.collections = t.collections;
  const closed = JSON.parse(localStorage.getItem('atlas.closed') || '[]');
  const sec = (id, title, count, body) => `<div class="sec ${closed.includes(id) ? 'closed' : ''}" data-sec="${id}">
      <div class="sec-h">${icon('chevDown', 'sm chev')}${title}<span class="count">${count}</span></div><div class="sec-b">${body}</div></div>`;

  const docsBy = {};
  t.documents.forEach(d => (docsBy[d.collection_id || 0] ??= []).push(d));
  const docNode = d => `<div class="node" data-doc="${d.id}"><span class="tw"></span>${icon(d.kind === 'image' ? 'image' : 'doc', 'sm')}<span class="lbl">${esc(d.title)}</span>${d.status !== 'ready' ? `<span class="dot ${d.status === 'failed' ? 'bad' : 'warn pulse'}"></span>` : ''}</div>`;
  let colHtml = t.collections.map(c => `<div class="node" data-toggle><span class="tw">${icon('chev', 'sm')}</span><span class="sw" style="background:${esc(c.color)}"></span><span class="lbl">${esc(c.name)}</span><span class="meta">${c.n}</span></div>
      <div class="children hidden">${(docsBy[c.id] || []).map(docNode).join('') || '<div class="empty" style="padding:6px">Empty</div>'}</div>`).join('');
  if (docsBy[0]?.length) colHtml += `<div class="node" data-toggle><span class="tw">${icon('chev', 'sm')}</span>${icon('folder', 'sm')}<span class="lbl">Unsorted</span><span class="meta">${docsBy[0].length}</span></div><div class="children hidden">${docsBy[0].map(docNode).join('')}</div>`;

  const typeHtml = Object.entries(t.types).map(([type, g]) => `<div class="node" data-toggle><span class="tw">${icon('chev', 'sm')}</span><span style="color:${TYPE_COLOR[type] || '#94a3b8'}">${icon(TYPE_ICON[type] || 'tag', 'sm')}</span><span class="lbl" style="text-transform:capitalize">${esc(type)}</span><span class="meta">${g.n}</span></div>
      <div class="children hidden">${g.items.map(e => `<div class="node" data-ent="${e.id}"><span class="tw"></span><span class="lbl">${esc(e.name)}</span><span class="meta">${e.mention_count}</span></div>`).join('')}</div>`).join('');

  const clusterHtml = t.clusters.map(c => `<div class="node" data-cluster="${c.id}"><span class="tw"></span><span class="sw" style="background:${PALETTE[(c.id - 1) % PALETTE.length]};border-radius:50%"></span><span class="lbl">${esc(c.label.split('|').join(' · '))}</span><span class="meta">${c.n}</span></div>`).join('');

  $('#tree').innerHTML =
    sec('col', 'Collections', t.collections.length, colHtml || '<div class="empty">No collections</div>') +
    sec('topics', 'Topic clusters', t.clusters.length, clusterHtml || '<div class="empty">Run a sleep cycle to discover topics</div>') +
    sec('types', 'Entity types', Object.keys(t.types).length, typeHtml || '<div class="empty">No entities yet</div>');

  // selects that depend on collections
  const opts = t.collections.map(c => `<option value="${c.id}">${esc(c.name)}</option>`).join('');
  $('#uploadCollection').innerHTML = opts;
  $('#chatCollection').innerHTML = '<option value="">All collections</option>' + opts;
  const types = Object.keys(t.types);
  $('#gfType').innerHTML = '<option value="">All types</option>' + types.map(x => `<option value="${x}">${x[0].toUpperCase() + x.slice(1)}</option>`).join('');
}

$('#tree').addEventListener('click', e => {
  const h = e.target.closest('.sec-h');
  if (h) {
    const s = h.parentElement; s.classList.toggle('closed');
    const closed = $$('.sec.closed').map(x => x.dataset.sec);
    try { localStorage.setItem('atlas.closed', JSON.stringify(closed)); } catch {}
    return;
  }
  const n = e.target.closest('.node'); if (!n) return;
  if (n.hasAttribute('data-toggle')) { n.classList.toggle('open'); n.nextElementSibling?.classList.toggle('hidden'); return; }
  $$('#tree .node.active').forEach(x => x.classList.remove('active')); n.classList.add('active');
  if (n.dataset.ent) { openEntity(+n.dataset.ent, true); }
  if (n.dataset.doc) openDocument(+n.dataset.doc);
  if (n.dataset.cluster) focusCluster(+n.dataset.cluster);
});

// ---------------------------------------------------------------- graph
function nodeColor(n) {
  if (S.colorBy === 'type') return TYPE_COLOR[n.type] || '#94a3b8';
  return n.cluster ? PALETTE[(n.cluster - 1) % PALETTE.length] : '#38bdf8';
}
const groupOf = n => S.colorBy === 'type' ? n.type : (n.cluster ? 'c' + n.cluster : 'none');
const nodeSize = n => 3 + Math.sqrt((n.mentions || 0) + (n.degree || 0) * 1.5) * 1.3;

async function loadGraph() {
  try {
    const d = await api('graph', {limit: $('#gfLimit').value, type: $('#gfType').value, min_conf: $('#gfConf').value});
    S.data = d;
    $('#graphEmpty').hidden = d.nodes.length > 0;
    const ids = new Map(d.nodes.map(n => [n.id, n]));
    d.nodes.forEach(n => { n.neighbors = new Set(); });
    d.links.forEach(l => { ids.get(l.source)?.neighbors.add(l.target); ids.get(l.target)?.neighbors.add(l.source); });
    $('#entityList').innerHTML = d.nodes.slice(0, 1500).map(n => `<option value="${esc(n.name)}">`).join('');
    renderGraph();
    renderLegend();
  } catch (e) { toast('Graph: ' + esc(e.message), 'bad'); }
}

function visibleData() {
  if (!S.hiddenGroups.size) return S.data;
  const nodes = S.data.nodes.filter(n => !S.hiddenGroups.has(groupOf(n)));
  const keep = new Set(nodes.map(n => n.id));
  const id = x => typeof x === 'object' ? x.id : x;
  return {nodes, links: S.data.links.filter(l => keep.has(id(l.source)) && keep.has(id(l.target)))};
}

function renderGraph() {
  const el = $('#graph');
  const data = visibleData();
  $('#graphStats').textContent = `${fmtNum(data.nodes.length)} nodes · ${fmtNum(data.links.length)} links`;
  if (S.graph && S.graph._mode === S.mode) { S.graph.graphData(data); return; }
  if (S.graph) { S.graph._destructor?.(); el.innerHTML = ''; }
  const w = el.clientWidth, h = el.clientHeight;
  const isLinked = l => S.highlight.size && (S.highlight.has(typeof l.source === 'object' ? l.source.id : l.source) && S.highlight.has(typeof l.target === 'object' ? l.target.id : l.target));

  if (S.mode === '2d') {
    const g = ForceGraph()(el).width(w).height(h).backgroundColor('rgba(0,0,0,0)')
      .nodeId('id').nodeVal(n => nodeSize(n)).nodeLabel(() => '')
      .linkColor(l => isLinked(l) ? 'rgba(125,211,252,.9)' : l.origin === 'inferred' ? 'rgba(167,139,250,.18)' : 'rgba(56,189,248,.16)')
      .linkWidth(l => isLinked(l) ? 1.6 : Math.min(2.2, .4 + (l.weight || 1) * .25))
      .linkLineDash(l => l.origin === 'inferred' ? [2, 3] : null)
      .linkDirectionalParticles(l => isLinked(l) ? 2 : 0).linkDirectionalParticleWidth(2).linkDirectionalParticleColor(() => '#7dd3fc')
      .nodeCanvasObject((n, ctx, scale) => {
        if (!Number.isFinite(n.x) || !Number.isFinite(n.y)) return;
        const r = nodeSize(n), c = nodeColor(n);
        const dim = S.highlight.size && !S.highlight.has(n.id);
        ctx.globalAlpha = dim ? .15 : 1;
        const grd = ctx.createRadialGradient(n.x, n.y, 0, n.x, n.y, r * 3);
        grd.addColorStop(0, c + '55'); grd.addColorStop(1, c + '00');
        ctx.fillStyle = grd; ctx.beginPath(); ctx.arc(n.x, n.y, r * 3, 0, 2 * Math.PI); ctx.fill();
        ctx.fillStyle = c; ctx.beginPath(); ctx.arc(n.x, n.y, r, 0, 2 * Math.PI); ctx.fill();
        ctx.fillStyle = 'rgba(255,255,255,.85)'; ctx.beginPath(); ctx.arc(n.x, n.y, r * .38, 0, 2 * Math.PI); ctx.fill();
        if (n.id === S.selectedId) { ctx.strokeStyle = '#fff'; ctx.lineWidth = 1.5 / scale; ctx.beginPath(); ctx.arc(n.x, n.y, r + 3 / scale, 0, 2 * Math.PI); ctx.stroke(); }
        const show = S.labels && (scale > 1.6 || r > 7 || S.data.nodes.length <= 120 || S.highlight.has(n.id) || n.id === S.selectedId);
        if (show && !dim) {
          const fs = Math.max(10 / scale, 2.4);
          ctx.font = `${n.id === S.selectedId ? 600 : 500} ${fs}px Inter, Segoe UI, sans-serif`;
          ctx.textAlign = 'center'; ctx.textBaseline = 'top';
          ctx.fillStyle = 'rgba(7,9,13,.75)';
          const tw = ctx.measureText(n.name).width;
          ctx.fillRect(n.x - tw / 2 - 2 / scale, n.y + r + 2 / scale, tw + 4 / scale, fs + 2 / scale);
          ctx.fillStyle = S.highlight.has(n.id) || n.id === S.selectedId ? '#fff' : '#b9c4d6';
          ctx.fillText(n.name, n.x, n.y + r + 3 / scale);
        }
        ctx.globalAlpha = 1;
      })
      .nodePointerAreaPaint((n, color, ctx) => { ctx.fillStyle = color; ctx.beginPath(); ctx.arc(n.x, n.y, nodeSize(n) + 2, 0, 2 * Math.PI); ctx.fill(); })
      .onNodeHover(n => onHover(n)).onNodeClick(n => { openEntity(n.id); openPreview(n.id); })
      .onBackgroundClick(() => { S.selectedId = null; S.highlight.clear(); refreshGraphStyle(); showOverview(); })
      .cooldownTicks(220).d3VelocityDecay(.3);
    g.d3Force('charge').strength(-70).distanceMax(420);
    g.d3Force('link').distance(l => l.origin === 'inferred' ? 70 : 42);
    g._mode = '2d';
    g.onEngineStop(() => { if (!g._fitted) { g.zoomToFit(600, 60); g._fitted = true; } });
    S.graph = g;
  } else {
    const g = ForceGraph3D({controlType: 'orbit'})(el).width(w).height(h).backgroundColor('rgba(0,0,0,0)').showNavInfo(false)
      .nodeId('id').nodeVal(n => nodeSize(n) * 1.6).nodeColor(n => S.highlight.size && !S.highlight.has(n.id) ? 'rgba(80,90,110,.25)' : nodeColor(n))
      .nodeOpacity(.95).nodeResolution(14).nodeLabel(n => `<div class="glass" style="padding:5px 8px;font:12px Inter,sans-serif;color:#e5ecf5">${esc(n.name)} <span style="color:#7c8aa3">· ${esc(n.type)}</span></div>`)
      .linkColor(l => isLinked(l) ? '#7dd3fc' : l.origin === 'inferred' ? '#6d5bd0' : '#2b6c8f').linkOpacity(.35)
      .linkWidth(l => isLinked(l) ? 1.2 : 0).linkDirectionalParticles(l => isLinked(l) ? 3 : 0).linkDirectionalParticleWidth(1.6)
      .onNodeHover(n => { el.style.cursor = n ? 'pointer' : ''; onHover(n, true); })
      .onNodeClick(n => { openEntity(n.id); openPreview(n.id); const d = 90, r = 1 + d / Math.hypot(n.x, n.y, n.z || 1); g.cameraPosition({x: n.x * r, y: n.y * r, z: n.z * r}, n, 900); })
      .onBackgroundClick(() => { S.selectedId = null; S.highlight.clear(); refreshGraphStyle(); showOverview(); });
    g._mode = '3d';
    g.onEngineStop(() => { if (!g._fitted) { g.zoomToFit(600, 40); g._fitted = true; } });
    S.graph = g;
  }
  S.graph.graphData(data);
}

function refreshGraphStyle() {
  if (!S.graph) return;
  if (S.mode === '3d') { S.graph.nodeColor(S.graph.nodeColor()).linkWidth(S.graph.linkWidth()).linkColor(S.graph.linkColor()).linkDirectionalParticles(S.graph.linkDirectionalParticles()); }
  else { S.graph.linkDirectionalParticles(S.graph.linkDirectionalParticles()); }
}

function setHighlight(id) {
  S.highlight.clear();
  const n = S.data.nodes.find(x => x.id === id);
  if (n) { S.highlight.add(id); n.neighbors.forEach(x => S.highlight.add(x)); }
}

function onHover(n, is3d) {
  const tip = $('#graphTip');
  if (!is3d) {
    if (n) {
      tip.innerHTML = `<b>${esc(n.name)}</b> <span class="muted">· ${esc(n.type)}</span><br><span class="muted">${n.mentions} mentions · ${n.degree} links</span>`;
      tip.style.display = 'block';
    } else tip.style.display = 'none';
    $('#graph').style.cursor = n ? 'pointer' : '';
  }
  if (S.selectedId) return;
  if (n) setHighlight(n.id); else S.highlight.clear();
  refreshGraphStyle();
}
$('#graph').addEventListener('mousemove', e => { const t = $('#graphTip'); const r = $('#graph').getBoundingClientRect(); t.style.left = (e.clientX - r.left + 14) + 'px'; t.style.top = (e.clientY - r.top + 14) + 'px'; });

function focusNode(id) {
  const n = S.data.nodes.find(x => x.id === id);
  S.selectedId = id; setHighlight(id); refreshGraphStyle();
  if (!n || !S.graph || n.x === undefined) return;
  if (S.mode === '2d') { S.graph.centerAt(n.x, n.y, 700); S.graph.zoom(Math.max(S.graph.zoom(), 2.4), 700); }
  else { const d = 110, r = 1 + d / Math.hypot(n.x, n.y, n.z || 1); S.graph.cameraPosition({x: n.x * r, y: n.y * r, z: n.z * r}, n, 900); }
}

function focusCluster(cid) {
  if (S.view !== 'graph') setView('graph');
  S.colorBy = 'cluster'; $$('#graphColor button').forEach(b => b.classList.toggle('on', b.dataset.color === 'cluster'));
  S.selectedId = null; S.highlight = new Set(S.data.nodes.filter(n => n.cluster === cid).map(n => n.id)); refreshGraphStyle();
  const nodes = S.data.nodes.filter(n => n.cluster === cid);
  if (S.mode === '2d' && nodes.length) S.graph.zoomToFit(700, 80, n => n.cluster === cid);
  renderLegend();
}

function renderLegend() {
  const counts = {};
  S.data.nodes.forEach(n => { const g = groupOf(n); counts[g] = (counts[g] || 0) + 1; });
  const entries = Object.entries(counts).sort((a, b) => b[1] - a[1]).slice(0, 14);
  const label = g => {
    if (S.colorBy === 'type') return g[0].toUpperCase() + g.slice(1);
    if (g === 'none') return 'Unclustered';
    const cid = +g.slice(1); const c = S.tree?.clusters.find(x => +x.id === cid);
    return c ? c.label.split('|').slice(0, 2).join(' · ') : 'Topic ' + cid;
  };
  const color = g => S.colorBy === 'type' ? (TYPE_COLOR[g] || '#94a3b8') : g === 'none' ? '#38bdf8' : PALETTE[(+g.slice(1) - 1) % PALETTE.length];
  $('#legend').innerHTML = entries.length ? `<b style="color:var(--text);font-size:11px;letter-spacing:.6px;text-transform:uppercase">${S.colorBy === 'type' ? 'Types' : 'Topics'}</b>` +
    entries.map(([g, n]) => `<div class="row ${S.hiddenGroups.has(g) ? 'off' : ''}" data-g="${esc(g)}"><span class="dot" style="background:${color(g)}"></span><span style="flex:1;max-width:190px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(label(g))}</span><span class="muted">${n}</span></div>`).join('') : '';
  $('#legend').style.display = entries.length ? '' : 'none';
}
$('#legend').addEventListener('click', e => {
  const r = e.target.closest('.row'); if (!r) return;
  const g = r.dataset.g; S.hiddenGroups.has(g) ? S.hiddenGroups.delete(g) : S.hiddenGroups.add(g);
  renderLegend(); renderGraph();
});

function resizeGraph() { if (S.graph) { const el = $('#graph'); S.graph.width(el.clientWidth).height(el.clientHeight); } }
window.addEventListener('resize', debounce(resizeGraph, 120));

$('#graphMode').addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; S.mode = b.dataset.mode; $$('#graphMode button').forEach(x => x.classList.toggle('on', x === b)); renderGraph(); });
$('#graphColor').addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; S.colorBy = b.dataset.color; S.hiddenGroups.clear(); $$('#graphColor button').forEach(x => x.classList.toggle('on', x === b)); renderLegend(); renderGraph(); refreshGraphStyle(); if (S.mode === '3d') S.graph.nodeColor(S.graph.nodeColor()); });
$('#gfType').addEventListener('change', () => { S.graph && (S.graph._fitted = false); loadGraph(); });
$('#gfLimit').addEventListener('change', loadGraph);
$('#gfConf').addEventListener('input', e => { $('#gfConfV').textContent = Math.round(e.target.value * 100) + '%'; });
$('#gfConf').addEventListener('change', loadGraph);
$('#btnFit').onclick = () => S.graph?.zoomToFit(600, 60);
$('#btnLabels').onclick = () => { S.labels = !S.labels; $('#btnLabels').classList.toggle('on', S.labels); };
$('#btnReloadGraph').onclick = () => { if (S.graph) S.graph._fitted = false; loadGraph(); };

// path finder
$('#btnPath').onclick = () => $('#pathPanel').classList.toggle('show');
$('#pathClose').onclick = () => $('#pathPanel').classList.remove('show');
$('#pathGo').onclick = async () => {
  const find = v => S.data.nodes.find(n => n.name.toLowerCase() === v.trim().toLowerCase());
  const a = find($('#pathFrom').value), b = find($('#pathTo').value);
  if (!a || !b) { $('#pathResult').innerHTML = '<span class="muted">Pick two concepts from the suggestions.</span>'; return; }
  const {steps} = await api('path', {from: a.id, to: b.id});
  if (!steps.length) { $('#pathResult').innerHTML = '<span class="muted">No connection found within 5 hops (yet!).</span>'; return; }
  S.selectedId = null; S.highlight = new Set([a.id, ...steps.map(s => s.to)]); refreshGraphStyle();
  $('#pathResult').innerHTML = `<div class="muted" style="margin-bottom:6px">${steps.length} hop${steps.length > 1 ? 's' : ''}</div>` +
    `<div class="rel" data-e="${a.id}"><span class="on"><b>${esc(a.name)}</b></span></div>` +
    steps.map(s => `<div style="padding-left:12px" class="rt muted">↓ ${esc(s.type.replace(/_/g, ' '))}</div><div class="rel" data-e="${s.to}"><span class="on"><b>${esc(s.to_name)}</b></span></div>`).join('');
  $$('#pathResult .rel').forEach(r => r.onclick = () => openEntity(+r.dataset.e));
};

// ---------------------------------------------------------------- inspector
function setInsp(title, html, push = true) {
  if (push && $('#insp').innerHTML && $('#inspTitle').textContent !== title) S.inspStack.push({title: $('#inspTitle').textContent, html: $('#insp').innerHTML});
  if (S.inspStack.length > 20) S.inspStack.shift();
  $('#inspTitle').textContent = title; $('#insp').innerHTML = html; $('#insp').scrollTop = 0;
  $('#inspBack').hidden = !S.inspStack.length;
  bindInsp();
}
$('#inspBack').onclick = () => { const p = S.inspStack.pop(); if (p) { $('#inspTitle').textContent = p.title; $('#insp').innerHTML = p.html; bindInsp(); } $('#inspBack').hidden = !S.inspStack.length; };

function bindInsp() {
  $$('#insp [data-e]').forEach(el => el.onclick = () => openEntity(+el.dataset.e, true));
  $$('#insp [data-d]').forEach(el => el.onclick = () => openDocument(+el.dataset.d));
  $$('#insp [data-c]').forEach(el => el.onclick = () => openChunk(+el.dataset.c));
  $$('#insp [data-action]').forEach(bindAction);
}

async function openEntity(id, focus = false) {
  if (!S._flaggedEntity) { S._flaggedEntity = true; api('tutorial_save', {flag: 'opened_entity'}).catch(() => {}); }
  if (document.body.clientWidth > 1050) $('#app').classList.remove('no-insp');
  S.selectedId = id;
  if (S.view === 'graph') { if (focus) focusNode(id); else { setHighlight(id); refreshGraphStyle(); } }
  let e; try { e = await api('entity', {id}); } catch (err) { toast(esc(err.message), 'bad'); return; }
  const tc = TYPE_COLOR[e.type] || '#94a3b8';
  const rels = e.relations.map(r => `<div class="rel" data-e="${r.other_id}" title="${esc(r.evidence || '')}">
      <span class="rt">${r.direction === 'out' ? '→' : '←'} ${esc(r.type.replace(/_/g, ' '))}</span>
      <span class="on">${esc(r.other_name)}</span>
      ${r.origin === 'inferred' ? '<span class="badge violet">inferred</span>' : r.origin === 'user' ? '<span class="badge ok">taught</span>' : r.weight > 1 ? `<span class="badge">×${r.weight}</span>` : ''}</div>`).join('');
  setInsp('Entity', `
    <div class="card">
      <div style="display:flex;gap:6px;align-items:center"><span class="badge" style="color:${tc};border-color:${tc}55;background:${tc}14">${icon(TYPE_ICON[e.type] || 'tag', 'sm')}${esc(e.type)}</span>
        ${e.cluster_id ? `<span class="badge"><span class="dot" style="background:${PALETTE[(e.cluster_id - 1) % PALETTE.length]}"></span>Topic ${e.cluster_id}</span>` : ''}</div>
      <div class="ent-title">${esc(e.name)}</div>
      <div style="color:var(--text-2);font-size:13px">${esc(e.description || 'No description yet. Upload more material about this to teach Mnemo.')}</div>
      ${e.aliases.length ? `<div class="chips" style="margin-top:10px">${e.aliases.map(a => `<span class="badge">${esc(a)}</span>`).join('')}</div>` : ''}
      <div style="margin-top:12px;display:flex;justify-content:space-between;font-size:11.5px;color:var(--text-3)"><span>Confidence</span><span>${Math.round(e.confidence * 100)}%</span></div>
      <div class="meter" style="margin-top:4px"><i style="width:${Math.round(e.confidence * 100)}%"></i></div>
      <dl class="kv" style="margin-top:12px"><dt>Mentions</dt><dd>${e.mention_count}</dd><dt>Connections</dt><dd>${e.relations.length}</dd><dt>First learned</dt><dd>${esc(e.first_seen.slice(0, 16))}</dd></dl>
      <div style="display:flex;gap:6px;margin-top:12px">
        <button class="btn sm" data-action="ask-about" data-name="${esc(e.name)}">${icon('chat', 'sm')}Ask about this</button>
        <button class="btn sm" data-action="focus" data-id="${e.id}">${icon('fit', 'sm')}Show in graph</button>
        <button class="btn sm icon" data-action="merge-entity" data-id="${e.id}" data-name="${esc(e.name)}" title="Merge into another concept (duplicate)">${icon('merge', 'sm')}</button>
        <button class="btn sm icon danger" data-action="forget-entity" data-id="${e.id}" title="Forget this concept">${icon('trash', 'sm')}</button>
      </div>
    </div>
    <div class="card"><h4>Relationships <span>${e.relations.length}</span></h4>${rels || '<div class="empty">No relationships yet</div>'}</div>
    ${e.claims?.length ? `<div class="card"><h4>Facts (claims) <span>${e.claims.length}</span></h4>${e.claims.map(c => `<div class="claim ${c.status}" ${c.chunk_id ? `data-c="${c.chunk_id}" style="cursor:pointer"` : ''}>
        ${c.status === 'disputed' ? `<span class="badge warn">disputed</span>` : c.status === 'rejected' ? '<span class="badge bad">rejected</span>' : c.status === 'superseded' ? '<span class="badge">outdated</span>' : c.origin === 'user' ? '<span class="badge ok">taught</span>' : c.support > 0 ? `<span class="badge accent">✓${c.support}</span>` : ''}
        <span style="flex:1">${esc(c.statement)}</span></div>`).join('')}</div>` : ''}
    <div class="card"><h4>Evidence</h4>${e.snippets.map(s => `<div class="snip" data-c="${s.chunk_id}">${esc(s.text)}…<span class="src">${icon('doc', 'sm')} ${esc(s.title)}${s.page ? ' · p.' + s.page : ''}</span></div>`).join('') || '<div class="empty">—</div>'}</div>
    <div class="card"><h4>Sources <span>${e.sources.length}</span></h4>${e.sources.map(s => `<div class="list-row" data-d="${s.document_id}">${icon(s.kind === 'image' ? 'image' : 'doc', 'sm')}<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(s.title)}</span><span class="muted">${s.mentions}×</span></div>`).join('')}</div>
    ${e.merges?.length ? `<div class="card"><h4>Merged into this</h4>${e.merges.map(m => `<div class="list-row" style="cursor:default">${icon('merge', 'sm')}<span style="flex:1">${esc(m.merged_name)}</span>${m.undone_at ? '<span class="muted">undone</span>' : `<button class="btn ghost sm" data-action="undo-merge" data-id="${m.id}">${icon('undo', 'sm')}Undo</button>`}</div>`).join('')}</div>` : ''}
    ${e.history?.length ? `<div class="card"><h4>History</h4>${e.history.map(h => `<div class="hist"><b>${esc(h.field)}</b> · ${esc(h.created_at.slice(0, 16))}${h.reason ? ' · ' + esc(h.reason) : ''}${h.old_value ? `<div class="muted" style="text-decoration:line-through">${esc(h.old_value.slice(0, 160))}</div>` : ''}${h.new_value ? `<div>${esc(h.new_value.slice(0, 160))}</div>` : ''}</div>`).join('')}</div>` : ''}`);
}

async function openDocument(id) {
  $('#app').classList.remove('no-insp');
  let d; try { d = await api('document', {id}); } catch (err) { toast(esc(err.message), 'bad'); return; }
  const status = statusBadge(d.status);
  setInsp('Source', `
    <div class="card">
      <div style="display:flex;gap:6px">${status}<span class="badge">${icon(d.kind === 'image' ? 'image' : 'doc', 'sm')}${esc((d.filename.split('.').pop() || '').toUpperCase())}</span></div>
      <div class="ent-title">${esc(d.title)}</div>
      ${d.kind === 'image' && d.stored_path ? `<img src="api/index.php?action=file&id=${d.id}" style="width:100%;border-radius:8px;margin:6px 0;border:1px solid var(--line)" alt="">` : ''}
      <div style="color:var(--text-2);font-size:13px">${esc(d.summary || (d.status === 'ready' ? 'No summary.' : 'Mnemo is still reading this source…'))}</div>
      ${d.error ? `<div class="badge bad" style="margin-top:8px;height:auto;padding:4px 8px;white-space:normal">${esc(d.error)}</div>` : ''}
      <dl class="kv" style="margin-top:12px"><dt>Collection</dt><dd>${esc(d.collection || '—')}</dd><dt>Pages</dt><dd>${d.page_count}</dd><dt>Size</dt><dd>${fmtBytes(d.size_bytes)}</dd><dt>Added</dt><dd>${esc(d.created_at.slice(0, 16))}</dd></dl>
      <div style="margin-top:12px;font-size:12px;color:var(--text-3);display:flex;justify-content:space-between"><span>Source trust</span><span id="trustV">${d.trust}</span></div>
      <input type="range" min="0" max="100" value="${d.trust}" style="width:100%" id="trustRange" data-id="${d.id}">
      <div style="display:flex;gap:6px;margin-top:10px;flex-wrap:wrap">
        ${d.stored_path ? `<a class="btn sm" href="api/index.php?action=file&id=${d.id}" target="_blank">${icon('link', 'sm')}Open file</a>` : ''}
        <button class="btn sm" data-action="graph-doc" data-id="${d.id}">${icon('graph', 'sm')}Graph</button>
        ${d.status === 'failed' ? `<button class="btn sm" data-action="retry" data-id="${d.id}">${icon('retry', 'sm')}Retry</button>` : ''}
        <button class="btn sm danger" data-action="delete-doc" data-id="${d.id}">${icon('trash', 'sm')}Forget</button>
      </div>
    </div>
    ${d.disputed ? `<div class="alert warn" style="cursor:pointer" data-action="go-review">${icon('scale', 'sm')}<div>${d.disputed} of ${d.claims} facts from this source are disputed or rejected. Open Review.</div></div>` : ''}
    ${d.related_images?.length ? `<div class="card"><h4>Related images</h4><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">${d.related_images.map(r => `<div data-d="${r.id}" style="cursor:pointer" title="${esc(r.title)} · ${Math.round(r.score * 100)}% (${r.method})"><img src="api/index.php?action=file&id=${r.id}" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px;border:1px solid var(--line)" alt=""></div>`).join('')}</div></div>` : ''}
    <div class="card"><h4>Concepts found <span>${d.entities.length}</span></h4><div class="chips">${d.entities.map(e => `<span class="badge" style="cursor:pointer" data-e="${e.id}"><span class="dot" style="background:${TYPE_COLOR[e.type] || '#94a3b8'}"></span>${esc(e.name)}</span>`).join('') || '<span class="muted">None yet</span>'}</div></div>
    <div class="card"><h4>Passages <span>${d.chunks.length}</span></h4>${d.chunks.slice(0, 40).map(c => `<div class="snip" data-c="${c.id}">${esc(c.content.slice(0, 220))}…<span class="src">#${c.seq + 1}${c.page ? ' · p.' + c.page : ''}${c.heading ? ' · ' + esc(c.heading.slice(0, 50)) : ''}</span></div>`).join('')}</div>`);
  const tr = $('#trustRange');
  if (tr) { tr.oninput = () => $('#trustV').textContent = tr.value; tr.onchange = () => api('document_update', {id: +tr.dataset.id, trust: +tr.value}).then(() => toast('Trust updated', 'ok')); }
}

async function openChunk(id, highlightText) {
  $('#app').classList.remove('no-insp');
  let c; try { c = await api('chunk', {id}); } catch (err) { toast(esc(err.message), 'bad'); return; }
  setInsp('Passage', `<div class="card"><div style="display:flex;gap:6px;margin-bottom:6px"><span class="badge accent">${icon('doc', 'sm')}${c.page ? 'Page ' + c.page : 'Passage'}</span></div>
    <div class="list-row" data-d="${c.document_id}" style="font-weight:600;color:var(--text)">${esc(c.title)}</div>
    ${c.heading ? `<div class="muted" style="margin:6px 0">${esc(c.heading)}</div>` : ''}
    <div style="white-space:pre-wrap;font-size:13px;color:var(--text-2);margin-top:8px;line-height:1.6">${esc(c.content)}</div></div>`);
}

function statusBadge(s) {
  return {ready: '<span class="badge ok">Learned</span>', processing: '<span class="badge warn"><span class="dot warn pulse"></span>Learning</span>',
    queued: '<span class="badge">Queued</span>', failed: '<span class="badge bad">Failed</span>'}[s] || esc(s);
}

async function showOverview() {
  if (!S.status) return;
  const c = S.status.counts;
  let dash = null; try { dash = await api('dashboard'); } catch {}
  setInsp('Overview', `
    <div class="card"><h4>Mnemo's memory</h4>
      <div class="kpis" style="grid-template-columns:1fr 1fr;gap:8px">
        ${[['Sources', c.documents, 'files'], ['Concepts', c.entities, 'bulb'], ['Links', c.relations, 'link'], ['Topics', c.clusters, 'layers']].map(([l, v, i]) =>
          `<div class="kpi" style="padding:10px"><div class="l">${icon(i, 'sm')}${l}</div><div class="v" style="font-size:20px">${fmtNum(v)}</div></div>`).join('')}
      </div>
      ${dash ? `<div style="margin-top:10px;display:flex;align-items:center;gap:8px;font-size:12px;color:var(--text-2)">${icon('flame', 'sm')} Learning streak: <b style="color:var(--text)">${dash.streak} day${dash.streak === 1 ? '' : 's'}</b></div>` : ''}
    </div>
    ${dash?.top?.length ? `<div class="card"><h4>Most connected</h4>${dash.top.slice(0, 8).map(e => `<div class="rel" data-e="${e.id}"><span style="color:${TYPE_COLOR[e.type]}">${icon(TYPE_ICON[e.type] || 'tag', 'sm')}</span><span class="on">${esc(e.name)}</span><span class="muted">${e.degree}</span></div>`).join('')}</div>` : ''}
    ${dash?.gaps?.length ? `<div class="card"><h4>Knowledge gaps</h4><div class="muted" style="font-size:12px;margin-bottom:6px">Mentioned often but poorly explained. Upload material about these:</div>${dash.gaps.map(g => `<div class="rel" data-e="${g.id}">${icon('alert', 'sm')}<span class="on">${esc(g.name)}</span><span class="muted">${g.degree} links</span></div>`).join('')}</div>` : ''}
    <div class="card"><h4>Tips</h4><div style="font-size:12.5px;color:var(--text-2);line-height:1.7">
      • Click a node to inspect it, hover to see its neighbours.<br>• Use <b>Path finder</b> to discover how two ideas connect.<br>
      • Teach directly in chat with <code>remember:</code>, <code>define:</code>, <code>correct:</code>.<br>• Run a <b>Sleep cycle</b> daily to find new links and topics.</div></div>`, false);
  S.inspStack = []; $('#inspBack').hidden = true;
}

// ---------------------------------------------------------------- actions
function bindAction(el) {
  el.onclick = async () => {
    const a = el.dataset.action, id = +el.dataset.id;
    if (a === 'upload') openUpload();
    if (a === 'sleep') runSleep();
    if (a === 'focus') { setView('graph'); setTimeout(() => focusNode(id), 80); }
    if (a === 'ask-about') { setView('ask'); $('#chatInput').value = `What do my sources say about ${el.dataset.name}?`; autoGrow(); $('#chatInput').focus(); }
    if (a === 'graph-doc') { setView('graph'); const d = await api('graph', {document: id}); S.selectedId = null; S.highlight = new Set(d.nodes.map(n => n.id)); refreshGraphStyle(); if (S.mode === '2d') S.graph.zoomToFit(700, 80, n => S.highlight.has(n.id)); }
    if (a === 'forget-entity') {
      if (!confirm('Forget this concept and all its links? Sources stay; it may be re-learned if you re-process them.')) return;
      await api('entity_forget', {id}); toast('Concept forgotten', 'ok'); S.selectedId = null; refreshAll(); showOverview();
    }
    if (a === 'go-review') setView('review');
    if (a === 'merge-entity') openMerge(id, el.dataset.name);
    if (a === 'undo-merge') { try { await api('merge_undo', {id}); toast('Merge undone', 'ok'); refreshAll(); openEntity(S.selectedId); } catch (e) { toast(esc(e.message), 'bad'); } }
    if (a === 'retry') { await api('document_retry', {id}); toast('Queued again'); kickProcessing(); openDocument(id); }
    if (a === 'delete-doc') {
      if (!confirm('Forget this source? Its passages and any concepts only it mentioned will be removed. This cannot be undone.')) return;
      await api('document_delete', {id}); toast('Source forgotten', 'ok'); refreshAll(); showOverview();
    }
  };
}
$$('[data-action]').forEach(bindAction);

async function runSleep() {
  const btns = $$('[data-action="sleep"], #btnSleep'); btns.forEach(b => b.disabled = true);
  setWork('Sleep cycle: consolidating memory…', true);
  try {
    const r = await api('nightly', {});
    toast(`Sleep cycle done: ${r.inferred_links} new inferred links, ${r.clusters} topic clusters${r.log.some(l => l.includes('skipped')) ? ' (similarity skipped: is Ollama running?)' : ''}`, 'ok');
    refreshAll();
  } catch (e) { toast('Sleep cycle failed: ' + esc(e.message), 'bad'); }
  btns.forEach(b => b.disabled = false); setWork();
}
$('#btnSleep').onclick = runSleep;

// ---------------------------------------------------------------- chat
function md(text, sources) {
  let h = esc(text);
  h = h.replace(/```([\s\S]*?)```/g, (_, c) => `<pre><code>${c.replace(/^\w*\n/, '')}</code></pre>`);
  h = h.replace(/`([^`\n]+)`/g, '<code>$1</code>');
  h = h.replace(/\*\*([^*\n]+)\*\*/g, '<b>$1</b>').replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<i>$2</i>');
  h = h.replace(/^#{1,4}\s+(.+)$/gm, '<h4>$1</h4>');
  const lines = h.split('\n'); let out = '', list = null;
  for (const ln of lines) {
    const ul = ln.match(/^\s*[-*•]\s+(.*)/), ol = ln.match(/^\s*\d+[.)]\s+(.*)/);
    if (ul || ol) { const t = ul ? 'ul' : 'ol'; if (list !== t) { if (list) out += `</${list}>`; out += `<${t}>`; list = t; } out += `<li>${(ul || ol)[1]}</li>`; continue; }
    if (list) { out += `</${list}>`; list = null; }
    out += ln.trim() === '' ? '\n' : (ln.startsWith('<h4>') || ln.startsWith('<pre>') ? ln : ln + '<br>');
  }
  if (list) out += `</${list}>`;
  out = out.split(/\n{1,}/).map(p => p.trim()).filter(Boolean).map(p => /^<(ul|ol|h4|pre)/.test(p) ? p : `<p>${p.replace(/<br>$/, '')}</p>`).join('');
  const valid = new Set((sources || []).map(s => s.n));
  return out.replace(/\[(\d{1,2})\]/g, (m, n) => valid.has(+n) ? `<span class="cite" data-n="${n}">${n}</span>` : m);
}

function chatEmpty() {
  $('#chatInner').innerHTML = `<div class="chat-empty">
    <div class="orb"><img src="assets/icon.svg" alt="Mnemo"></div><h3>Hi, I'm ${esc(aiName())}</h3><div class="who" ${aiName() === 'Mnemo' ? 'hidden' : ''}>powered by Mnemo</div>
    <div>I answer only from what you've taught me, and I always show my sources.</div>
    <div class="suggest">
      <div class="card" data-q="Give me an overview of everything I've uploaded so far."><b>Overview</b>Summarize my knowledge base</div>
      <div class="card" data-q="What are the most important concepts in my sources and how do they relate?"><b>Key concepts</b>Main ideas and relationships</div>
      <div class="card" data-q="Do any of my sources contradict each other? Show both sides."><b>Contradictions</b>Find disagreements between sources</div>
      <div class="card" data-q="remember: "><b>Teach me</b>Start with remember: to store a fact</div>
    </div></div>`;
  $$('#chatInner .suggest .card').forEach(c => c.onclick = () => { $('#chatInput').value = c.dataset.q; autoGrow(); if (!c.dataset.q.endsWith(': ')) sendChat(); else $('#chatInput').focus(); });
}

function renderMsg(role, content, sources = [], id = null, feedback = null) {
  const el = document.createElement('div');
  el.className = 'msg ' + role;
  el.innerHTML = `<div class="av">${role === 'user' ? 'You'.slice(0, 1) : 'M'}</div><div class="bubble"><div class="who">${role === 'user' ? 'You' : 'Mnemo'}</div>
    <div class="content">${role === 'user' ? esc(content).replace(/\n/g, '<br>') : md(content, sources)}</div><div class="srcs"></div><div class="acts"></div></div>`;
  $('#chatInner').appendChild(el);
  if (role === 'assistant') { renderSources(el, sources); renderActs(el, id, feedback, content); }
  return el;
}

function renderSources(el, sources) {
  if (!sources?.length) { $('.srcs', el).innerHTML = ''; return; }
  $('.srcs', el).innerHTML = `<div class="sources">${sources.map(s => `<div class="src-card" data-c="${s.chunk_id}" data-n="${s.n}">
      <div class="t"><span class="cite">${s.n}</span>${icon(s.kind === 'image' ? 'image' : 'doc', 'sm')}<span>${esc(s.title)}</span></div>
      <div class="s">${s.page ? 'p.' + s.page + ' · ' : ''}${esc(s.snippet)}</div></div>`).join('')}</div>`;
  el._sources = sources;
  $$('.src-card', el).forEach(c => c.onclick = () => openChunk(+c.dataset.c));
  $$('.content .cite', el).forEach(c => c.onclick = () => { const s = sources.find(x => x.n === +c.dataset.n); if (s) openChunk(s.chunk_id); });
}

function renderActs(el, id, feedback, content) {
  if (!id) { $('.acts', el).innerHTML = ''; return; }
  $('.acts', el).innerHTML = `<div class="msg-actions">
    <button class="btn ghost sm icon ${feedback == 1 ? 'on' : ''}" data-fb="1" title="Good answer">${icon('thumbUp', 'sm')}</button>
    <button class="btn ghost sm icon ${feedback == -1 ? 'on' : ''}" data-fb="-1" title="Bad answer">${icon('thumbDown', 'sm')}</button>
    <button class="btn ghost sm icon" data-copy title="Copy">${icon('copy', 'sm')}</button>
    <button class="btn ghost sm" data-watch title="Re-answer this question automatically when new knowledge arrives">${icon('eye', 'sm')}Watch</button></div>`;
  $$('[data-fb]', el).forEach(b => b.onclick = async () => { await api('feedback', {id, value: +b.dataset.fb}); $$('[data-fb]', el).forEach(x => x.classList.toggle('on', x === b)); toast(b.dataset.fb === '1' ? 'Thanks! Mnemo will remember this was helpful.' : 'Noted. Try correct: to fix a fact.'); });
  $('[data-copy]', el).onclick = () => { navigator.clipboard?.writeText(content); toast('Copied'); };
  $('[data-watch]', el).onclick = async () => {
    let q = el.previousElementSibling?.classList.contains('user') ? $('.content', el.previousElementSibling).textContent : '';
    q = prompt('Watch this question (Mnemo re-answers it when knowledge changes):', q); if (!q) return;
    await api('watch_add', {question: q}); toast('Added to your watchlist', 'ok');
  };
}

const scrollChat = () => { const s = $('#chatScroll'); s.scrollTop = s.scrollHeight; };
function autoGrow() { const t = $('#chatInput'); t.style.height = 'auto'; t.style.height = Math.min(200, t.scrollHeight) + 'px'; }
$('#chatInput').addEventListener('input', autoGrow);
$('#chatInput').addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendChat(); } });
$('#chatSend').onclick = sendChat;
$$('.hint-chip').forEach(c => c.onclick = () => { $('#chatInput').value = c.dataset.ins; $('#chatInput').focus(); autoGrow(); });

let chatBusy = false;
async function sendChat() {
  const q = $('#chatInput').value.trim();
  if (!q || chatBusy) return;
  chatBusy = true; $('#chatSend').disabled = true;
  if ($('.chat-empty')) $('#chatInner').innerHTML = '';
  renderMsg('user', q);
  $('#chatInput').value = ''; autoGrow();
  const el = renderMsg('assistant', '');
  $('.content', el).innerHTML = '<div class="typing"><span></span><span></span><span></span></div>';
  scrollChat();
  let text = '', sources = [], msgId = null;
  try {
    const r = await fetch('api/index.php?action=ask', {method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({q, conversation_id: S.conv || 0, as_of: $('#chatAsOf').value, collection: $('#chatCollection').value})});
    if (!r.ok && r.headers.get('content-type')?.includes('json')) throw new Error((await r.json()).error);
    const reader = r.body.getReader(); const dec = new TextDecoder(); let buf = '';
    const render = () => { $('.content', el).innerHTML = md(text, sources) || '<div class="typing"><span></span><span></span><span></span></div>'; scrollChat(); };
    const throttled = debounce(render, 30);
    for (;;) {
      const {value, done} = await reader.read(); if (done) break;
      buf += dec.decode(value, {stream: true});
      let i;
      while ((i = buf.indexOf('\n')) >= 0) {
        const line = buf.slice(0, i).trim(); buf = buf.slice(i + 1);
        if (!line) continue;
        let ev; try { ev = JSON.parse(line); } catch { continue; }
        if (ev.type === 'meta') { if (!S.conv) { S.conv = ev.conversation_id; loadConversations(); } }
        if (ev.type === 'sources') { sources = ev.sources; renderSources(el, sources);
          const flags = (ev.approved ? `<span class="ans-flag ok">${icon('thumbUp', 'sm')}Reusing an answer you approved (${Math.round(ev.approved.similarity * 100)}% match)</span>` : '') +
            (ev.disputed ? `<span class="ans-flag warn">${icon('scale', 'sm')}${ev.disputed} disputed fact(s) in these sources; Mnemo was told not to trust them</span>` : '');
          if (flags) $('.who', el).insertAdjacentHTML('afterend', `<div>${flags}</div>`); }
        if (ev.type === 'token') { text += ev.t; throttled(); }
        if (ev.type === 'done') msgId = ev.message_id;
        if (ev.error) { text += '\n\n⚠️ ' + ev.error; }
      }
    }
    render(); renderSources(el, sources); renderActs(el, msgId, null, text);
    if (/^\s*(remember|define|correct)\s*:/i.test(q)) { refreshStatus(); setTimeout(() => { loadTree(); if (S.graph) loadGraph(); }, 300); }
  } catch (e) {
    $('.content', el).innerHTML = `<span style="color:var(--bad)">⚠️ ${esc(e.message)}</span>`;
  }
  chatBusy = false; $('#chatSend').disabled = false; $('#chatInput').focus();
}

async function loadConversations() {
  try {
    const list = await api('conversations');
    $('#convSelect').innerHTML = '<option value="">New conversation</option>' + list.map(c => `<option value="${c.id}" ${c.id == S.conv ? 'selected' : ''}>${esc(c.title.slice(0, 60))}</option>`).join('');
  } catch {}
  if (!S.conv && !$('#chatInner').children.length) chatEmpty();
}
$('#convSelect').onchange = async () => {
  S.conv = +$('#convSelect').value || 0;
  if (!S.conv) return chatEmpty();
  const msgs = await api('conversation', {id: S.conv});
  $('#chatInner').innerHTML = '';
  msgs.forEach(m => renderMsg(m.role, m.content, m.sources ? JSON.parse(m.sources) : [], m.id, m.feedback));
  scrollChat();
};
$('#btnNewChat').onclick = () => { S.conv = 0; $('#convSelect').value = ''; chatEmpty(); $('#chatInput').focus(); };

// ---------------------------------------------------------------- documents
let docsCache = [];
async function loadDocuments() {
  try { docsCache = await api('documents'); } catch (e) { toast(esc(e.message), 'bad'); return; }
  renderDocs();
}
function renderDocs() {
  const f = $('#docFilter').value.toLowerCase(), st = $('#docStatus').value;
  const rows = docsCache.filter(d => (!f || d.title.toLowerCase().includes(f) || d.filename.toLowerCase().includes(f)) && (!st || d.status === st));
  $('#docTable tbody').innerHTML = rows.map(d => {
    const pct = d.chunks ? Math.round(d.extracted / d.chunks * 100) : 0;
    return `<tr data-id="${d.id}">
      <td><div class="ttl" data-open="${d.id}">${icon(d.kind === 'image' ? 'image' : 'doc')}<span>${esc(d.title)}</span></div><div class="muted" style="font-size:11.5px;margin-left:24px">${esc(d.filename)}${d.size_bytes ? ' · ' + fmtBytes(d.size_bytes) : ''}</div></td>
      <td><select class="select" style="height:26px;font-size:12px" data-coll="${d.id}"><option value="0">—</option>${S.collections.map(c => `<option value="${c.id}" ${c.id == d.collection_id ? 'selected' : ''}>${esc(c.name)}</option>`).join('')}</select></td>
      <td>${statusBadge(d.status)}${d.status === 'processing' ? `<div class="progress" style="margin-top:5px;width:90px"><i style="width:${pct}%"></i></div>` : ''}${d.error ? `<div class="muted" style="font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(d.error)}">${esc(d.error)}</div>` : ''}</td>
      <td class="muted">${d.chunks}</td><td>${d.entities}</td>
      <td><div class="meter" style="width:60px" title="${d.trust}"><i style="width:${d.trust}%"></i></div></td>
      <td class="muted" title="${esc(d.created_at)}">${ago(d.created_at)}</td>
      <td style="white-space:nowrap">${d.status === 'failed' ? `<button class="btn ghost sm icon" data-retry="${d.id}" title="Retry">${icon('retry', 'sm')}</button>` : ''}<button class="btn ghost sm icon danger" data-del="${d.id}" title="Forget">${icon('trash', 'sm')}</button></td></tr>`;
  }).join('') || `<tr><td colspan="8"><div class="empty" style="padding:50px">No sources yet. <a href="#" data-action="upload">Upload some documents</a> to begin.</div></td></tr>`;
  $$('#docTable [data-action]').forEach(bindAction);
}
$('#docTable').addEventListener('click', async e => {
  const t = e.target.closest('[data-open],[data-retry],[data-del]'); if (!t) return;
  if (t.dataset.open) { $$('#docTable tr').forEach(r => r.classList.toggle('sel', r.dataset.id === t.dataset.open)); openDocument(+t.dataset.open); }
  if (t.dataset.retry) { await api('document_retry', {id: +t.dataset.retry}); kickProcessing(); loadDocuments(); }
  if (t.dataset.del && confirm('Forget this source? This cannot be undone.')) { await api('document_delete', {id: +t.dataset.del}); toast('Source forgotten', 'ok'); refreshAll(); }
});
$('#docTable').addEventListener('change', async e => { const s = e.target.closest('[data-coll]'); if (s) { await api('document_update', {id: +s.dataset.coll, collection_id: +s.value}); loadTree(); toast('Moved', 'ok'); } });
$('#docFilter').addEventListener('input', renderDocs);
$('#docStatus').addEventListener('change', renderDocs);
$('#btnProcess').onclick = () => kickProcessing(true);

// ---------------------------------------------------------------- timeline
const EV = {
  document_added: ['upload', 'Added source'], document_ready: ['check', 'Finished learning'], document_failed: ['alert', 'Could not read'],
  entity_created: ['bulb', 'Learned concept'], user_taught: ['sparkle', 'You taught'], nightly_run: ['moon', 'Sleep cycle'], document_deleted: ['trash', 'Forgot'], backup_created: ['download', 'Backup saved'], backup_restored: ['retry', 'Memory restored from backup'],
  memory_wiped: ['trash', 'Memory deleted:'], entity_forgotten: ['trash', 'Forgot concept'], reembed_started: ['refresh', 'Re-embedding started'], search_index_rebuilt: ['wrench', 'Search index rebuilt'], level_up: ['sprout', 'Mnemo grew:'], lesson_taught: ['school', 'Lesson taught:'], lesson_exam: ['target', 'Exam:'], demo_loaded: ['book', 'Demo pack loaded:'], demo_removed: ['trash', 'Demo pack removed:'],
  contradiction_found: ['scale', 'Contradiction found:'], contradiction_resolved: ['check', 'Contradiction resolved'], entity_merged: ['merge', 'Merged duplicate'], merge_undone: ['undo', 'Merge undone'],
  hypothesis_accepted: ['link', 'New connection accepted'], golden_run: ['target', 'Benchmark:'], self_quiz: ['sparkle', 'Self-quiz:'], standing_updated: ['eye', 'Watchlist answer changed:'], answer_approved: ['thumbUp', 'Approved answer:'],
};
async function loadTimeline() {
  let t; try { t = await api('timeline', {limit: 400}); } catch (e) { toast(esc(e.message), 'bad'); return; }
  if (!t.events.length) { $('#timeline').innerHTML = '<div class="empty" style="padding:60px">Nothing learned yet. Mnemo\'s history starts with your first upload.</div>'; return; }
  const byDay = {};
  t.events.forEach(e => (byDay[e.created_at.slice(0, 10)] ??= []).push(e));
  const stats = Object.fromEntries(t.days.map(d => [d.day, d]));
  $('#timeline').innerHTML = Object.entries(byDay).map(([day, evs]) => {
    const st = stats[day];
    // collapse runs of entity_created
    const items = []; let run = [];
    const flush = () => { if (run.length > 3) items.push({event: 'entity_run', list: run}); else items.push(...run); run = []; };
    evs.forEach(e => { if (e.event === 'entity_created') run.push(e); else { flush(); items.push(e); } }); flush();
    return `<div class="tl-day"><h3>${icon('calendar', 'sm')}${new Date(day + 'T00:00').toLocaleDateString(undefined, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'})}
        ${st ? `<span class="badge">${st.documents} sources</span><span class="badge accent">${st.entities} concepts</span><span class="badge violet">${st.relations} links</span>` : ''}</h3>
      <div class="card" style="padding:6px 12px">${items.map(e => {
        if (e.event === 'entity_run') return `<div class="tl-item"><span class="ic">${icon('bulb', 'sm')}</span><div class="tx">Learned ${e.list.length} concepts: ${e.list.slice(0, 12).map(x => `<b data-e="${x.entity_id}">${esc(x.detail)}</b>`).join(', ')}${e.list.length > 12 ? ` and ${e.list.length - 12} more` : ''}</div><span class="tm">${e.list[0].created_at.slice(11, 16)}</span></div>`;
        const [ic, lbl] = EV[e.event] || ['tag', e.event];
        const what = e.event === 'entity_created' ? `<b data-e="${e.entity_id}">${esc(e.detail)}</b>` : e.document_id && e.doc_title ? `<b data-d="${e.document_id}">${esc(e.doc_title)}</b>` : esc(e.detail || '');
        return `<div class="tl-item"><span class="ic">${icon(ic, 'sm')}</span><div class="tx">${lbl} ${what}</div><span class="tm">${e.created_at.slice(11, 16)}</span></div>`;
      }).join('')}</div></div>`;
  }).join('');
  $$('#timeline [data-e]').forEach(b => b.onclick = () => openEntity(+b.dataset.e));
  $$('#timeline [data-d]').forEach(b => b.onclick = () => openDocument(+b.dataset.d));
}

// ---------------------------------------------------------------- dashboard
async function loadDashboard() {
  let d; try { d = await api('dashboard'); } catch (e) { toast(esc(e.message), 'bad'); return; }
  const c = S.status?.counts || {};
  const days = [];
  for (let i = 29; i >= 0; i--) { const dt = new Date(Date.now() - i * 864e5); days.push(dt.toISOString().slice(0, 10)); }
  const g = Object.fromEntries(d.growth.map(x => [x.day, x]));
  const max = Math.max(1, ...days.map(k => (+g[k]?.ents || 0) + (+g[k]?.rels || 0)));
  const bars = days.map(k => { const e = +g[k]?.ents || 0, r = +g[k]?.rels || 0;
    return `<div class="b" data-tip="${k}: ${e} concepts, ${r} links"><i style="height:${e / max * 130}px;background:#38bdf8"></i><i style="height:${r / max * 130}px;background:#a78bfa"></i></div>`; }).join('');
  const tmax = Math.max(1, ...d.types.map(t => +t.n));
  const dg = d.digest;
  $('#dashboard').innerHTML = `
    <div class="kpis">
      ${[['Sources', c.documents, 'files'], ['Images', c.images, 'image'], ['Passages', c.chunks, 'layers'], ['Concepts', c.entities, 'bulb'], ['Relationships', c.relations, 'link'], ['Inferred links', c.inferred, 'sparkle'], ['Topics', c.clusters, 'graph'], ['Learning streak', d.streak + 'd', 'flame']]
        .map(([l, v, i]) => `<div class="kpi"><div class="l">${icon(i, 'sm')}${l}</div><div class="v">${typeof v === 'number' ? fmtNum(v) : v}</div></div>`).join('')}
    </div>
    <div class="grid2">
      <div class="card"><h4>Brain growth · last 30 days <span><span class="dot" style="display:inline-block;background:#38bdf8"></span> concepts &nbsp;<span class="dot" style="display:inline-block;background:#a78bfa"></span> links</span></h4><div class="bars">${bars}</div></div>
      <div class="card"><h4>Latest digest ${dg ? `<span>${esc(dg.day)}</span>` : ''}</h4>${dg ? `<dl class="kv"><dt>New sources</dt><dd>${dg.documents}</dd><dt>New concepts</dt><dd>${dg.entities}</dd><dt>New relationships</dt><dd>${dg.relations}</dd><dt>Inferred links</dt><dd>${dg.inferred_links}</dd><dt>Topic clusters</dt><dd>${dg.clusters}</dd></dl>${dg.notes ? `<div class="snip" style="cursor:default;margin-top:12px">${esc(dg.notes)}</div>` : ''}` : '<div class="empty">Run a sleep cycle to create today\'s digest.</div>'}
        <button class="btn sm" style="margin-top:10px" data-action="sleep">${icon('moon', 'sm')}Run sleep cycle</button></div>
    </div>
    <div class="grid2b">
      <div class="card"><h4>Concept types</h4>${d.types.map(t => `<div class="hbar"><span class="n" style="text-transform:capitalize">${esc(t.type)}</span><span class="t"><i style="width:${t.n / tmax * 100}%;background:${TYPE_COLOR[t.type] || '#94a3b8'}"></i></span><span class="c">${t.n}</span></div>`).join('') || '<div class="empty">—</div>'}</div>
      <div class="card"><h4>Most connected concepts</h4>${d.top.map(e => `<div class="list-row" data-e="${e.id}"><span style="color:${TYPE_COLOR[e.type]}">${icon(TYPE_ICON[e.type] || 'tag', 'sm')}</span><span style="flex:1">${esc(e.name)}</span><span class="muted">${e.degree} links · ${e.mention_count}×</span></div>`).join('') || '<div class="empty">—</div>'}</div>
    </div>
    <div class="grid2b">
      <div class="card"><h4>Knowledge gaps · what to upload next</h4>${d.gaps.map(g => `<div class="list-row" data-e="${g.id}">${icon('alert', 'sm')}<span style="flex:1">${esc(g.name)}</span><span class="muted">${g.degree} links, no explanation</span></div>`).join('') || '<div class="empty">No gaps detected yet</div>'}</div>
      <div class="card"><h4>How Mnemo gets smarter</h4><div style="font-size:12.5px;color:var(--text-2);line-height:1.75">
        <b style="color:var(--text)">1. Read</b>: every upload is parsed, chunked and embedded.<br><b style="color:var(--text)">2. Understand</b>: concepts and relationships are extracted into the graph.<br>
        <b style="color:var(--text)">3. Connect</b>: the sleep cycle infers new links and discovers topics.<br><b style="color:var(--text)">4. Learn from you</b>: corrections, definitions and feedback carry the highest trust.</div></div>
    </div>`;
  $$('#dashboard [data-e]').forEach(b => b.onclick = () => openEntity(+b.dataset.e));
  $$('#dashboard [data-action]').forEach(bindAction);
}

// ---------------------------------------------------------------- upload
function openUpload() { $('#uploadModal').classList.add('show'); }
$('#btnUpload').onclick = openUpload;
$$('.modal-bg').forEach(m => m.addEventListener('click', e => { if (e.target === m || e.target.closest('[data-close]')) m.classList.remove('show'); }));
$('#dropZone').onclick = () => $('#fileInput').click();
$('#fileInput').onchange = e => { uploadFiles([...e.target.files]); e.target.value = ''; };
['dragenter', 'dragover'].forEach(ev => $('#dropZone').addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); $('#dropZone').classList.add('over'); }));
['dragleave', 'drop'].forEach(ev => $('#dropZone').addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); $('#dropZone').classList.remove('over'); }));
$('#dropZone').addEventListener('drop', e => uploadFiles([...e.dataTransfer.files]));
let dragDepth = 0;
window.addEventListener('dragenter', e => { if (e.dataTransfer?.types?.includes('Files') && !$('#uploadModal').classList.contains('show')) { dragDepth++; $('#globalDrop').classList.add('show'); } });
window.addEventListener('dragleave', () => { if (--dragDepth <= 0) { dragDepth = 0; $('#globalDrop').classList.remove('show'); } });
window.addEventListener('dragover', e => e.preventDefault());
window.addEventListener('drop', e => { e.preventDefault(); dragDepth = 0; $('#globalDrop').classList.remove('show'); if (e.dataTransfer?.files?.length && !e.target.closest('#dropZone')) { openUpload(); uploadFiles([...e.dataTransfer.files]); } });

function uploadFiles(files) {
  if (!files.length) return;
  const st = S.status?.storage;
  if (st?.level === 'critical' && st.warnings.some(w => /disk/i.test(w.message))) { toast(esc(st.warnings[0].message), 'bad'); return; }
  const lim = st?.upload_limit || 0;
  const tooBig = lim ? files.filter(f => f.size > lim) : [];
  if (tooBig.length) toast(`${tooBig.map(f => esc(f.name)).join(', ')} ${tooBig.length > 1 ? 'are' : 'is'} larger than the ${fmtBytes(lim)} upload limit. Raise it in php.ini (see Memory → Limits).`, 'bad');
  files = files.filter(f => !lim || f.size <= lim);
  const totalSize = files.reduce((a, f) => a + f.size, 0);
  if (lim && totalSize > lim && files.length > 1) { files.forEach(f => uploadFiles([f])); return; }
  if (!files.length) return;
  const list = $('#uploadList');
  const fd = new FormData();
  files.forEach(f => fd.append('files[]', f));
  fd.append('collection_id', $('#uploadCollection').value || '');
  const rows = files.map(f => { const r = document.createElement('div'); r.className = 'upl-row'; r.innerHTML = `${icon('doc', 'sm')}<span class="n">${esc(f.name)}</span><span class="muted">${fmtBytes(f.size)}</span><span class="st"><div class="progress" style="width:70px"><i></i></div></span>`; list.prepend(r); return r; });
  const xhr = new XMLHttpRequest();
  xhr.open('POST', 'api/index.php?action=upload');
  xhr.upload.onprogress = e => { if (e.lengthComputable) rows.forEach(r => { const i = $('.progress i', r); if (i) i.style.width = (e.loaded / e.total * 100) + '%'; }); };
  xhr.onload = () => {
    let j; try { j = JSON.parse(xhr.responseText); } catch { j = {error: 'Upload failed (' + xhr.status + '). Check upload_max_filesize in php.ini.'}; }
    if (j.error) { rows.forEach(r => $('.st', r).innerHTML = `<span class="badge bad">Error</span>`); toast(esc(j.error), 'bad'); return; }
    j.results.forEach((res, i) => { $('.st', rows[i]).innerHTML = res.error ? `<span class="badge bad" title="${esc(res.error)}">Error</span>` : res.duplicate ? '<span class="badge">Already known</span>' : '<span class="badge ok">Queued</span>'; if (res.error) toast(esc(res.name + ': ' + res.error), 'bad'); });
    const n = j.results.filter(r => !r.error && !r.duplicate).length;
    if (n) { toast(`${n} source${n > 1 ? 's' : ''} queued. Mnemo is learning…`, 'ok'); kickProcessing(); }
    loadTree(); if (S.view === 'documents') loadDocuments();
  };
  xhr.onerror = () => toast('Upload failed: network error', 'bad');
  xhr.send(fd);
}

// ---------------------------------------------------------------- background processing
function setWork(text, busy) {
  $('#sbWork').innerHTML = `<span class="dot ${busy ? 'warn pulse' : ''}"></span><span>${esc(text || 'Idle')}</span>`;
}
// Percent bar: learning = passages read in the files being learned; fact-checking = claims checked
const prog = {phase: '', t0: 0, left0: 0};
function renderProgress(p, note) {
  p = p || {};
  const learnLeft = (p.chunks_left || 0) + (p.embed_left || 0);
  let phase = '', pct = 100, left = 0, label = '';
  if (learnLeft || p.queued) {
    phase = 'learn'; left = learnLeft;
    const total = (p.chunks_total || 0) + (p.embed_left && !p.chunks_left ? p.embed_left : 0);
    pct = total ? Math.floor((1 - Math.min(learnLeft, total) / total) * 100) : 0;
    label = `Learning… ${learnLeft} passage${learnLeft === 1 ? '' : 's'} left${p.queued ? ` · ${p.queued} file${p.queued === 1 ? '' : 's'} waiting` : ''}`;
  } else if (p.claims_left) {
    phase = 'check'; left = p.claims_left;
    pct = p.claims_total ? Math.floor((1 - p.claims_left / p.claims_total) * 100) : 0;
    label = `Fact-checking… ${p.claims_left} claim${p.claims_left === 1 ? '' : 's'} left`;
  }
  const wrap = $('#sbProgWrap');
  if (!phase) { prog.phase = ''; wrap.hidden = true; setWork(); return; }
  // Time estimate from how fast "left" goes down
  const now = Date.now();
  if (prog.phase !== phase || left > prog.left0) { prog.phase = phase; prog.t0 = now; prog.left0 = left; }
  let eta = '';
  const done = prog.left0 - left, secs = (now - prog.t0) / 1000;
  if (done > 0 && secs > 20) {
    const mins = Math.round(left / (done / secs) / 60);
    eta = mins < 1 ? '· under a minute' : mins < 90 ? `· about ${mins} min left` : `· about ${Math.round(mins / 60)} h left`;
  }
  wrap.hidden = false;
  $('#sbProg').style.width = Math.max(2, Math.min(100, pct)) + '%';
  $('#sbPct').textContent = Math.min(99, pct) + '%';
  $('#sbEta').textContent = eta;
  setWork(note ? `${label} (${note})` : label, true);
}
async function kickProcessing(manual) {
  if (S.processing) return;
  S.processing = true;
  let completedAny = false, lastGraphRefresh = Date.now();
  try {
    for (;;) {
      renderProgress(S.status?.pending);
      const r = await api('process', {budget: 15});
      S.status && (S.status.pending = r.pending);
      r.errors.forEach(e => toast(esc(e), 'bad'));
      if (r.completed.length) { completedAny = true; toast('Learned: ' + r.completed.map(esc).join(', '), 'ok'); loadTree(); }
      const total = (r.pending.chunks_left || 0) + (r.pending.queued || 0) + (r.pending.embed_left || 0) + (r.pending.claims_left || 0);
      renderProgress(r.pending, r.busy ? 'background worker' : '');
      if (Date.now() - lastGraphRefresh > 20000 && S.view === 'graph') { loadGraph(); lastGraphRefresh = Date.now(); }
      if (S.view === 'documents') loadDocuments();
      if (!total) break;
      if (r.errors.some(e => /unreachable/i.test(e))) { toast('Mnemo can\'t reach Ollama. Start Ollama, then click "Process queue".', 'bad'); break; }
      if (r.busy) { await new Promise(res => setTimeout(res, 8000)); continue; }
      if (!r.extracted_chunks && !r.parsed.length && !r.completed.length && !r.embedded && !r.checked_claims) break;
    }
  } catch (e) { toast('Processing stopped: ' + esc(e.message), 'bad'); }
  S.processing = false; renderProgress(null);
  refreshStatus();
  if (completedAny || manual) { loadGraph(); if (S.view === 'documents') loadDocuments(); setTimeout(() => { if (!S.selectedId && $('#inspTitle').textContent === 'Overview') showOverview(); }, 600); }
}

// ---------------------------------------------------------------- "Download my AI" (installer + this memory + a name)
const dl = {timer: null};
function aiName() { return (S.status && S.status.ai_name) || 'Mnemo'; }
function applyAiName() {
  const n = aiName(), custom = n !== 'Mnemo';
  document.title = custom ? `${n} · ATLAS` : 'ATLAS · Mnemo';
  const b = $('.topbar .brand'); if (b) b.innerHTML = custom ? `${esc(n)} <small>powered by Mnemo</small>` : 'ATLAS <small>Mnemo AI</small>';
  const h = $('.chat-empty h3'); if (h) h.textContent = `Hi, I'm ${n}`;
  const sub = $('.chat-empty .who'); if (sub) sub.hidden = !custom;
}
function dlEnsure() {
  let m = $('#dlModal'); if (m) return m;
  m = document.createElement('div'); m.className = 'modal-bg'; m.id = 'dlModal';
  m.innerHTML = '<div class="modal dl"><div class="modal-h"><span style="display:flex;gap:8px;align-items:center">' + icon('download', 'sm') + 'Download my AI</span><button class="btn ghost sm icon" data-close>' + icon('x', 'sm') + '</button></div><div class="modal-b" id="dlBody"></div></div>';
  document.body.appendChild(m);
  m.addEventListener('click', e => { if (e.target === m || e.target.closest('[data-close]')) { m.classList.toggle('show', false); clearInterval(dl.timer); } });
  return m;
}
async function openDownload() {
  dlEnsure().classList.add('show');
  $('#dlBody').innerHTML = '<div class="empty" style="padding:40px">Loading…</div>';
  let info;
  try { info = await api('package_info'); } catch (e) { $('#dlBody').innerHTML = `<div class="alert critical">${esc(e.message)}</div>`; return; }
  if (info.job && info.job.state === 'working') return dlProgress();
  const gb = b => (b / 1073741824).toFixed(1) + ' GB';
  $('#dlBody').innerHTML = `
    <div class="dl-hero"><img src="assets/icon.svg" alt=""><div><b>Turn what you taught into software</b>
      <div class="muted">One installer with the AI and everything it knows right now (${fmtNum(info.knows.concepts)} concepts from ${fmtNum(info.knows.sources)} sources). Install it on any PC and it answers from what you taught.</div></div></div>
    <label class="fld">What's the name of your AI? <input class="input" id="dlName" maxlength="40" value="${esc(info.ai_name)}" placeholder="e.g. Bruce AI"></label>
    <div class="dl-fixed"><span>${icon('cpu', 'sm')} Model: <b>Mnemo</b> 🔒</span><span><img src="assets/icon.svg" alt=""> Logo: <b>M</b> 🔒</span><span class="muted">These stay the same.</span></div>
    <label class="fld">Save to <input class="input" id="dlDest" value="${esc(info.default_dest)}"></label>
    <div class="muted" style="font-size:12px;margin-top:-4px">${info.base_ready ? 'About ' + gb(info.base_size) + '. Use a drive with enough space, like D:\\ or a USB drive.' : ''}</div>
    ${info.base_ready ? '' : `<div class="alert warn" style="margin-top:12px"><div><b>One-time step first.</b> Build the base installer once (it holds the web server and the AI):<br>
      run <code>C:\\xampp\\htdocs\\atlas\\installer\\Build ATLAS installer.bat</code> and press <b>1</b>. It goes to <code>${esc(info.base_dir)}</code>. After that, this button works any time.</div></div>`}
    ${info.job && info.job.state === 'done' ? `<div class="alert ok" style="margin-top:12px"><div>Last download: <code>${esc(info.job.folder)}</code></div></div>` : ''}
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:16px"><button class="btn" data-close>Cancel</button><button class="btn primary" id="dlGo" ${info.base_ready ? '' : 'disabled'}>${icon('download', 'sm')}Download</button></div>`;
  $('#dlName').focus(); $('#dlName').select();
  $('#dlGo').onclick = async () => {
    const b = $('#dlGo'); b.disabled = true; b.textContent = 'Preparing…';
    try { await api('package_create', {name: $('#dlName').value, dest: $('#dlDest').value}, 'POST'); dlProgress(); }
    catch (e) { b.disabled = false; b.innerHTML = icon('download', 'sm') + 'Download'; toast(esc(e.message), 'bad'); }
  };
}
function dlProgress() {
  const draw = async () => {
    let info; try { info = await api('package_info'); } catch { return; }
    const j = info.job || {};
    if (j.state === 'done') {
      clearInterval(dl.timer);
      $('#dlBody').innerHTML = `<div class="dl-done"><img src="assets/icon.svg" alt=""><h3>${esc(j.name)} is ready</h3>
        <div class="muted">Copy this whole folder to the other PC (USB drive), then double-click <b>ATLAS-Setup.exe</b>:</div>
        <div class="dl-path"><code id="dlPath">${esc(j.folder)}</code><button class="btn sm" id="dlCopy">${icon('copy', 'sm')}Copy path</button></div>
        <div class="muted" style="font-size:12px">It contains the installer, the memory (${fmtNum(info.knows.concepts)} concepts) and the name. Instructions are in "READ ME FIRST.txt".</div>
        <div style="margin-top:16px"><button class="btn primary" data-close>Done</button></div></div>`;
      $('#dlCopy').onclick = () => { navigator.clipboard?.writeText(j.folder); toast('Path copied', 'ok'); };
      return;
    }
    if (j.state === 'error') { clearInterval(dl.timer); $('#dlBody').innerHTML = `<div class="alert critical"><div>${esc(j.msg)}</div></div><div style="margin-top:12px"><button class="btn" id="dlBack">Back</button></div>`; $('#dlBack').onclick = openDownload; return; }
    $('#dlBody').innerHTML = `<div class="dl-done"><img src="assets/icon.svg" alt=""><h3>Preparing ${esc(j.name || 'your AI')}…</h3>
      <div class="progress dl-bar"><i style="width:${Math.max(2, j.pct || 0)}%"></i></div><b>${j.pct || 0}%</b>
      <div class="muted">${esc(j.msg || '')}</div><div class="muted" style="font-size:12px;margin-top:8px">You can close this window and keep using ATLAS. Come back here to see when it is ready.</div></div>`;
  };
  draw(); clearInterval(dl.timer); dl.timer = setInterval(() => { if ($('#dlModal').classList.contains('show')) draw(); }, 1500);
}
$('#btnDownloadAI').onclick = openDownload;

// ---------------------------------------------------------------- background parts (AI · Memory · Learner)
const sysView = {log: 'learner', timer: null};
async function refreshSystem(withLogs) {
  let st;
  try { st = await api('system', withLogs ? {logs: 1} : {}); }
  catch { st = {ai: {ok: false, text: 'Unknown (ATLAS cannot reach its memory)'}, memory: {ok: false, text: 'Not reachable: start ATLAS with the desktop shortcut'}, learner: {ok: false, text: 'Unknown'}}; }
  const learnerClass = st.learner.ok ? 'ok' : (S.processing ? 'warn' : '');
  $$('#sbSys [data-sys]').forEach(d => { const k = d.dataset.sys; d.className = 'dot ' + (k === 'learner' ? learnerClass : st[k].ok ? 'ok' : 'bad'); });
  $('#sbSys').title = `AI: ${st.ai.text}\nMemory: ${st.memory.text}\nLearner: ${st.learner.ok ? st.learner.text : (S.processing ? 'this tab is learning' : st.learner.text)}\n\nClick for details and logs`;
  return st;
}
function sysEnsure() {
  let m = $('#sysModal'); if (m) return m;
  m = document.createElement('div'); m.className = 'modal-bg'; m.id = 'sysModal';
  m.innerHTML = '<div class="modal sys"><div class="modal-h"><span style="display:flex;gap:8px;align-items:center">' + icon('cpu', 'sm') + 'Background parts</span><button class="btn ghost sm icon" data-close>' + icon('x', 'sm') + '</button></div><div class="modal-b" id="sysBody"></div></div>';
  document.body.appendChild(m);
  const hide = () => { m.classList.toggle('show', false); clearInterval(sysView.timer); };
  m.addEventListener('click', e => { if (e.target === m || e.target.closest('[data-close]')) hide(); });
  return m;
}
async function openSystem() {
  sysEnsure().classList.add('show');
  const draw = async () => {
    const st = await refreshSystem(true);
    const ai = st.ai, mem = st.memory, ln = st.learner;
    const card = (title, ok, big, sub) => `<div class="sys-card"><h4><span class="dot ${ok}"></span>${title}</h4><div class="big">${esc(big)}</div><div class="muted">${sub}</div></div>`;
    const models = (ai.loaded || []).map(l => `${esc(l.name)}: <b>${esc(l.where)}</b>`).join('<br>') || (ai.ok ? 'No model loaded right now (it wakes up when needed).' : '');
    const lnOk = ln.ok ? 'ok' : (S.processing ? 'warn' : 'bad');
    $('#sysBody').innerHTML = `
      <div class="muted" style="font-size:12.5px;margin-bottom:10px">These run hidden in the background, so they don't fill your taskbar. Green = working.</div>
      <div class="sys-grid">
        ${card('AI (Ollama)', ai.ok ? 'ok' : 'bad', ai.ok ? ai.model + ' · ' + ai.text : 'Not running', ai.ok ? 'Version ' + esc(ai.version) + '<br>' + models : 'Start ATLAS again with the desktop shortcut.')}
        ${card('Memory (MySQL)', mem.ok ? 'ok' : 'bad', mem.text, mem.ok ? esc(mem.version) : 'Start ATLAS again with the desktop shortcut.')}
        ${card('Learner (background)', lnOk, ln.ok ? ln.text : (S.processing ? 'This tab is learning' : ln.text), ln.ok ? (esc(ln.last) || 'Running') + (ln.pid ? '<br>Process ' + ln.pid : '') : esc(ln.hint || ''))}
      </div>
      <div class="sys-tabs">${[['learner', 'Learner log'], ['ai', 'AI log'], ['memory', 'Memory log']].map(([k, t]) => `<button class="btn sm ${sysView.log === k ? 'primary' : 'ghost'}" data-log="${k}">${t}</button>`).join('')}</div>
      <pre class="sys-log" id="sysLog">${esc((st.logs || {})[sysView.log] || 'No log yet. Logs appear after ATLAS is started with the desktop shortcut.')}</pre>`;
    $$('#sysBody [data-log]').forEach(b => b.onclick = () => { sysView.log = b.dataset.log; draw(); });
    const pre = $('#sysLog'); pre.scrollTop = pre.scrollHeight;
  };
  await draw();
  clearInterval(sysView.timer); sysView.timer = setInterval(() => { if ($('#sysModal').classList.contains('show')) draw(); }, 5000);
}
$('#sbSys').onclick = openSystem;

// ---------------------------------------------------------------- status
async function refreshStatus() {
  try {
    S.status = await api('status');
    const c = S.status.counts, o = S.status.ollama;
    $('#sbCounts').innerHTML = `${icon('files', 'sm')} ${fmtNum(c.documents)} sources &nbsp; ${icon('bulb', 'sm')} ${fmtNum(c.entities)} concepts &nbsp; ${icon('link', 'sm')} ${fmtNum(c.relations)} links &nbsp; ${icon('layers', 'sm')} ${fmtNum(c.chunks)} passages`;
    const ok = o.online && o.chat?.installed && o.embed?.installed;
    $('#mnemoStatus').innerHTML = `<span class="dot ${ok ? 'ok' : o.online ? 'warn' : 'bad'}"></span><span>Mnemo ${ok ? 'online' : o.online ? 'needs models' : 'offline'}</span>`;
    $('#mnemoStatus').title = o.online ? `Chat: ${o.chat.name} ${o.chat.installed ? '✓' : '✗ (run: ollama pull ' + o.chat.name + ')'}\nEmbeddings: ${o.embed.name} ${o.embed.installed ? '✓' : '✗ (run: ollama pull ' + o.embed.name + ')'}\nVision: ${o.vision.name || 'disabled'} ${o.vision.installed ? '✓' : '✗'}` : 'Ollama is not running. Start it to enable learning and answers.';
    $('#sbModel').innerHTML = o.online ? `${icon('cpu', 'sm')} ${esc(o.chat.name)}` : '';
    if (!S.processing && (S.status.pending.queued || S.status.pending.chunks_left || S.status.pending.embed_left || S.status.pending.claims_left) && ok) kickProcessing();
    renderStorageStatus(S.status.storage);
    applyAiName();
    refreshSystem();
    renderBadges(S.status.badges);
    renderIdentity(S.status);
  } catch (e) {
    $('#mnemoStatus').innerHTML = `<span class="dot bad"></span><span>Server error</span>`; $('#mnemoStatus').title = e.message;
    refreshSystem();
  }
}
$('#mnemoStatus').onclick = openSettings;

function refreshAll() { refreshStatus(); loadTree(); if (S.view === 'memory') loadMemory(); if (S.graph || S.view === 'graph') loadGraph(); if (S.view === 'documents') loadDocuments(); if (S.view === 'dashboard') loadDashboard(); if (S.view === 'timeline') loadTimeline(); }

// ---------------------------------------------------------------- settings
async function openSettings() {
  const s = await api('settings'); const o = S.status?.ollama || {};
  const opt = (sel, list) => [...new Set([sel, ...(list || [])])].filter(Boolean).map(m => `<option ${m === sel ? 'selected' : ''}>${esc(m)}</option>`).join('');
  const app = s.app || {}, hasMnemo = (o.models || []).some(m => /^mnemo:/.test(m));
  $('#settingsBody').innerHTML = `
    <div class="card" style="display:flex;gap:14px;align-items:center"><img class="about-logo" src="assets/icon.svg" alt="">
      <div style="flex:1"><b style="font-size:15px">${esc(app.name || 'ATLAS · Mnemo')}</b> <span class="badge">v${esc(app.version || '1.0.0')}</span>
        <div class="muted" style="font-size:12px">An offline AI that starts knowing nothing and learns from what you give it.${app.author ? ' Created by ' + esc(app.author) + '.' : ''}</div>
        <div class="muted" style="font-size:11.5px;margin-top:4px">Powered by Qwen 2.5 (Alibaba Cloud, Apache 2.0) · Ollama (MIT) · nomic-embed-text (Apache 2.0) · <a href="NOTICE.txt" target="_blank">licenses</a></div></div></div>
    ${hasMnemo && !/^mnemo:/.test(s.ollama.chat_model) ? `<div class="card" style="display:flex;gap:10px;align-items:center;border-color:rgba(251,191,36,.45)"><div style="flex:1;font-size:13px"><b>Mnemo's own model is installed.</b> <span class="muted">Use <code>${esc((o.models || []).find(m => /^mnemo:/.test(m)))}</code> as the chat model.</span></div><button class="btn sm primary" id="useMnemo">Use it</button></div>` : ''}
    <div class="card" style="display:flex;gap:10px;align-items:center"><span class="dot ${o.online ? 'ok' : 'bad'}"></span><div style="flex:1"><b>Ollama</b> <span class="muted">${esc(s.ollama.url)}</span><div class="muted" style="font-size:12px">${o.online ? (o.models.length + ' models installed') : 'Not running. Install from ollama.com and start it.'}</div></div></div>
    <label class="fld">Chat & reasoning model <select class="select" id="setChat">${opt(s.ollama.chat_model, o.models)}</select></label>
    <label class="fld">Embedding model <select class="select" id="setEmbed">${opt(s.ollama.embed_model, o.models)}</select><span class="muted" style="font-size:11.5px">Changing this later requires re-processing existing sources.</span></label>
    <label class="fld">Vision model (image understanding) <select class="select" id="setVision"><option value="">Disabled</option>${opt(s.ollama.vision_model, o.models)}</select></label>
    <label class="fld">Sources per answer <input class="input" type="number" min="3" max="20" id="setTopK" value="${esc(s.retrieval.top_k)}"></label>
    <div class="card"><h4>Speed</h4>
      <label class="fld">AI working memory <select class="select" id="setCtx">${[[4096, '4096 · fastest, shorter answers'], [6144, '6144 · best for a 6 GB graphics card'], [8192, '8192 · standard'], [16384, '16384 · 12 GB+ graphics card']].map(([v, t]) => `<option value="${v}" ${+s.ollama.num_ctx === v ? 'selected' : ''}>${t}</option>`).join('')}</select></label>
      <label style="display:flex;gap:8px;align-items:center;font-size:13px;margin-top:6px"><input type="checkbox" id="setEmbedCpu" ${['1', 'true', true].includes(s.ollama.embed_on_cpu) ? 'checked' : ''}> Run the search model on the CPU (leaves graphics memory for Mnemo)</label>
      <div class="muted" style="font-size:11.5px;margin-top:6px">Graphics card with 6 GB: choose 6144 and tick the box, so Mnemo runs 100% on the GPU. Check with <code>ollama ps</code>.</div></div>
    <div class="card"><h4>Optional tools</h4><dl class="kv"><dt>pdftotext (better PDFs)</dt><dd>${s.tools.pdftotext ? '<span class="badge ok">found</span>' : '<span class="badge">not found</span>'}</dd><dt>Tesseract (OCR)</dt><dd>${s.tools.tesseract ? '<span class="badge ok">found</span>' : '<span class="badge">not found</span>'}</dd>
      <dt>ffmpeg (audio/video)</dt><dd>${s.tools.ffmpeg ? '<span class="badge ok">found</span>' : '<span class="badge">not found</span>'}</dd>
      <dt>Whisper + model (transcription)</dt><dd>${s.tools.whisper && s.tools.whisper_model ? '<span class="badge ok">ready</span>' : '<span class="badge">not set</span>'}</dd>
      <dt>GD (image similarity)</dt><dd>${s.gd ? '<span class="badge ok">enabled</span>' : '<span class="badge">enable extension=gd</span>'}</dd>
      <dt>Native vector index</dt><dd>${s.vector.enabled ? '<span class="badge ok">on</span>' : s.vector.supported ? '<span class="badge">available</span>' : '<span class="badge">MariaDB 11.7+</span>'}</dd></dl>
      <div class="muted" style="font-size:11.5px;margin-top:8px">Set full paths in <code>config.php</code> if installed but not detected. <a href="setup.php">Open setup check →</a></div></div>`;
  if ($('#useMnemo')) $('#useMnemo').onclick = () => { const m = (o.models || []).find(x => /^mnemo:7b-trained/.test(x)) || (o.models || []).find(x => /^mnemo:/.test(x)); $('#setChat').value = m; $('#useMnemo').closest('.card').remove(); toast('Click Save to switch to ' + m, 'ok'); };
  $('#settingsModal').classList.add('show');
}
$('#btnSettings').onclick = openSettings;
$('#settingsSave').onclick = async () => {
  await api('settings_save', {ollama_chat_model: $('#setChat').value, ollama_embed_model: $('#setEmbed').value, ollama_vision_model: $('#setVision').value, ollama_num_ctx: $('#setCtx').value, ollama_embed_on_cpu: $('#setEmbedCpu').checked ? '1' : '0', retrieval_top_k: $('#setTopK').value});
  $('#settingsModal').classList.remove('show'); toast('Settings saved', 'ok'); refreshStatus();
};

// ---------------------------------------------------------------- collections
$('#btnNewCollection').onclick = async () => {
  const name = prompt('New collection name (e.g. "Thesis research", "Biology"):'); if (!name) return;
  await api('collection_create', {name, color: PALETTE[Math.floor(Math.random() * PALETTE.length)]}); loadTree(); toast('Collection created', 'ok');
};
$('#btnRefreshTree').onclick = loadTree;

// ---------------------------------------------------------------- global search
let searchSel = -1;
const runSearch = debounce(async q => {
  const box = $('#searchResults');
  if (q.length < 2) { box.hidden = true; return; }
  const r = await api('search', {q});
  const items = [...r.entities.map(e => ({k: 'e', id: e.id, html: `<span style="color:${TYPE_COLOR[e.type]}">${icon(TYPE_ICON[e.type] || 'tag', 'sm')}</span><span style="flex:1">${esc(e.name)}</span><span class="muted">${esc(e.type)}</span>`})),
                 ...r.documents.map(d => ({k: 'd', id: d.id, html: `${icon(d.kind === 'image' ? 'image' : 'doc', 'sm')}<span style="flex:1">${esc(d.title)}</span><span class="muted">source</span>`}))];
  box.innerHTML = (r.entities.length ? '<div class="grp">Concepts</div>' : '') + items.filter(i => i.k === 'e').map((i, n) => `<div class="it" data-k="e" data-id="${i.id}">${i.html}</div>`).join('') +
    (r.documents.length ? '<div class="grp">Sources</div>' : '') + items.filter(i => i.k === 'd').map(i => `<div class="it" data-k="d" data-id="${i.id}">${i.html}</div>`).join('') +
    `<div class="grp">Ask</div><div class="it" data-k="q">${icon('chat', 'sm')}<span style="flex:1">Ask Mnemo: “${esc(q)}”</span></div>`;
  box.hidden = false; searchSel = -1;
}, 160);
function pickSearch(it) {
  const q = $('#globalSearch').value;
  $('#searchResults').hidden = true; $('#globalSearch').blur();
  if (it.dataset.k === 'e') { if (S.view !== 'graph') setView('graph'); setTimeout(() => openEntity(+it.dataset.id, true), 60); }
  if (it.dataset.k === 'd') openDocument(+it.dataset.id);
  if (it.dataset.k === 'q') { setView('ask'); $('#chatInput').value = q; sendChat(); }
  $('#globalSearch').value = '';
}
$('#globalSearch').addEventListener('input', e => runSearch(e.target.value.trim()));
$('#globalSearch').addEventListener('keydown', e => {
  const its = $$('#searchResults .it');
  if (e.key === 'ArrowDown' || e.key === 'ArrowUp') { e.preventDefault(); searchSel = (searchSel + (e.key === 'ArrowDown' ? 1 : -1) + its.length) % its.length; its.forEach((x, i) => x.classList.toggle('sel', i === searchSel)); }
  if (e.key === 'Enter') { const it = its[searchSel] || its[its.length - 1]; if (it) pickSearch(it); }
  if (e.key === 'Escape') { $('#searchResults').hidden = true; e.target.blur(); }
});
$('#searchResults').addEventListener('mousedown', e => { const it = e.target.closest('.it'); if (it) { e.preventDefault(); pickSearch(it); } });
$('#globalSearch').addEventListener('blur', () => setTimeout(() => $('#searchResults').hidden = true, 150));


// ---------------------------------------------------------------- memory & storage
let memWarned = '';
function renderStorageStatus(st) {
  if (!st) return;
  const el = $('#sbStorage');
  el.className = 'sb sb-storage ' + (st.level !== 'ok' ? st.level : '');
  el.innerHTML = `${icon('database', 'sm')} DB ${fmtBytes(st.db_bytes)} &nbsp;${icon('hdd', 'sm')} ${fmtBytes(st.disk_free)} free${st.level !== 'ok' ? ' &nbsp;' + icon('alert', 'sm') : ''}`;
  el.style.cursor = 'pointer'; el.onclick = () => setView('memory');
  const a = $('#memAlert');
  a.hidden = st.level === 'ok'; a.className = 'tab-alert ' + (st.level === 'critical' ? 'critical' : '');
  const key = st.warnings.map(w => w.message).join('|');
  if (key && key !== memWarned) { memWarned = key; st.warnings.forEach(w => toast('<b>Storage:</b> ' + esc(w.message), w.level === 'critical' ? 'bad' : '')); }
}

async function loadMemory() {
  const box = $('#memory');
  let m; try { m = await api('storage'); } catch (e) { box.innerHTML = `<div class="alert critical">${esc(e.message)}</div>`; return; }
  const t = m.thresholds, MB = 1048576;
  const pct = (v, max) => Math.min(100, Math.round(v / Math.max(1, max) * 100));
  const lvl = (v, w, c) => v >= c ? 'critical' : v >= w ? 'warn' : '';
  const diskUsed = m.disk_total - m.disk_free;
  const totalMemory = m.db_bytes + m.uploads_bytes;
  const lim = m.limits, my = lim.mysql, php = lim.php;
  const rec = [
    ['upload_max_filesize', 'php.ini', php.upload_max_filesize, '128M', 'Largest single file you can upload'],
    ['post_max_size', 'php.ini', php.post_max_size, '128M', 'Must be ≥ upload_max_filesize'],
    ['max_execution_time', 'php.ini', php.max_execution_time + 's', '300', 'Long PDFs need time to read'],
    ['memory_limit', 'php.ini', php.memory_limit, '512M', 'Vector search and big PDFs'],
    ['max_allowed_packet', 'my.ini', fmtBytes(+my.max_allowed_packet || 0), '64M', 'Largest single row MySQL accepts'],
    ['innodb_buffer_pool_size', 'my.ini', fmtBytes(+my.innodb_buffer_pool_size || 0), '1G–2G', 'MySQL memory cache (speed)'],
    ['innodb_log_file_size', 'my.ini', fmtBytes(+my.innodb_log_file_size || 0), '256M', 'Faster large imports'],
    ['innodb_ft_min_token_size', 'my.ini', my.innodb_ft_min_token_size, '2', 'Lets short words like "AI" be found'],
  ];
  box.innerHTML = `
    ${m.warnings.map(w => `<div class="alert ${w.level}">${icon(w.level === 'info' ? 'bulb' : 'alert', 'sm')}<div>${esc(w.message)}</div></div>`).join('')}
    ${m.level === 'ok' && !m.warnings.length ? `<div class="alert info">${icon('shield', 'sm')}<div>Storage is healthy.</div></div>` : ''}
    <div class="card" style="margin-bottom:12px"><h4>Memory cards <span>insert · eject · switch, like a memory card in a phone</span></h4><div id="memCards"><div class="empty">Loading…</div></div></div>
    <div class="gauges">
      <div class="gauge"><div class="l">${icon('database', 'sm')}MySQL database</div><div class="v">${fmtBytes(m.db_bytes)} <small>/ warn at ${fmtNum(t.warn_db_mb)} MB</small></div>
        <div class="bar"><i class="${lvl(m.db_bytes, t.warn_db_mb * MB, t.crit_db_mb * MB)}" style="width:${pct(m.db_bytes, t.crit_db_mb * MB)}%"></i><b style="left:${pct(t.warn_db_mb, t.crit_db_mb)}%"></b></div>
        <div class="s">Critical at ${fmtNum(t.crit_db_mb)} MB · ${fmtNum(m.tables.reduce((a, x) => a + x.rows, 0))} rows</div></div>
      <div class="gauge"><div class="l">${icon('hdd', 'sm')}Disk</div><div class="v">${fmtBytes(m.disk_free)} <small>free of ${fmtBytes(m.disk_total)}</small></div>
        <div class="bar"><i class="${m.disk_free < t.crit_disk_free_mb * MB ? 'critical' : m.disk_free < t.warn_disk_free_mb * MB ? 'warn' : ''}" style="width:${pct(diskUsed, m.disk_total)}%"></i></div>
        <div class="s">Warn below ${fmtNum(t.warn_disk_free_mb)} MB · uploads pause below ${fmtNum(t.crit_disk_free_mb)} MB</div></div>
      <div class="gauge"><div class="l">${icon('files', 'sm')}Original files</div><div class="v">${fmtBytes(m.uploads_bytes)}</div>
        <div class="bar"><i style="width:${pct(m.uploads_bytes, totalMemory)}%"></i></div><div class="s">storage/uploads · ${pct(m.uploads_bytes, totalMemory)}% of Mnemo's total memory</div></div>
      <div class="gauge"><div class="l">${icon('download', 'sm')}Backups</div><div class="v">${m.backups.length} <small>· ${fmtBytes(m.backups_bytes)}</small></div>
        <div class="bar"><i style="width:${m.last_backup ? 100 : 0}%"></i></div><div class="s">${m.last_backup ? 'Last: ' + esc(m.last_backup) + ' (' + ago(m.last_backup) + ')' : 'No backups yet'}</div></div>
    </div>

    <div class="grid2b">
      <div class="card"><h4>Download &amp; back up Mnemo's memory</h4>
        <div style="font-size:12.5px;color:var(--text-2);margin-bottom:10px">Save the whole MySQL memory as one file you can keep on a USB drive. Restore it here at any time, even on another PC.</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
          <button class="btn primary sm" id="bkDb">${icon('download', 'sm')}Back up database</button>
          <button class="btn sm" id="bkFull">${icon('box', 'sm')}Full backup (database + files)</button>
          <button class="btn sm" id="bkRestore">${icon('retry', 'sm')}Restore from file…</button>
          <input type="file" id="bkFile" accept=".gz,.sql,.zip" hidden>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px">
          <a class="btn ghost sm" href="api/index.php?action=export_md">${icon('doc', 'sm')}Export knowledge (.md)</a>
          <a class="btn ghost sm" href="api/index.php?action=export_graph">${icon('graph', 'sm')}Export graph (.json)</a>
        </div>
        <div style="margin-top:12px;max-height:260px;overflow:auto">${m.backups.map(b => `<div class="list-row" style="cursor:default">${icon(b.with_files ? 'box' : 'database', 'sm')}
            <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${esc(b.file)}">${esc(b.file)}</span><span class="muted">${fmtBytes(b.bytes)}</span>
            <a class="btn ghost sm icon" href="api/index.php?action=backup_download&file=${encodeURIComponent(b.file)}" title="Download">${icon('download', 'sm')}</a>
            <button class="btn ghost sm icon" data-restore="${esc(b.file)}" title="Restore this backup">${icon('retry', 'sm')}</button>
            <button class="btn ghost sm icon danger" data-bdel="${esc(b.file)}" title="Delete backup">${icon('trash', 'sm')}</button></div>`).join('') || '<div class="empty">No backups yet</div>'}</div>
      </div>
      <div class="card"><h4>Where the memory is stored</h4>
        ${m.tables.slice(0, 12).map(x => `<div class="hbar"><span class="n" style="width:130px" title="${esc(x.name)}">${esc(x.name)}</span><span class="t"><i style="width:${pct(x.bytes, m.tables[0].bytes)}%;background:#38bdf8"></i></span><span class="c" style="width:70px">${fmtBytes(x.bytes)}</span><span class="c" style="width:60px">${fmtNum(x.rows)}</span></div>`).join('')}
        <div class="muted" style="font-size:11.5px;margin-top:8px">MySQL itself has no practical size limit (an InnoDB table can grow to 64 TB). The real limits are your disk space and the upload / packet sizes below.</div>
      </div>
    </div>

    <div class="card" style="margin-top:12px"><h4>Limits <span>current → recommended</span></h4>
      <div class="limit-row h"><span>Setting</span><span>Current</span><span>Recommended</span><span>Why</span></div>
      ${rec.map(([k, f, cur, r, why]) => `<div class="limit-row"><span><code>${k}</code> <span class="muted">${f}</span></span><span>${esc(cur)}</span><span style="color:var(--ok)">${r}</span><span class="muted">${why}</span></div>`).join('')}
      <div class="muted" style="font-size:11.5px;margin-top:10px">Edit <code>C:\\xampp\\php\\php.ini</code> and <code>C:\\xampp\\mysql\\bin\\my.ini</code> (under <code>[mysqld]</code>), then restart Apache and MySQL from the XAMPP Control Panel. Effective upload limit right now: <b>${fmtBytes(lim.upload_effective)}</b>.</div>
    </div>

    <div class="grid2b">
      <div class="card"><h4>Warning levels</h4>
        <div class="thr">
          <label class="fld">Warn when database exceeds (MB)<input class="input" type="number" id="thWarnDb" value="${t.warn_db_mb}"></label>
          <label class="fld">Critical database size (MB)<input class="input" type="number" id="thCritDb" value="${t.crit_db_mb}"></label>
          <label class="fld">Warn when free disk below (MB)<input class="input" type="number" id="thWarnDisk" value="${t.warn_disk_free_mb}"></label>
          <label class="fld">Pause uploads below (MB)<input class="input" type="number" id="thCritDisk" value="${t.crit_disk_free_mb}"></label>
          <label class="fld">Max file size (MB)<input class="input" type="number" id="thMaxUp" value="${t.max_upload_mb}"></label>
          <label class="fld">Keep automatic backups<input class="input" type="number" id="thKeep" value="${t.backup_keep}"></label>
        </div>
        <button class="btn sm primary" id="thSave" style="margin-top:10px">Save warning levels</button>
      </div>
      <div class="card"><h4>Maintenance</h4>
        <div class="danger-row"><div class="t"><b>Rebuild search index</b><span>After changing innodb_ft_min_token_size in my.ini</span></div><button class="btn sm" data-mt="rebuild_index">${icon('wrench', 'sm')}Rebuild</button></div>
        <div class="danger-row"><div class="t"><b>Re-embed all passages</b><span>Required after changing the embedding model</span></div><button class="btn sm" data-mt="reembed">${icon('refresh', 'sm')}Re-embed</button></div>
        <div class="danger-row"><div class="t"><b>Optimize tables</b><span>Reclaims space after deleting lots of memory</span></div><button class="btn sm" data-mt="optimize">${icon('database', 'sm')}Optimize</button></div>
        <div class="danger-row"><div class="t"><b>Native vector index ${m.vector.enabled ? '<span class="badge ok">on</span>' : ''}</b><span>${m.vector.supported ? 'Fast search for 50k+ passages (MariaDB ' + esc(m.vector.version.split('-')[0]) + ')' : 'Needs MariaDB 11.7+ (you have ' + esc(m.vector.version.split('-')[0]) + '). PHP search handles about 50k passages fine.'} · ${fmtNum(m.embeddings)} vectors</span></div>
          <button class="btn sm" id="vecToggle" ${m.vector.supported || m.vector.enabled ? '' : 'disabled'}>${icon('layers', 'sm')}${m.vector.enabled ? 'Disable' : 'Enable'}</button></div>
      </div>
    </div>

    <div class="card danger-zone" style="margin-top:12px"><h4 style="color:var(--bad)">Danger zone · delete memory</h4>
      ${Object.entries(m.wipe_scopes).map(([k, label]) => `<div class="danger-row"><div class="t"><b>${k === 'everything' ? 'Delete ALL memory (reset Mnemo)' : 'Delete ' + esc(label.split(',')[0].toLowerCase())}</b><span>${esc(label)}</span></div>
        <button class="btn sm ${k === 'everything' ? 'danger-solid' : 'danger-outline'}" data-wipe="${k}" data-label="${esc(label)}">${icon('trash', 'sm')}Delete</button></div>`).join('')}
      <div class="muted" style="font-size:11.5px;margin-top:8px">To forget a single source or concept, open it and click <b>Forget</b>. A safety backup is made before every delete, so you can restore it.</div>
    </div>`;

  const doBackup = async full => {
    setWork(full ? 'Creating full backup…' : 'Backing up database…', true);
    try { const r = await api('backup_create', {with_files: full}); toast('Backup ready: downloading ' + esc(r.file), 'ok'); location.href = 'api/index.php?action=backup_download&file=' + encodeURIComponent(r.file); loadMemory(); }
    catch (e) { toast('Backup failed: ' + esc(e.message), 'bad'); }
    setWork();
  };
  $('#bkDb').onclick = () => doBackup(false);
  $('#bkFull').onclick = () => doBackup(true);
  $('#bkRestore').onclick = () => $('#bkFile').click();
  $('#bkFile').onchange = async e => {
    const f = e.target.files[0]; e.target.value = ''; if (!f) return;
    if (!confirm(`Restore "${f.name}"? Mnemo's current memory will be REPLACED (a safety backup is saved first).`)) return;
    const fd = new FormData(); fd.append('backup', f);
    setWork('Restoring memory…', true);
    try { const r = await fetch('api/index.php?action=restore', {method: 'POST', body: fd}); const j = await r.json(); if (j.error) throw new Error(j.error);
      toast(`Memory restored (${j.statements} statements, ${j.files} files). Safety copy: ${esc(j.safety_backup)}`, 'ok'); refreshAll(); loadMemory(); }
    catch (err) { toast('Restore failed: ' + esc(err.message), 'bad'); }
    setWork();
  };
  $$('#memory [data-restore]').forEach(b => b.onclick = async () => {
    if (!confirm(`Restore ${b.dataset.restore}? Current memory will be replaced (a safety backup is saved first).`)) return;
    setWork('Restoring memory…', true);
    try { const j = await api('restore', {file: b.dataset.restore}); toast(`Memory restored. Safety copy: ${esc(j.safety_backup)}`, 'ok'); refreshAll(); loadMemory(); }
    catch (e) { toast('Restore failed: ' + esc(e.message), 'bad'); }
    setWork();
  });
  $$('#memory [data-bdel]').forEach(b => b.onclick = async () => { if (!confirm('Delete this backup file?')) return; await api('backup_delete', {file: b.dataset.bdel}); loadMemory(); });
  $('#thSave').onclick = async () => {
    await api('storage_settings_save', {warn_db_mb: $('#thWarnDb').value, crit_db_mb: $('#thCritDb').value, warn_disk_free_mb: $('#thWarnDisk').value,
      crit_disk_free_mb: $('#thCritDisk').value, max_upload_mb: $('#thMaxUp').value, backup_keep: $('#thKeep').value});
    toast('Warning levels saved', 'ok'); memWarned = ''; refreshStatus(); loadMemory();
  };
  $$('#memory [data-mt]').forEach(b => b.onclick = async () => {
    const a = b.dataset.mt;
    if (a === 'reembed' && !confirm('Re-embed every passage with the current embedding model? This can take a while.')) return;
    b.disabled = true;
    try { await api(a, {}); toast({rebuild_index: 'Search index rebuilt', reembed: 'Re-embedding queued. Mnemo is working on it.', optimize: 'Tables optimized'}[a], 'ok'); if (a === 'reembed') kickProcessing(); loadMemory(); }
    catch (e) { toast(esc(e.message), 'bad'); }
    b.disabled = false;
  });
  $$('#memory [data-wipe]').forEach(b => b.onclick = () => openWipe(b.dataset.wipe, b.dataset.label));
  renderMemoryCards();
  if ($('#vecToggle')) $('#vecToggle').onclick = async () => {
    try { const r = await api('vector_native', {enable: !m.vector.enabled}); toast(m.vector.enabled ? 'Native vector index disabled' : `Native vector index built (${r.indexed} vectors)`, 'ok'); loadMemory(); }
    catch (e) { toast(esc(e.message), 'bad'); }
  };
}
$('#btnMemRefresh').onclick = loadMemory;

let wipeScope = null;
function openWipe(scope, label) {
  wipeScope = scope;
  $('#wipeWhat').innerHTML = `You are about to delete: <b style="color:var(--text)">${esc(label)}</b>.` + (scope === 'everything' ? '<br><br><span style="color:var(--bad)">Mnemo will forget everything and start from zero knowledge.</span>' : '');
  $('#wipeConfirm').value = ''; $('#wipeGo').disabled = true; $('#wipeBackup').checked = true;
  $('#wipeModal').classList.add('show'); setTimeout(() => $('#wipeConfirm').focus(), 50);
}
$('#wipeConfirm').addEventListener('input', e => $('#wipeGo').disabled = e.target.value !== 'DELETE');
$('#wipeGo').onclick = async () => {
  $('#wipeGo').disabled = true;
  try {
    const r = await api('wipe', {scope: wipeScope, confirm: 'DELETE', backup: $('#wipeBackup').checked});
    $('#wipeModal').classList.remove('show');
    toast('Memory deleted.' + (r.safety_backup ? ' Safety backup: ' + esc(r.safety_backup) : ''), 'ok');
    if (wipeScope === 'conversations') { S.conv = 0; chatEmpty(); }
    S.selectedId = null; refreshAll(); loadMemory(); showOverview();
    if (wipeScope === 'graph') kickProcessing();
  } catch (e) { toast(esc(e.message), 'bad'); $('#wipeGo').disabled = false; }
};

// ---------------------------------------------------------------- badges
function renderBadges(b) {
  if (!b) return;
  $$('[data-badge]').forEach(el => {
    const n = b[el.dataset.badge] || 0;
    el.hidden = !n;
    if (el.tagName === 'B') { el.textContent = n > 99 ? '99+' : n; el.classList.toggle('warn', el.dataset.badge === 'review' && b.contradictions > 0); }
  });
}
const scoreClass = s => s >= 0.7 ? 'hi' : s >= 0.4 ? 'mid' : 'lo';
const pctTxt = s => Math.round((+s || 0) * 100) + '%';

// ---------------------------------------------------------------- Review (Phase 5)
let reviewTab = 'contradictions', conStatus = 'open';
$('#reviewTabs').addEventListener('click', e => { const b = e.target.closest('button'); if (!b) return; reviewTab = b.dataset.rt; $$('#reviewTabs button').forEach(x => x.classList.toggle('on', x === b)); loadReview(); });
async function loadReview() {
  const box = $('#review');
  try {
    const c = await api('review_counts');
    $$('[data-rc]').forEach(el => { const n = c[el.dataset.rc] || 0; el.textContent = n; el.classList.toggle('zero', !n); el.classList.toggle('warn', el.dataset.rc === 'contradictions' && n > 0); });
    if (reviewTab === 'contradictions') {
      const rows = await api('contradictions', {status: conStatus});
      const side = (r, k, win) => `<div class="side ${win === true ? 'win' : win === false ? 'lose' : ''}">
          <div style="display:flex;gap:6px;flex-wrap:wrap">${r[k + '_origin'] === 'user' ? '<span class="badge ok">taught by you</span>' : ''}<span class="badge">trust ${r[k + '_trust'] ?? '—'}</span></div>
          <div style="font-size:14px;color:var(--text)">${esc(r[k + '_statement'])}</div>
          <div class="src" data-c="${r[k + '_chunk'] || ''}">${icon('doc', 'sm')} ${esc(r[k + '_title'] || 'Taught by you')}${r[k + '_page'] ? ' · p.' + r[k + '_page'] : ''}</div></div>`;
      box.innerHTML = `<div style="display:flex;gap:8px;margin-bottom:12px;align-items:center"><div class="glass seg" style="background:var(--panel)">
          <button data-cs="open" class="${conStatus === 'open' ? 'on' : ''}">Open</button><button data-cs="resolved" class="${conStatus === 'resolved' ? 'on' : ''}">Resolved</button></div>
          <span class="muted" style="font-size:12px">When sources disagree, you decide. Mnemo stops trusting the losing fact everywhere.</span></div>` +
        (rows.map(r => {
          const win = r.status === 'a_correct' ? [true, false] : r.status === 'b_correct' ? [false, true] : [null, null];
          return `<div class="rv-card"><div class="vs">${side(r, 'a', win[0])}<div class="mid">VS</div>${side(r, 'b', win[1])}</div>
            ${r.reason ? `<div class="rv-reason">${icon('bulb', 'sm')}<span>${esc(r.reason)}</span></div>` : ''}
            ${r.status === 'open' ? `<div class="rv-actions"><button class="btn sm" data-res="a_correct" data-id="${r.id}">${icon('check', 'sm')}Left is right</button>
              <button class="btn sm" data-res="b_correct" data-id="${r.id}">${icon('check', 'sm')}Right is right</button>
              <button class="btn sm ghost" data-res="both_valid" data-id="${r.id}">Both valid (different context)</button>
              <button class="btn sm ghost" data-res="ignored" data-id="${r.id}">Ignore</button></div>`
              : `<div class="rv-actions"><span class="badge">${esc(r.status.replace('_', ' '))} · ${esc((r.resolved_at || '').slice(0, 16))}</span></div>`}</div>`;
        }).join('') || `<div class="empty" style="padding:50px">${icon('check', 'lg')}<br>${conStatus === 'open' ? 'No open contradictions. Your sources agree.' : 'Nothing resolved yet.'}</div>`);
      $$('[data-cs]', box).forEach(b => b.onclick = () => { conStatus = b.dataset.cs; loadReview(); });
      $$('[data-res]', box).forEach(b => b.onclick = async () => { await api('contradiction_resolve', {id: +b.dataset.id, decision: b.dataset.res}); toast('Decision saved. Mnemo updated its beliefs.', 'ok'); loadReview(); refreshStatus(); });
      $$('.src[data-c]', box).forEach(b => b.onclick = () => b.dataset.c && openChunk(+b.dataset.c));
    }
    if (reviewTab === 'relations') {
      const rows = await api('review_items', {kind: 'relation'});
      box.innerHTML = `<div class="muted" style="font-size:12px;margin-bottom:12px">Links the AI extracted but whose quoted evidence could not be found in the passage. Approve the true ones; reject the hallucinations.</div>` +
        (rows.map(r => `<div class="rv-card"><div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:14px">
            <b data-e="${r.s_id}" style="cursor:pointer;color:var(--text)">${esc(r.s_name)}</b><span class="badge accent">${esc(r.type.replace(/_/g, ' '))}</span><b data-e="${r.t_id}" style="cursor:pointer;color:var(--text)">${esc(r.t_name)}</b>
            <span class="muted" style="margin-left:auto;font-size:12px">confidence ${pctTxt(r.confidence)}</span></div>
            ${r.evidence ? `<div class="rv-reason">${icon('alert', 'sm')}<span>AI's quote: “${esc(r.evidence)}”</span></div>` : ''}
            ${r.passage ? `<div class="snip" data-c="${r.chunk_id}">${esc(r.passage)}…<span class="src">${esc(r.doc_title || '')}${r.page ? ' · p.' + r.page : ''}</span></div>` : ''}
            <div class="rv-actions"><button class="btn sm" data-dec="1" data-id="${r.id}">${icon('check', 'sm')}Correct</button><button class="btn sm danger-outline" data-dec="0" data-id="${r.id}">${icon('x', 'sm')}Wrong, delete link</button></div></div>`).join('')
        || `<div class="empty" style="padding:50px">Nothing to verify.</div>`);
      $$('[data-dec]', box).forEach(b => b.onclick = async () => { await api('review_decide', {id: +b.dataset.id, approve: b.dataset.dec === '1'}); b.closest('.rv-card').remove(); refreshStatus(); loadReviewCountsOnly(); });
      $$('[data-e]', box).forEach(b => b.onclick = () => openEntity(+b.dataset.e));
      $$('.snip[data-c]', box).forEach(b => b.onclick = () => openChunk(+b.dataset.c));
    }
    if (reviewTab === 'merges') {
      const [rows, merges] = await Promise.all([api('review_items', {kind: 'merge'}), api('merges')]);
      const ent = (id, name, type, desc, n) => `<div class="side"><b data-e="${id}" style="cursor:pointer;color:var(--text);font-size:14px">${esc(name)}</b><span class="muted" style="font-size:12px">${esc(type)} · ${n} mentions</span><span style="font-size:12.5px;color:var(--text-2)">${esc((desc || '').slice(0, 180))}</span></div>`;
      box.innerHTML = `<div class="muted" style="font-size:12px;margin-bottom:12px">Concepts that look like the same thing. Merging moves every link and mention to the left concept. It can be undone.</div>` +
        (rows.map(r => `<div class="rv-card"><div class="vs">${ent(r.a_id, r.a_name, r.a_type, r.a_desc, r.a_mentions)}<div class="mid" style="color:var(--accent)">${icon('merge')}</div>${ent(r.b_id, r.b_name, r.b_type, r.b_desc, r.b_mentions)}</div>
          <div class="rv-reason">${icon('bulb', 'sm')}<span>${esc(r.reason)}</span></div>
          <div class="rv-actions"><button class="btn sm primary" data-dec="1" data-id="${r.id}">${icon('merge', 'sm')}Same thing, merge</button><button class="btn sm ghost" data-dec="0" data-id="${r.id}">Different things</button></div></div>`).join('')
        || `<div class="empty" style="padding:30px">No duplicate suggestions.</div>`) +
        (merges.length ? `<div class="card" style="margin-top:14px"><h4>Recent merges</h4>${merges.map(m => `<div class="list-row" style="cursor:default">${icon('merge', 'sm')}<span style="flex:1">${esc(m.merged_name)} → <b data-e="${m.kept_id}" style="cursor:pointer">${esc(m.kept_name || '?')}</b></span><span class="muted">${ago(m.created_at)}</span>
            ${m.undone_at ? '<span class="badge">undone</span>' : `<button class="btn ghost sm" data-undo="${m.id}">${icon('undo', 'sm')}Undo</button>`}</div>`).join('')}</div>` : '');
      $$('[data-dec]', box).forEach(b => b.onclick = async () => { try { await api('review_decide', {id: +b.dataset.id, approve: b.dataset.dec === '1'}); toast(b.dataset.dec === '1' ? 'Merged' : 'Marked as different', 'ok'); loadReview(); refreshAll(); } catch (e) { toast(esc(e.message), 'bad'); } });
      $$('[data-undo]', box).forEach(b => b.onclick = async () => { try { await api('merge_undo', {id: +b.dataset.undo}); toast('Merge undone', 'ok'); loadReview(); refreshAll(); } catch (e) { toast(esc(e.message), 'bad'); } });
      $$('[data-e]', box).forEach(b => b.onclick = () => openEntity(+b.dataset.e));
    }
    if (reviewTab === 'hypotheses') {
      const rows = await api('hypotheses');
      box.innerHTML = `<div class="muted" style="font-size:12px;margin-bottom:12px">Mnemo noticed these concepts are never linked directly, yet share several neighbours. Maybe they're connected?</div>` +
        (rows.map(h => `<div class="rv-card"><div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:15px">
            <b data-e="${h.a_id}" style="cursor:pointer;color:var(--text)">${esc(h.a_name)}</b><span style="color:var(--violet)">⟷ ?</span><b data-e="${h.b_id}" style="cursor:pointer;color:var(--text)">${esc(h.b_name)}</b>
            <span class="badge violet" style="margin-left:auto">score ${(+h.score).toFixed(2)}</span></div>
          <div class="rv-reason">${icon('graph', 'sm')}<span>Both connect to: ${h.shared.map(x => `<b data-e="${x.id}" style="cursor:pointer">${esc(x.name)}</b>`).join(', ')}</span></div>
          <div class="rv-actions"><button class="btn sm" data-hy="1" data-id="${h.id}">${icon('link', 'sm')}Yes, connect them</button>
            <button class="btn sm ghost" data-ask="${esc(h.a_name)}|${esc(h.b_name)}">${icon('chat', 'sm')}Ask Mnemo</button>
            <button class="btn sm ghost" data-hy="0" data-id="${h.id}">Not related</button></div></div>`).join('')
        || `<div class="empty" style="padding:50px">No suggestions yet. They appear as the graph grows.</div>`);
      $$('[data-hy]', box).forEach(b => b.onclick = async () => { await api('hypothesis_decide', {id: +b.dataset.id, accept: b.dataset.hy === '1'}); b.closest('.rv-card').remove(); if (b.dataset.hy === '1') { toast('Connection added to the graph', 'ok'); if (S.graph) loadGraph(); } loadReviewCountsOnly(); });
      $$('[data-ask]', box).forEach(b => b.onclick = () => { const [x, y] = b.dataset.ask.split('|'); setView('ask'); $('#chatInput').value = `How is ${x} related to ${y}?`; sendChat(); });
      $$('[data-e]', box).forEach(b => b.onclick = () => openEntity(+b.dataset.e));
    }
  } catch (e) { box.innerHTML = `<div class="alert critical">${esc(e.message)}</div>`; }
}
async function loadReviewCountsOnly() {
  const c = await api('review_counts');
  $$('[data-rc]').forEach(el => { const n = c[el.dataset.rc] || 0; el.textContent = n; el.classList.toggle('zero', !n); });
  refreshStatus();
}
$('#btnTruthScan').onclick = async () => {
  const b = $('#btnTruthScan'); b.disabled = true; setWork('Fact-checking and scanning the graph…', true);
  try { const r = await api('truth_scan', {}); toast(`Scan done: ${r.checked} facts checked, ${r.merges} duplicate suggestions, ${r.hypotheses} new connections${r.remaining ? ` (${r.remaining} facts still queued)` : ''}`, 'ok'); loadReview(); refreshStatus(); }
  catch (e) { toast('Scan failed: ' + esc(e.message), 'bad'); }
  b.disabled = false; setWork();
};

// merge modal
let mergeFrom = null;
function openMerge(id, name) {
  mergeFrom = id;
  $('#mergeWhat').innerHTML = `Merge <b style="color:var(--text)">${esc(name)}</b> into another concept.`;
  $('#mergeTarget').value = '';
  if (!$('#entityList').children.length) api('graph', {limit: 3000}).then(d => $('#entityList').innerHTML = d.nodes.map(n => `<option value="${esc(n.name)}">`).join(''));
  $('#mergeModal').classList.add('show'); setTimeout(() => $('#mergeTarget').focus(), 50);
}
$('#mergeGo').onclick = async () => {
  const name = $('#mergeTarget').value.trim().toLowerCase();
  let target = S.data.nodes.find(n => n.name.toLowerCase() === name);
  if (!target) { const r = await api('search', {q: $('#mergeTarget').value.trim()}); target = r.entities.find(e => e.name.toLowerCase() === name) || r.entities[0]; }
  if (!target) { toast('Concept not found', 'bad'); return; }
  try { await api('merge', {keep: target.id, merge: mergeFrom}); $('#mergeModal').classList.remove('show'); toast('Merged. You can undo it from the concept\'s panel.', 'ok'); refreshAll(); openEntity(target.id, true); }
  catch (e) { toast(esc(e.message), 'bad'); }
};

// ---------------------------------------------------------------- Practice (Phase 7)
let deck = [], deckIdx = 0;
async function loadPractice() {
  const box = $('#practice');
  let d; try { d = await api('cards'); } catch (e) { box.innerHTML = `<div class="alert critical">${esc(e.message)}</div>`; return; }
  deck = d.due; deckIdx = 0;
  const st = d.stats, maxBox = Math.max(1, ...Object.values(st.boxes));
  box.innerHTML = `<div class="kpis" style="max-width:900px;margin:0 auto">
      <div class="kpi"><div class="l">${icon('cards', 'sm')}Cards</div><div class="v">${fmtNum(st.total)}</div></div>
      <div class="kpi"><div class="l">${icon('clock', 'sm')}Due now</div><div class="v">${fmtNum(st.due)}</div></div>
      <div class="kpi"><div class="l">${icon('check', 'sm')}Reviewed today</div><div class="v">${fmtNum(st.reviewed_today)}</div></div>
      <div class="kpi"><div class="l">${icon('layers', 'sm')}Mastery boxes</div><div class="boxes" style="margin-top:6px">${Object.entries(st.boxes).map(([b, n]) => `<div style="height:${Math.max(4, n / maxBox * 100)}%" title="Box ${b}: ${n} cards"><span>${b}</span></div>`).join('')}</div></div>
    </div><div id="deck"></div>`;
  renderCard();
}
function renderCard() {
  const el = $('#deck'); if (!el) return;
  const c = deck[deckIdx];
  if (!c) {
    el.innerHTML = `<div class="empty" style="padding:60px">${icon('check', 'lg')}<br><b style="color:var(--text);font-size:15px">All caught up!</b><br>${deck.length ? 'Great session.' : 'No cards are due. Generate cards from your knowledge with the buttons above.'}</div>`;
    return;
  }
  el.innerHTML = `<div class="muted" style="text-align:center;margin-top:18px;font-size:12px">Card ${deckIdx + 1} of ${deck.length} · box ${c.box}${c.source ? ' · from ' + esc(c.source) : ''}</div>
    <div class="flash-wrap"><div class="flash" id="flash"><div class="face front"><div class="q">${esc(c.question)}</div><div class="hint">Click or press Space to reveal</div></div>
      <div class="face back"><div class="a">${esc(c.answer)}</div><div class="hint">How well did you know it?</div></div></div></div>
    <div class="grades" id="grades" style="visibility:hidden">
      <button class="btn danger-outline" data-g="again">Again<small>1 · now</small></button><button class="btn" data-g="hard">Hard<small>2 · same box</small></button>
      <button class="btn" data-g="good">Good<small>3 · next box</small></button><button class="btn primary" data-g="easy">Easy<small>4 · skip ahead</small></button></div>
    <div style="text-align:center;margin-top:10px"><button class="btn ghost sm danger" id="cardDel">${icon('trash', 'sm')}Delete card</button>${c.entity_id ? ` <button class="btn ghost sm" data-e="${c.entity_id}">${icon('bulb', 'sm')}Open concept</button>` : ''}</div>`;
  $('#flash').onclick = flipCard;
  $$('#grades [data-g]').forEach(b => b.onclick = () => gradeCard(b.dataset.g));
  $('#cardDel').onclick = async () => { await api('card_delete', {id: c.id}); deck.splice(deckIdx, 1); renderCard(); };
  $$('#deck [data-e]').forEach(b => b.onclick = () => openEntity(+b.dataset.e));
}
function flipCard() { const f = $('#flash'); if (!f) return; f.classList.toggle('flipped'); $('#grades').style.visibility = 'visible'; }
async function gradeCard(g) {
  const c = deck[deckIdx]; if (!c) return;
  await api('card_review', {id: c.id, grade: g});
  if (g === 'again') deck.push(c);
  deckIdx++; renderCard(); refreshStatus();
}
document.addEventListener('keydown', e => {
  if (S.view !== 'practice' || e.target.matches('input,textarea,select')) return;
  if (e.code === 'Space') { e.preventDefault(); flipCard(); }
  const g = {'1': 'again', '2': 'hard', '3': 'good', '4': 'easy'}[e.key];
  if (g && $('#flash')?.classList.contains('flipped')) { e.stopImmediatePropagation(); gradeCard(g); }
}, true);
$('#btnCardsConcepts').onclick = async () => { const r = await api('cards_generate', {source: 'concepts'}); toast(`${r.created} new card(s) from concepts`, 'ok'); loadPractice(); };
$('#btnCardsSources').onclick = async () => {
  const b = $('#btnCardsSources'); b.disabled = true; setWork('Writing flashcards from your sources…', true);
  try { const r = await api('cards_generate', {source: 'sources'}); toast(`${r.created} new AI flashcard(s)`, 'ok'); loadPractice(); } catch (e) { toast(esc(e.message), 'bad'); }
  b.disabled = false; setWork();
};

// ---------------------------------------------------------------- Quality (Phase 6)
function scoreChart(runs) {
  if (!runs.length) return '<div class="empty">No benchmark runs yet. Add test questions below, then click "Run benchmark".</div>';
  const W = 600, H = 160, P = 28, n = runs.length;
  const x = i => n === 1 ? W / 2 : P + i * (W - 2 * P) / (n - 1), y = v => H - P - v * (H - 2 * P);
  const pts = runs.map((r, i) => `${x(i)},${y(+r.avg_score)}`).join(' ');
  return `<svg class="score-chart" viewBox="0 0 ${W} ${H}" preserveAspectRatio="none">
    ${[0, .5, 1].map(v => `<line x1="${P}" x2="${W - P}" y1="${y(v)}" y2="${y(v)}" stroke="var(--line)"/><text x="4" y="${y(v) + 4}" fill="var(--text-3)" font-size="10">${v * 100}%</text>`).join('')}
    <polyline points="${pts}" fill="none" stroke="#38bdf8" stroke-width="2.5"/>
    ${runs.map((r, i) => `<circle cx="${x(i)}" cy="${y(+r.avg_score)}" r="4" fill="#38bdf8"><title>${esc(r.created_at)}: ${pctTxt(r.avg_score)} (${r.passed}/${r.total} passed, ${r.knowledge_size} passages)</title></circle>`).join('')}
  </svg>`;
}
async function loadQuality() {
  const box = $('#quality');
  let d; try { d = await api('quality'); } catch (e) { box.innerHTML = `<div class="alert critical">${esc(e.message)}</div>`; return; }
  const last = d.runs[d.runs.length - 1], prev = d.runs[d.runs.length - 2];
  const delta = last && prev ? Math.round((last.avg_score - prev.avg_score) * 100) : null;
  box.innerHTML = `
    <div class="kpis">
      <div class="kpi"><div class="l">${icon('target', 'sm')}Benchmark score</div><div class="v">${last ? pctTxt(last.avg_score) : '—'}</div>${delta !== null ? `<div class="d" style="color:${delta >= 0 ? 'var(--ok)' : 'var(--bad)'}">${delta >= 0 ? '▲' : '▼'} ${Math.abs(delta)} pts vs last run</div>` : ''}</div>
      <div class="kpi"><div class="l">${icon('check', 'sm')}Tests passed</div><div class="v">${last ? `${last.passed}/${last.total}` : '—'}</div></div>
      <div class="kpi"><div class="l">${icon('sparkle', 'sm')}Self-quiz score</div><div class="v">${d.quiz_stats.n ? pctTxt(d.quiz_stats.avg_score) : '—'}</div><div class="d" style="color:var(--text-3)">${d.quiz_stats.n} questions</div></div>
      <div class="kpi"><div class="l">${icon('search', 'sm')}Retrieval accuracy</div><div class="v">${d.quiz_stats.n ? pctTxt(d.quiz_stats.retrieval) : '—'}</div><div class="d" style="color:var(--text-3)">right passage found</div></div>
      <div class="kpi"><div class="l">${icon('thumbUp', 'sm')}Approved answers</div><div class="v">${d.approved.length}</div></div>
    </div>
    <div class="card" style="margin-top:12px"><h4>Is Mnemo getting smarter? <span>benchmark score over time</span></h4>${scoreChart(d.runs)}</div>
    <div class="grid2b">
      <div class="card"><h4>Benchmark questions <span>${d.tests.length}</span></h4>
        <div style="display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
          <input class="input" id="gtQ" placeholder="Question, e.g. How many neurons are in the human brain?">
          <div style="display:flex;gap:6px"><input class="input" id="gtA" placeholder="Expected answer, e.g. About 86 billion" style="flex:1"><button class="btn primary" id="gtAdd">${icon('plus', 'sm')}Add</button></div>
        </div>
        ${d.tests.map(t => `<div class="qrow" style="${t.active == 1 ? '' : 'opacity:.5'}"><div><b style="font-weight:500">${esc(t.question)}</b><div class="muted">Expected: ${esc(t.expected)}</div></div>
          <div>${t.last_score !== null ? `<span class="scorepill ${scoreClass(+t.last_score)}" title="${esc((t.last_answer || '').slice(0, 300))}">${pctTxt(t.last_score)}</span>` : '<span class="muted">—</span>'}</div>
          <div style="text-align:right"><button class="btn ghost sm icon" data-gt-toggle="${t.id}" title="${t.active == 1 ? 'Pause' : 'Activate'}">${icon(t.active == 1 ? 'eye' : 'x', 'sm')}</button><button class="btn ghost sm icon danger" data-gt-del="${t.id}">${icon('trash', 'sm')}</button></div></div>`).join('') || '<div class="empty">Add 20–50 questions you know the answers to. This is your measurable result.</div>'}
      </div>
      <div class="card"><h4>Self-quiz · Mnemo tests itself <span>weakest first</span></h4>
        ${[...d.quiz].sort((a, b) => a.score - b.score).slice(0, 25).map(q => `<div class="qrow"><div><b style="font-weight:500">${esc(q.question)}</b><div class="muted">Expected: ${esc(q.expected)}${q.title ? ' · ' + esc(q.title) : ''}</div></div>
          <div><span class="scorepill ${scoreClass(+q.score)}">${pctTxt(q.score)}</span>${q.retrieved == 1 ? '' : ` <span title="The source passage was not retrieved: a retrieval weak spot">${icon('alert', 'sm')}</span>`}</div>
          <div style="text-align:right"><button class="btn ghost sm" data-promote="${q.id}" title="Add to benchmark">${icon('plus', 'sm')}Test</button></div></div>`).join('') || '<div class="empty">Runs every sleep cycle, or click "Self-quiz now".</div>'}
      </div>
    </div>
    <div class="card" style="margin-top:12px"><h4>Approved answers <span>reused when a similar question is asked</span></h4>
      ${d.approved.map(a => `<div class="qrow" style="grid-template-columns:1fr 60px 40px"><div><b style="font-weight:500">${esc(a.question)}</b><div class="muted">${esc(a.answer.slice(0, 220))}…</div></div><div class="muted">${a.uses} uses</div>
        <div style="text-align:right"><button class="btn ghost sm icon danger" data-ap-del="${a.id}">${icon('trash', 'sm')}</button></div></div>`).join('') || '<div class="empty">Click 👍 on a good answer in Ask Mnemo to store it here.</div>'}
    </div>`;
  $('#gtAdd').onclick = async () => { try { await api('golden_add', {question: $('#gtQ').value, expected: $('#gtA').value}); toast('Test question added', 'ok'); loadQuality(); } catch (e) { toast(esc(e.message), 'bad'); } };
  $$('[data-gt-del]', box).forEach(b => b.onclick = async () => { if (confirm('Delete this test question and its results?')) { await api('golden_delete', {id: +b.dataset.gtDel}); loadQuality(); } });
  $$('[data-gt-toggle]', box).forEach(b => b.onclick = async () => { await api('golden_toggle', {id: +b.dataset.gtToggle}); loadQuality(); });
  $$('[data-promote]', box).forEach(b => b.onclick = async () => { await api('quiz_promote', {id: +b.dataset.promote}); toast('Added to benchmark', 'ok'); loadQuality(); });
  $$('[data-ap-del]', box).forEach(b => b.onclick = async () => { await api('approved_delete', {id: +b.dataset.apDel}); loadQuality(); });
}
$('#btnRunTests').onclick = async () => {
  const b = $('#btnRunTests'); b.disabled = true; setWork('Running benchmark…', true);
  try { const r = await api('golden_run', {}); toast(r.total ? `Benchmark: ${r.passed}/${r.total} passed · ${pctTxt(r.avg_score)}` : 'Add some test questions first', r.total ? 'ok' : ''); loadQuality(); }
  catch (e) { toast(esc(e.message), 'bad'); }
  b.disabled = false; setWork();
};
$('#btnSelfQuiz').onclick = async () => {
  const b = $('#btnSelfQuiz'); b.disabled = true; setWork('Mnemo is quizzing itself…', true);
  try { const r = await api('self_quiz_run', {n: 5}); const ok = r.results.filter(x => !x.error); toast(ok.length ? `Self-quiz: ${ok.length} questions, average ${pctTxt(ok.reduce((a, x) => a + x.score, 0) / ok.length)}` : 'No new passages to quiz on', 'ok'); loadQuality(); }
  catch (e) { toast(esc(e.message), 'bad'); }
  b.disabled = false; setWork();
};

// ---------------------------------------------------------------- Watchlist (Phase 7)
async function loadWatchlist() {
  const list = await api('watch_list');
  $('#watchList').innerHTML = list.map(w => {
    const src = w.sources ? JSON.parse(w.sources) : [];
    return `<div class="watch-item ${w.unread == 1 ? 'unread' : ''}" data-wid="${w.id}">
      <div class="wq">${icon('eye', 'sm')}<span style="flex:1">${esc(w.question)}</span>${w.unread == 1 ? '<span class="badge accent">updated</span>' : ''}
        <button class="btn ghost sm icon" data-wcheck="${w.id}" title="Re-check now">${icon('refresh', 'sm')}</button><button class="btn ghost sm icon danger" data-wdel="${w.id}">${icon('trash', 'sm')}</button></div>
      <div class="wa content" style="font-size:13px">${w.answer ? md(w.answer, src) : '<span class="muted">Not answered yet</span>'}</div>
      <div class="muted" style="font-size:11.5px;margin-top:6px">${w.versions} version(s) · checked ${ago(w.checked_at)}${w.changed_at ? ' · changed ' + ago(w.changed_at) : ''}</div></div>`;
  }).join('') || '<div class="empty">No standing questions yet.</div>';
  $$('#watchList [data-wdel]').forEach(b => b.onclick = async () => { await api('watch_delete', {id: +b.dataset.wdel}); loadWatchlist(); });
  $$('#watchList [data-wcheck]').forEach(b => b.onclick = async () => { b.disabled = true; await api('watch_check', {id: +b.dataset.wcheck, force: true}); loadWatchlist(); });
  for (const w of list.filter(x => x.unread == 1)) api('watch_read', {id: w.id});
  setTimeout(refreshStatus, 400);
}
$('#btnWatchlist').onclick = () => { $('#watchModal').classList.add('show'); loadWatchlist(); };
$('#watchAdd').onclick = async () => {
  const q = $('#watchInput').value.trim(); if (!q) return;
  $('#watchAdd').disabled = true;
  try { await api('watch_add', {question: q}); $('#watchInput').value = ''; loadWatchlist(); } catch (e) { toast(esc(e.message), 'bad'); }
  $('#watchAdd').disabled = false;
};
$('#watchCheckAll').onclick = async () => { const r = await api('watch_check', {}); toast(`${r.updated} answer(s) changed`, 'ok'); loadWatchlist(); };

// ---------------------------------------------------------------- Search by image (Phase 8)
$('#btnImageSearch').onclick = () => $('#imageSearchFile').click();
$('#imageSearchFile').onchange = async e => {
  const f = e.target.files[0]; e.target.value = ''; if (!f) return;
  const fd = new FormData(); fd.append('image', f);
  setWork('Looking at your image…', true);
  try {
    const r = await (await fetch('api/index.php?action=image_search', {method: 'POST', body: fd})).json();
    if (r.error) throw new Error(r.error);
    $('#app').classList.remove('no-insp');
    setInsp('Image search', `<div class="card"><img src="${URL.createObjectURL(f)}" style="width:100%;border-radius:8px;border:1px solid var(--line)" alt="">
        ${r.description ? `<div style="font-size:12.5px;color:var(--text-2);margin-top:8px">${esc(r.description.slice(0, 400))}</div>` : '<div class="muted" style="font-size:12px;margin-top:8px">No vision model: matched by visual similarity and text only.</div>'}</div>
      ${r.images.length ? `<div class="card"><h4>Visually similar images</h4><div style="display:grid;grid-template-columns:repeat(3,1fr);gap:6px">${r.images.map(i => `<div data-d="${i.id}" style="cursor:pointer" title="${esc(i.title)} · ${pctTxt(i.score)}"><img src="api/index.php?action=file&id=${i.id}" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:6px;border:1px solid var(--line)" alt=""></div>`).join('')}</div></div>` : ''}
      ${r.entities.length ? `<div class="card"><h4>Related concepts</h4><div class="chips">${r.entities.map(x => `<span class="badge" style="cursor:pointer" data-e="${x.id}">${esc(x.name)}</span>`).join('')}</div></div>` : ''}
      <div class="card"><h4>Related passages <span>${r.passages.length}</span></h4>${r.passages.map(p => `<div class="snip" data-c="${p.chunk_id}">${esc(p.snippet)}…<span class="src">${icon(p.kind === 'image' ? 'image' : 'doc', 'sm')} ${esc(p.title)}${p.page ? ' · p.' + p.page : ''}</span></div>`).join('') || '<div class="empty">Nothing related found</div>'}</div>`);
  } catch (err) { toast('Image search failed: ' + esc(err.message), 'bad'); }
  setWork();
};

// ---------------------------------------------------------------- Memory cards
const STAGE_EMOJI = s => /Newborn/.test(s) ? '👶' : /Infant/.test(s) ? '🍼' : /Toddler/.test(s) ? '🧸' : /Child/.test(s) ? '🧒' : /Student/.test(s) ? '🎒' : /Graduate/.test(s) ? '🎓' : /Scholar/.test(s) ? '📚' : /Expert/.test(s) ? '🔬' : /Master/.test(s) ? '🏅' : /Sage/.test(s) ? '🦉' : '🌌';
let lastLevelToast = 0;
function renderIdentity(st) {
  if (st.card) { $('#cardName').textContent = st.card.name; $('#cardPill').title = `Memory card: ${st.card.name} (${st.card.count} on this PC). Click to switch, insert or eject.`; }
  if (st.growth) {
    $('#growText').textContent = `Lv ${st.growth.level} · ${st.growth.stage}`;
    const lu = st.growth.level_up;
    if (lu && lu.level !== lastLevelToast && lu.level > 0) {
      lastLevelToast = lu.level;
      toast(`${STAGE_EMOJI(lu.stage)} <b>Mnemo grew!</b> Level ${lu.level}${lu.stage_changed ? ` · now a <b>${esc(lu.stage)}</b>` : ''}`, 'ok');
      if (S.view === 'grow') loadGrow();
    }
  }
}
$('#growPill').onclick = () => setView('grow');
$('#cardPill').onclick = async e => {
  e.stopPropagation();
  const m = $('#cardMenu');
  if (!m.hidden) { m.hidden = true; return; }
  const d = await api('memory_cards');
  m.innerHTML = `<div class="sec-h" style="cursor:default">Memory cards on this PC</div>` +
    d.cards.map(c => `<div class="cm-item ${c.active ? 'active' : ''}" data-sw="${esc(c.id)}">${icon('sdcard')}<div style="flex:1;min-width:0"><b>${esc(c.name)}</b><span class="muted">${c.error ? esc(c.error) : `${fmtNum(c.sources)} sources · ${fmtNum(c.concepts)} concepts · ${fmtBytes(c.db_bytes)}`}</span></div>${c.active ? '<span class="badge ok">inserted</span>' : ''}</div>`).join('') +
    `<div class="cm-sep"></div>
     <div class="cm-item" data-cm="insert">${icon('upload')}<div><b>Insert a card file…</b><span class="muted">Mnemo instantly knows what's on it</span></div></div>
     <div class="cm-item" data-cm="eject">${icon('download')}<div><b>Eject this card…</b><span class="muted">Save it to a file, optionally remove it</span></div></div>
     <div class="cm-item" data-cm="blank">${icon('plus')}<div><b>New blank card</b><span class="muted">A newborn Mnemo with zero knowledge</span></div></div>
     <div class="cm-item" data-cm="manage">${icon('database')}<div><b>Manage cards</b></div></div>`;
  m.hidden = false;
  $$('[data-sw]', m).forEach(el => el.onclick = () => { if (!el.classList.contains('active')) switchCard(el.dataset.sw); });
  $$('[data-cm]', m).forEach(el => el.onclick = () => { m.hidden = true; ({insert: openInsert, eject: () => openEject(), blank: newBlankCard, manage: () => setView('memory')})[el.dataset.cm](); });
};
document.addEventListener('click', e => { if (!e.target.closest('#cardMenu')) $('#cardMenu').hidden = true; });

async function switchCard(id) {
  setWork('Switching memory card…', true);
  try { await api('card_switch', {id}); location.reload(); } catch (e) { toast(esc(e.message), 'bad'); setWork(); }
}
async function newBlankCard() {
  const name = prompt('Name for the new blank memory card (Mnemo starts as a newborn on it):', 'New memory'); if (!name) return;
  try { await api('card_create', {name}); location.reload(); } catch (e) { toast(esc(e.message), 'bad'); }
}

// insert
let cardFileSel = null;
function openInsert() {
  cardFileSel = null; $('#cardLabel').innerHTML = ''; $('#cardInsertGo').disabled = true;
  $('#insertModal').classList.add('show');
}
$('#cardDrop').onclick = () => $('#cardFile').click();
['dragenter', 'dragover'].forEach(ev => $('#cardDrop').addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); $('#cardDrop').classList.add('over'); }));
['dragleave', 'drop'].forEach(ev => $('#cardDrop').addEventListener(ev, e => { e.preventDefault(); e.stopPropagation(); $('#cardDrop').classList.remove('over'); }));
$('#cardDrop').addEventListener('drop', e => pickCard(e.dataTransfer.files[0]));
$('#cardFile').onchange = e => { pickCard(e.target.files[0]); e.target.value = ''; };
async function pickCard(f) {
  if (!f) return;
  const lim = S.status?.storage?.upload_limit || 0;
  if (lim && f.size > lim) { $('#cardLabel').innerHTML = `<div class="alert warn">${icon('alert', 'sm')}<div>This card is ${fmtBytes(f.size)}, larger than the ${fmtBytes(lim)} upload limit. Raise upload_max_filesize and post_max_size in php.ini (see Memory → Limits), then try again.</div></div>`; return; }
  const fd = new FormData(); fd.append('card', f); fd.append('inspect', '1');
  try {
    const r = await (await fetch('api/index.php?action=card_import', {method: 'POST', body: fd})).json();
    if (r.error) throw new Error(r.error);
    cardFileSel = f;
    const m = r.manifest || {};
    $('#cardLabel').innerHTML = `<div class="mcard active" style="margin-top:4px"><div class="nm">${icon('sdcard')}${esc(r.name)} ${r.encrypted ? '<span class="badge warn">password-protected</span>' : ''}</div>
        <div class="st">${m.sources !== undefined ? `<span>${fmtNum(m.sources)} sources</span><span>${fmtNum(m.concepts)} concepts</span><span>${fmtNum(m.links)} links</span><span>${fmtNum(m.taught)} taught facts</span>` : '<span>Full backup file</span>'}<span>${fmtBytes(f.size)}</span></div>
        ${m.exported ? `<div class="muted" style="font-size:11.5px">Saved ${esc(m.exported.slice(0, 10))}</div>` : ''}</div>
      <label class="fld" style="margin-top:10px"><span>Card name on this PC</span><input class="input" id="cardNewName" value="${esc(r.name)}"></label>
      ${r.encrypted ? `<label class="fld" style="margin-top:8px"><span>Password</span><input class="input" type="password" id="cardPass" autocomplete="off"></label>` : ''}
      <div class="muted" style="font-size:12px;margin-top:8px">The card is added next to your other cards and becomes the active memory. Nothing is overwritten.</div>`;
    $('#cardInsertGo').disabled = false;
  } catch (e) { $('#cardLabel').innerHTML = `<div class="alert critical">${icon('alert', 'sm')}<div>${esc(e.message)}</div></div>`; }
}
$('#cardInsertGo').onclick = async () => {
  if (!cardFileSel) return;
  const fd = new FormData(); fd.append('card', cardFileSel); fd.append('name', $('#cardNewName').value); if ($('#cardPass')) fd.append('password', $('#cardPass').value);
  $('#cardInsertGo').disabled = true; setWork('Reading the memory card…', true);
  try {
    const r = await (await fetch('api/index.php?action=card_import', {method: 'POST', body: fd})).json();
    if (r.error) throw new Error(r.error);
    location.reload();
  } catch (e) { toast('Insert failed: ' + esc(e.message), 'bad'); $('#cardInsertGo').disabled = false; setWork(); }
};

// eject
let ejectId = null, ejectName = '';
function openEject(id, name) {
  ejectId = id || S.status?.card?.id; ejectName = name || S.status?.card?.name || 'this card';
  $('#ejectWhat').innerHTML = `Save <b style="color:var(--text)">${esc(ejectName)}</b> as a card file you can keep, move to another PC, or insert again later.`;
  $('#ejectPass').value = ''; $('#ejectRemove').checked = false;
  $('#ejectModal').classList.add('show');
}
$('#ejectGo').onclick = async () => {
  const b = $('#ejectGo'); b.disabled = true; setWork('Writing the card file…', true);
  try {
    const r = await api('card_export', {id: ejectId, password: $('#ejectPass').value});
    const a = document.createElement('a');
    a.href = `api/index.php?action=card_download&token=${r.token}&name=${encodeURIComponent(r.filename)}`; a.download = r.filename; document.body.appendChild(a); a.click(); a.remove();
    $('#ejectModal').classList.remove('show');
    toast(`Card file saved: <b>${esc(r.filename)}</b> (check your Downloads folder)`, 'ok');
    if ($('#ejectRemove').checked) {
      setTimeout(async () => {
        if (!confirm(`Did "${r.filename}" finish downloading?\n\nClick OK to REMOVE "${ejectName}" from this PC. Mnemo will forget it until you insert the card file again.`)) return;
        await api('card_remove', {id: ejectId, confirm: 'DELETE'}); location.reload();
      }, 1500);
    } else if (S.view === 'memory') loadMemory();
  } catch (e) { toast('Eject failed: ' + esc(e.message), 'bad'); }
  b.disabled = false; setWork();
};

async function renderMemoryCards() {
  const host = $('#memCards'); if (!host) return;
  const d = await api('memory_cards');
  host.innerHTML = `<div class="mcards">${d.cards.map(c => `<div class="mcard ${c.active ? 'active' : ''}">
      <div class="nm">${icon('sdcard')}<span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(c.name)}</span>${c.active ? '<span class="badge ok">inserted</span>' : ''}</div>
      <div class="st">${c.error ? `<span style="color:var(--bad)">${esc(c.error)}</span>` : `<span>${fmtNum(c.sources)} sources</span><span>${fmtNum(c.concepts)} concepts</span><span>${fmtNum(c.taught)} taught</span><span>${fmtBytes(c.db_bytes)}</span>`}</div>
      <div class="acts">${c.active ? '' : `<button class="btn sm primary" data-c-sw="${esc(c.id)}">Use this card</button>`}
        <button class="btn sm" data-c-ej="${esc(c.id)}" data-n="${esc(c.name)}">${icon('download', 'sm')}Eject…</button>
        <button class="btn sm ghost icon" data-c-rn="${esc(c.id)}" data-n="${esc(c.name)}" title="Rename">${icon('type', 'sm')}</button>
        <button class="btn sm ghost icon danger" data-c-rm="${esc(c.id)}" data-n="${esc(c.name)}" title="${c.builtin ? 'Erase everything on this card' : 'Remove from this PC without saving'}">${icon('trash', 'sm')}</button></div></div>`).join('')}
    <div class="mcard new"><div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:center"><button class="btn sm primary" id="mcInsert">${icon('upload', 'sm')}Insert card file</button><button class="btn sm" id="mcBlank">${icon('plus', 'sm')}New blank card</button></div>
      <span class="muted" style="font-size:11.5px;text-align:center">Each card is its own MySQL database. One is active at a time.</span></div></div>`;
  $('#mcInsert').onclick = openInsert; $('#mcBlank').onclick = newBlankCard;
  $$('[data-c-sw]', host).forEach(b => b.onclick = () => switchCard(b.dataset.cSw));
  $$('[data-c-ej]', host).forEach(b => b.onclick = () => openEject(b.dataset.cEj, b.dataset.n));
  $$('[data-c-rn]', host).forEach(b => b.onclick = async () => { const n = prompt('Rename memory card:', b.dataset.n); if (n) { await api('card_rename', {id: b.dataset.cRn, name: n}); renderMemoryCards(); refreshStatus(); } });
  $$('[data-c-rm]', host).forEach(b => b.onclick = async () => {
    const t = prompt(`This permanently deletes the memory "${b.dataset.n}" from this PC WITHOUT saving a card file.\nUse "Eject" first if you want to keep it.\n\nType DELETE to confirm:`);
    if (t !== 'DELETE') return;
    try { await api('card_remove', {id: b.dataset.cRm, confirm: 'DELETE'}); location.reload(); } catch (e) { toast(esc(e.message), 'bad'); }
  });
}

// ---------------------------------------------------------------- Grow (baby mode)
async function loadGrow() {
  const box = $('#growBody');
  let d; try { d = await api('growth'); } catch (e) { box.innerHTML = `<div class="alert critical">${esc(e.message)}</div>`; return; }
  const s = d.status, maxXp = Math.max(1, ...s.breakdown.map(b => b.xp));
  box.innerHTML = `
    <div class="grow-hero"><div class="grow-avatar">${STAGE_EMOJI(s.stage)}</div>
      <div style="flex:1;min-width:0"><div class="lv">Level ${s.level}</div><h3>${esc(s.stage)}</h3>
        <div class="xpbar"><i style="width:${Math.max(2, s.progress * 100)}%"></i></div>
        <div class="muted" style="font-size:12px">${fmtNum(s.xp)} XP · ${fmtNum(s.xp_next - s.xp)} XP to level ${s.level + 1}${s.next_stage ? ` · becomes a <b style="color:var(--text)">${esc(s.next_stage.name)}</b> at level ${s.next_stage.level}` : ' · growing without limit'}</div></div>
      <div style="text-align:right;font-size:12px;color:var(--text-2)">${s.accuracy !== null ? `<div style="font-size:22px;font-weight:650;color:var(--text)">${pctTxt(s.accuracy)}</div>benchmark accuracy` : '<span class="muted">Add benchmark questions<br>in Quality to measure accuracy</span>'}</div>
    </div>
    <div class="grid2" style="grid-template-columns:1.4fr 1fr">
      <div>
        <div class="card"><h4>Mnemo asks you <span>${d.questions.length} open</span></h4>
          <div class="muted" style="font-size:12px;margin-bottom:10px">Like a curious child, Mnemo asks about things it has heard of but doesn't understand. Your answers become top-trust knowledge.</div>
          ${d.questions.map(q => `<div class="cq" data-q="${q.id}"><div class="q">${icon('help', 'sm')}<span style="flex:1">${esc(q.question)}</span>${q.entity_id ? `<button class="btn ghost sm icon" data-e="${q.entity_id}" title="Open concept">${icon('bulb', 'sm')}</button>` : ''}</div>
              <div class="c">${esc(q.context || '')}</div><textarea class="input" placeholder="Explain it in your own words…"></textarea>
              <div style="display:flex;gap:6px;margin-top:6px"><button class="btn sm primary" data-ans="${q.id}">${icon('check', 'sm')}Teach Mnemo</button><button class="btn sm ghost" data-skip="${q.id}">Skip</button></div></div>`).join('')
            || `<div class="empty">No questions right now. Click “What are you curious about?” or wait for the next sleep cycle.</div>`}
          ${d.answered.length ? `<div class="muted" style="font-size:11px;text-transform:uppercase;letter-spacing:.6px;margin:12px 0 4px">Recently answered</div>${d.answered.slice(0, 5).map(q => `<div class="diary"><b>${esc(q.question)}</b> ${esc((q.answer || '').slice(0, 160))}</div>`).join('')}` : ''}
        </div>
        <div class="card"><h4>Lessons <span>teach, then examine</span></h4>
          <input class="input" id="lsTitle" placeholder="Lesson title, e.g. Colours, Photosynthesis, Our company products" style="width:100%;margin-bottom:6px">
          <textarea class="input" id="lsBody" style="width:100%;min-height:96px;height:auto;padding:8px 10px;resize:vertical;font:13px/1.5 var(--font)" placeholder="One fact per line. Start simple, like teaching a child:&#10;Red = the colour of blood and ripe tomatoes&#10;The sky is blue during the day&#10;Plants need sunlight and water to grow"></textarea>
          <div style="display:flex;gap:8px;align-items:center;margin-top:6px"><button class="btn primary sm" id="lsTeach">${icon('school', 'sm')}Teach this lesson</button><span class="muted" style="font-size:12px">“Term = meaning” lines become definitions. Up to 40 lines.</span></div>
          <div style="margin-top:12px">${d.lessons.map(l => `<div class="list-row" style="cursor:default;align-items:flex-start;flex-wrap:wrap" data-lesson="${l.id}">
              ${l.grade ? `<span class="grade ${l.grade}">${l.grade}</span>` : `<span class="grade" style="background:var(--panel-3);color:var(--text-3)">–</span>`}
              <div style="flex:1;min-width:0"><b style="color:var(--text);font-weight:600">${esc(l.title)}</b><div class="muted" style="font-size:12px">${l.items} facts · ${l.exams ? `examined ${l.exams}× · last ${pctTxt(l.last_score)}` : 'not examined yet'}</div></div>
              <button class="btn sm" data-exam="${l.id}">${icon('target', 'sm')}Examine Mnemo</button><button class="btn sm ghost icon danger" data-ldel="${l.id}" title="Remove lesson (the facts stay in memory)">${icon('trash', 'sm')}</button>
              ${l.last_exam ? `<div style="flex-basis:100%;margin-top:6px">${l.last_exam.map(r => `<div class="qrow" style="grid-template-columns:1fr 60px"><div>${esc(r.question)}<div class="muted">Expected: ${esc(r.expected)}</div></div><span class="scorepill ${scoreClass(r.score)}">${pctTxt(r.score)}</span></div>`).join('')}</div>` : ''}</div>`).join('') || '<div class="empty">No lessons yet.</div>'}</div>
        </div>
      </div>
      <div>
        <div class="card"><h4>Where the experience comes from</h4>${s.breakdown.map(b => `<div class="hbar"><span class="n" style="width:150px" title="${esc(b.label)}">${esc(b.label)}</span><span class="t"><i style="width:${b.xp / maxXp * 100}%;background:#34d399"></i></span><span class="c" style="width:64px">${fmtNum(b.xp)} XP</span></div>`).join('')}
          <div class="muted" style="font-size:11.5px;margin-top:8px">Levels never end: every source, fact, answer and decision adds experience.</div></div>
        <div class="card"><h4>Growth diary</h4>${d.diary.map(e => `<div class="diary"><b>${esc(e.day)}</b> · ${STAGE_EMOJI(e.stage)} Lv ${e.level}<br>${esc(e.entry)}</div>`).join('') || '<div class="empty">Mnemo writes its first entry after a sleep cycle.</div>'}</div>
        <div class="card"><h4>How Mnemo learns</h4>
          <label class="toggle-row"><input type="checkbox" id="babyStrict" ${s.strict ? 'checked' : ''}><span><b>Strict mode (recommended)</b>Mnemo only knows what you taught it. With nothing in memory about a question it answers “I don't know that yet”.</span></label>
          <label class="toggle-row"><input type="checkbox" id="babyForget" ${s.forgetting ? 'checked' : ''}><span><b>Forgetting</b>Weak links that were never confirmed slowly fade after 30 days. Off: Mnemo never forgets unless you delete.</span></label>
        </div>
      </div>
    </div>`;
  $$('#growBody [data-ans]').forEach(b => b.onclick = async () => {
    const cq = b.closest('.cq'), txt = $('textarea', cq).value.trim(); if (!txt) { $('textarea', cq).focus(); return; }
    b.disabled = true;
    try { await api('curiosity_answer', {id: +b.dataset.ans, answer: txt}); toast('Mnemo learned that. Thank you!', 'ok'); refreshStatus(); loadGrow(); loadTree(); } catch (e) { toast(esc(e.message), 'bad'); b.disabled = false; }
  });
  $$('#growBody [data-skip]').forEach(b => b.onclick = async () => { await api('curiosity_skip', {id: +b.dataset.skip}); b.closest('.cq').remove(); refreshStatus(); });
  $$('#growBody [data-e]').forEach(b => b.onclick = () => openEntity(+b.dataset.e));
  $('#lsTeach').onclick = async () => {
    const b = $('#lsTeach'); b.disabled = true; setWork('Mnemo is studying the lesson…', true);
    try { const r = await api('lesson_teach', {title: $('#lsTitle').value, content: $('#lsBody').value}); toast(`Lesson taught: ${r.items} facts. Now examine Mnemo on it!`, 'ok'); refreshAll(); loadGrow(); }
    catch (e) { toast(esc(e.message), 'bad'); }
    b.disabled = false; setWork();
  };
  $$('#growBody [data-exam]').forEach(b => b.onclick = async () => {
    b.disabled = true; setWork('Exam in progress…', true);
    try { const r = await api('lesson_exam', {id: +b.dataset.exam}); toast(`Exam result: grade <b>${r.grade}</b> (${pctTxt(r.score)})`, r.score >= 0.7 ? 'ok' : ''); loadGrow(); }
    catch (e) { toast(esc(e.message), 'bad'); }
    b.disabled = false; setWork();
  });
  $$('#growBody [data-ldel]').forEach(b => b.onclick = async () => { if (confirm('Remove this lesson from the list? The facts Mnemo learned stay in memory.')) { await api('lesson_delete', {id: +b.dataset.ldel}); loadGrow(); } });
  $('#babyStrict').onchange = e => api('baby_settings', {strict: e.target.checked}).then(() => toast('Saved', 'ok'));
  $('#babyForget').onchange = e => api('baby_settings', {forgetting: e.target.checked}).then(() => toast('Saved', 'ok'));
}
$('#btnWonder').onclick = async () => { const r = await api('wonder', {}); toast(r.new ? `Mnemo has ${r.new} new question(s) for you` : 'Mnemo has no new questions right now', r.new ? 'ok' : ''); loadGrow(); refreshStatus(); };

// ---------------------------------------------------------------- Neuron preview (click a dot in the graph)
const pv = {stack: [], entity: null, srcIdx: 0};
function pvEnsure() {
  let m = $('#pvModal');
  if (m) return m;
  m = document.createElement('div');
  m.className = 'modal-bg';
  m.id = 'pvModal';
  m.innerHTML = '<div class="pv" id="pvBox"></div>';
  document.body.appendChild(m);
  m.addEventListener('click', e => { if (e.target === m) pvClose(); });
  return m;
}
function pvClose() { const m = $('#pvModal'); if (m) m.classList.remove('show'); pv.stack = []; }
const pvRe = s => s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
function pvHighlight(text, e) {
  const names = [e.name, ...(e.aliases || [])].filter(n => n && n.length > 1).sort((a, b) => b.length - a.length);
  let h = esc(text);
  if (!names.length) return h;
  const re = new RegExp('(' + names.map(n => pvRe(esc(n))).join('|') + ')', 'gi');
  return h.replace(re, '<mark>$1</mark>');
}
const pvIsPdf = s => /pdf/i.test(s.mime || '') || /\.pdf$/i.test(s.filename || '');
const pvIsImg = s => s.kind === 'image' || /^image\//i.test(s.mime || '');

async function openPreview(id, push = true) {
  const m = pvEnsure();
  if (push && pv.entity && pv.entity.id !== id && m.classList.contains('show')) pv.stack.push(pv.entity.id);
  if (!m.classList.contains('show')) pv.stack = [];
  m.classList.add('show');
  $('#pvBox').innerHTML = '<div class="empty" style="padding:80px">Loading preview…</div>';
  let e;
  try { e = await api('entity', {id}); } catch (err) { $('#pvBox').innerHTML = `<div class="alert critical" style="margin:20px">${esc(err.message)}</div>`; return; }
  pv.entity = e; pv.srcIdx = 0;
  pvRender();
}

function pvRender() {
  const e = pv.entity, tc = TYPE_COLOR[e.type] || '#94a3b8';
  const rel = e.relations.slice(0, 12).map(r => `<div class="rel" data-pv="${r.other_id}" title="${esc(r.evidence || '')}">
      <span class="rt">${r.direction === 'out' ? '→' : '←'} ${esc(r.type.replace(/_/g, ' '))}</span><span class="on">${esc(r.other_name)}</span>
      ${r.origin === 'inferred' ? '<span class="badge violet">inferred</span>' : r.origin === 'user' ? '<span class="badge ok">taught</span>' : ''}</div>`).join('');
  const co = (e.cooccur || []).slice(0, 10).map(c => `<div class="rel" data-pv="${c.id}">
      <span style="color:${TYPE_COLOR[c.type] || '#94a3b8'}">${icon(TYPE_ICON[c.type] || 'tag', 'sm')}</span><span class="on">${esc(c.name)}</span>
      <span class="muted" style="font-size:11px">${c.n}× together</span>${+c.linked ? '' : '<span class="badge violet" title="Appears together but not linked in the graph yet">hidden link?</span>'}</div>`).join('');
  const docs = e.sources.map((s, i) => `<div class="rel" data-src="${i}"><span>${icon(pvIsImg(s) ? 'image' : 'doc', 'sm')}</span><span class="on">${esc(s.title)}</span><span class="muted" style="font-size:11px">${s.mentions}× · p.${s.first_page || 1}</span></div>`).join('');
  const facts = (e.claims || []).filter(c => c.status !== 'rejected').slice(0, 4).map(c => `<div class="claim ${c.status}">${c.origin === 'user' ? '<span class="badge ok">taught</span>' : c.status === 'disputed' ? '<span class="badge warn">disputed</span>' : ''}<span style="flex:1">${esc(c.statement)}</span></div>`).join('');
  $('#pvBox').innerHTML = `
    <div class="pv-head">
      ${pv.stack.length ? `<button class="btn ghost sm icon" id="pvBack" title="Back">${icon('back', 'sm')}</button>` : ''}
      <span class="badge" style="color:${tc};border-color:${tc}55;background:${tc}14">${icon(TYPE_ICON[e.type] || 'tag', 'sm')}${esc(e.type)}</span>
      <div class="pv-title">${esc(e.name)}</div>
      <span class="muted" style="font-size:12px">${e.mention_count} mentions · ${e.relations.length} links · ${Math.round(e.confidence * 100)}% confidence</span>
      <span style="flex:1"></span>
      <button class="btn sm" id="pvAsk">${icon('chat', 'sm')}Ask about this</button>
      <button class="btn sm" id="pvGraph">${icon('fit', 'sm')}Show in graph</button>
      <button class="btn ghost sm icon" id="pvX" title="Close (Esc)">${icon('x', 'sm')}</button>
    </div>
    <div class="pv-body">
      <div class="pv-view">
        ${e.sources.length > 1 ? `<div class="pv-tabs">${e.sources.map((s, i) => `<button class="pv-tab ${i === pv.srcIdx ? 'on' : ''}" data-src="${i}">${icon(pvIsImg(s) ? 'image' : 'doc', 'sm')}${esc(s.title)}</button>`).join('')}</div>` : ''}
        <div class="pv-viewer" id="pvViewer"></div>
      </div>
      <div class="pv-side">
        <div class="card"><h4>About</h4><div style="font-size:13px;color:var(--text-2)">${esc(e.description || 'No description yet. Teach Mnemo with define: or upload a document about it.')}</div>
          ${e.aliases.length ? `<div class="chips" style="margin-top:8px">${e.aliases.map(a => `<span class="badge">${esc(a)}</span>`).join('')}</div>` : ''}</div>
        <div class="card"><h4>Links <span>${e.relations.length}</span></h4>${rel || '<div class="empty">No links yet</div>'}</div>
        <div class="card"><h4>Cross-references</h4>
          <div class="muted pv-sub">Mentioned in ${e.sources.length} source${e.sources.length === 1 ? '' : 's'}</div>${docs || '<div class="empty">—</div>'}
          <div class="muted pv-sub" style="margin-top:10px">Often appears together with</div>${co || '<div class="empty">—</div>'}</div>
        ${facts ? `<div class="card"><h4>Facts</h4>${facts}</div>` : ''}
      </div>
    </div>`;
  $('#pvX').onclick = pvClose;
  if ($('#pvBack')) $('#pvBack').onclick = () => openPreview(pv.stack.pop(), false);
  $('#pvAsk').onclick = () => { pvClose(); setView('ask'); $('#chatInput').value = `What do my sources say about ${e.name}?`; autoGrow(); $('#chatInput').focus(); };
  $('#pvGraph').onclick = () => { pvClose(); if (S.view !== 'graph') setView('graph'); setTimeout(() => focusNode(e.id), 80); };
  $$('#pvBox [data-pv]').forEach(el => el.onclick = () => openPreview(+el.dataset.pv));
  $$('#pvBox [data-src]').forEach(el => el.onclick = () => { pv.srcIdx = +el.dataset.src; pvRender(); });
  pvViewer();
}

async function pvViewer() {
  const e = pv.entity, box = $('#pvViewer'), s = e.sources[pv.srcIdx];
  if (!s) {
    box.innerHTML = `<div class="pv-passage">${e.snippets.length ? e.snippets.map(x => `<p>${pvHighlight(x.text, e)}…</p>`).join('') : '<div class="empty">No source document; this concept was taught directly.</div>'}</div>`;
    return;
  }
  const fileUrl = `api/index.php?action=file&id=${s.document_id}`;
  const head = `<div class="pv-srchead">${icon(pvIsImg(s) ? 'image' : 'doc', 'sm')}<b>${esc(s.title)}</b><span class="muted">${esc(s.filename)}${s.first_page ? ' · page ' + s.first_page : ''}</span><span style="flex:1"></span>
      ${s.has_file ? `<a class="btn ghost sm" href="${fileUrl}" target="_blank">${icon('link', 'sm')}Open file</a>` : ''}
      <button class="btn ghost sm" id="pvDoc">${icon('files', 'sm')}Source details</button></div>`;
  let passage = '';
  if (s.chunk_id) { try { const c = await api('chunk', {id: s.chunk_id}); passage = c.content || ''; } catch {} }
  const passHtml = passage ? `<div class="pv-passage"><div class="pv-sub muted">Passage where Mnemo learned it${s.first_page ? ' (page ' + s.first_page + ')' : ''}</div><p>${pvHighlight(passage, e)}</p></div>` : '';
  if (s.has_file && pvIsPdf(s)) {
    box.innerHTML = head + `<iframe class="pv-frame" src="${fileUrl}#page=${s.first_page || 1}&zoom=page-width" title="${esc(s.title)}"></iframe>` + passHtml;
  } else if (s.has_file && pvIsImg(s)) {
    box.innerHTML = head + `<div class="pv-img"><img src="${fileUrl}" alt="${esc(s.title)}"></div>` + passHtml;
  } else {
    box.innerHTML = head + (passHtml || '<div class="empty">No preview available for this file type. Use "Open file".</div>');
  }
  if ($('#pvDoc')) $('#pvDoc').onclick = () => { pvClose(); openDocument(s.document_id); };
  const mk = box.querySelector('.pv-passage mark'); if (mk) mk.scrollIntoView({block: 'center'});
}

// ---------------------------------------------------------------- shell
$$('[data-view]').forEach(b => b.addEventListener('click', () => setView(b.dataset.view)));
$('#btnToggleSide').onclick = () => { $('#app').classList.toggle('no-side'); setTimeout(resizeGraph, 20); };
$('#btnToggleInsp').onclick = () => { $('#app').classList.toggle('no-insp'); setTimeout(resizeGraph, 20); };
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); $('#globalSearch').focus(); }
  if (e.key === 'Escape') $$('.modal-bg.show').forEach(m => m.classList.remove('show'));
  if (e.target.matches('input,textarea,select')) return;
  const map = {'1': 'graph', '2': 'ask', '3': 'documents', '4': 'review', '5': 'practice', '6': 'quality', '7': 'timeline', '8': 'dashboard', '9': 'memory', '0': 'grow'};
  if (map[e.key] && !e.ctrlKey && !e.metaKey && !e.altKey) setView(map[e.key]);
  if (e.key.toLowerCase() === 'u' && !e.ctrlKey && !e.metaKey) openUpload();
  if (e.key === '?' && window.Tutorial) window.Tutorial.openHub();
});

// Public hooks used by tutorial.js
window.ATLAS = {api, toast, icon, esc, setView, openInsert, loadGrow, openUpload, loadGraph, refreshAll, refreshStatus, loadTree, openEntity, focusNode, sendChat, autoGrow, runSleep, kickProcessing, S, $, $$};

if ('serviceWorker' in navigator) navigator.serviceWorker.register('sw.js').catch(() => {});
hydrateIcons();
$('#btnLabels').classList.add('on');
(async () => {
  await refreshStatus();
  await loadTree();
  let v = 'graph'; try { v = localStorage.getItem('atlas.view') || 'graph'; } catch {}
  setView(v);
  showOverview();
  setInterval(refreshStatus, 10000);
})();
})();
