// Service worker: app shell cached for offline canvassing; API responses network-first with cache fallback.
const SHELL = 'nvvp-shell-v1';
const ASSETS = ['/canvass', '/assets/app.css', '/assets/canvass.js', '/manifest.webmanifest', 'https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.js', 'https://unpkg.com/maplibre-gl@4.7.1/dist/maplibre-gl.css'];
self.addEventListener('install', e => { e.waitUntil(caches.open(SHELL).then(c => Promise.allSettled(ASSETS.map(a => c.add(a)))).then(() => self.skipWaiting())); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== SHELL).map(k => caches.delete(k)))).then(() => self.clients.claim())); });
self.addEventListener('fetch', e => {
  const url = new URL(e.request.url);
  if (e.request.method !== 'GET') return;
  const cacheable = url.pathname.startsWith('/canvass') || url.pathname.startsWith('/assets/') || url.pathname.startsWith('/api/turfs/') || url.pathname === '/manifest.webmanifest' || url.host === 'unpkg.com' || url.host.endsWith('openfreemap.org');
  if (!cacheable) return;
  e.respondWith(fetch(e.request).then(r => { if (r.ok) { const copy = r.clone(); caches.open(SHELL).then(c => c.put(e.request, copy)); } return r; }).catch(() => caches.match(e.request).then(m => m || new Response('Offline', { status: 503 }))));
});
