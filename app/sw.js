/* Vetroom / FurrFinder - service worker: rende l'app installabile e più veloce.
   Pagine e codice: prima dalla rete (sempre aggiornati), la copia locale solo se si è offline.
   Immagini e librerie: dalla copia locale, aggiornata in background. */
const CACHE = 'vetroom-v1';
self.addEventListener('install', (e) => { self.skipWaiting(); });
self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== location.origin || !url.pathname.startsWith('/app/')) return;
  const isAsset = /\/app\/(img|vendor)\//.test(url.pathname);
  if (isAsset) {
    e.respondWith(caches.open(CACHE).then(async (c) => {
      const hit = await c.match(req);
      const net = fetch(req).then((r) => { if (r.ok) c.put(req, r.clone()); return r; }).catch(() => hit);
      return hit || net;
    }));
  } else {
    e.respondWith(fetch(req).then((r) => {
      if (r.ok) { const cp = r.clone(); caches.open(CACHE).then((c) => c.put(req, cp)); }
      return r;
    }).catch(() => caches.match(req)));
  }
});

// ---------- Notifiche FurrFinder ----------
self.addEventListener('push', (e) => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch { d = { body: e.data ? e.data.text() : '' }; }
  e.waitUntil(self.registration.showNotification(d.title || 'FurrFinder', {
    body: d.body || '',
    icon: '/app/img/furrfinder-192.png',
    tag: d.tag || undefined,
    renotify: !!d.tag,
    data: { url: d.url || '/app/proprietario/' }
  }));
});
self.addEventListener('notificationclick', (e) => {
  e.notification.close();
  // si aprono solo pagine di Vetroom/FurrFinder
  let url = new URL((e.notification.data && e.notification.data.url) || '/app/proprietario/', self.location.origin);
  if (url.origin !== self.location.origin || !url.pathname.startsWith('/app/')) url = new URL('/app/proprietario/', self.location.origin);
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((ws) => {
    const w = ws.find((x) => x.url.startsWith(self.location.origin + '/app/'));
    if (w) return w.navigate(url.href).then((c) => (c || w).focus()).catch(() => self.clients.openWindow(url.href));
    return self.clients.openWindow(url.href);
  }));
});
