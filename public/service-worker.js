/**
 * Service Worker – Academy Live (Adventure Southside 2026)
 * Cache-First für statische Assets, Network-First für API/PHP.
 */
const CACHE_NAME = 'academy-live-v2';

const PRECACHE_URLS = [
  '/css/style.css',
  '/css/programm.css',
  '/js/app.js',
  '/js/programm.js',
  '/img/logo-southside.png',
  '/img/logo-academy.png',
  '/manifest.json',
  '/programm.html',
  '/details.html',
  '/api/workshops.json',
];

// ── Install: Statische Assets vorab cachen ──
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS))
  );
  self.skipWaiting();
});

// ── Activate: Alte Caches aufräumen ──
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(
        keys
          .filter((key) => key !== CACHE_NAME)
          .map((key) => caches.delete(key))
      )
    )
  );
  self.clients.claim();
});

// ── Fetch: Strategie pro Request-Typ ──
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // POST-Requests nie cachen
  if (request.method !== 'GET') return;

  // workshops.json → Stale-While-Revalidate
  if (url.pathname === '/api/workshops.json') {
    event.respondWith(
      caches.open(CACHE_NAME).then((cache) =>
        cache.match(request).then((cached) => {
          const fetchPromise = fetch(request).then((response) => {
            cache.put(request, response.clone());
            return response;
          });
          return cached || fetchPromise;
        })
      )
    );
    return;
  }

  // Statische Assets → Cache-First
  if (
    url.pathname.startsWith('/css/') ||
    url.pathname.startsWith('/js/') ||
    url.pathname.startsWith('/img/') ||
    url.pathname === '/manifest.json' ||
    url.pathname === '/programm.html' ||
    url.pathname === '/details.html'
  ) {
    event.respondWith(
      caches.match(request).then(
        (cached) =>
          cached ||
          fetch(request).then((response) => {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
            return response;
          })
      )
    );
    return;
  }

  // HTML/PHP-Seiten → Network-First mit Offline-Fallback
  if (request.headers.get('accept')?.includes('text/html')) {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const clone = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, clone));
          return response;
        })
        .catch(() => caches.match(request))
    );
    return;
  }
});
