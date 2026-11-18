// ATLAS service worker: caches the app shell so the UI opens instantly. Data always comes live from your local server.
const CACHE = 'atlas-shell-v2';
const SHELL = ['assets/css/app.css', 'assets/js/app.js', 'assets/vendor/force-graph.min.js', 'assets/vendor/3d-force-graph.min.js', 'assets/icon.svg'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.pathname.includes('/api/')) return; // never cache knowledge
  if (url.pathname.includes('/assets/vendor/')) { e.respondWith(caches.match(e.request).then(r => r || fetch(e.request))); return; }
  // network first for the shell, cache as fallback
  e.respondWith(fetch(e.request).then(r => { if (r.ok && url.pathname.includes('/assets/')) { const c = r.clone(); caches.open(CACHE).then(x => x.put(e.request, c)); } return r; }).catch(() => caches.match(e.request)));
});
