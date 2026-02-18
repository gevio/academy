/**
 * Service Worker – AS26 Live (Adventure Southside 2026)
 *
 * Strategien:
 *   Precache:      Alle Shell-Assets beim Install vorab laden
 *   Cache-First:   Statische Assets (CSS/JS/Img) – schnell, offline-fähig
 *   Network-First: API-Daten (workshops.json) + HTML-Seiten – immer frisch
 *   Offline:       Fallback-Seite wenn alles fehlschlägt
 */
const CACHE_NAME = 'as26-live-v21';

// Shell-Assets: werden beim Install vorab gecached
const PRECACHE_URLS = [
  '/',
  '/home.html',
  '/programm.html',
  '/details.html',
  '/offline.html',
  '/css/style.css',
  '/css/programm.css',
  '/js/app.js',
  '/js/programm.js',
  '/img/logo-southside.png',
  '/img/logo-academy.png',
  '/img/icon-192.png',
  '/manifest.json',
  '/api/workshops.json',
  '/aussteller.html',
  '/css/aussteller.css',
  '/js/aussteller.js',
  '/api/aussteller.json',
  '/api/standplan.json',
  '/experten.html',
  '/experte.html',
  '/css/experten.css',
  '/api/experten.json',
  '/js/ortmap.js',
  '/api/veranstaltungsorte.json',
  '/img/plan/overview.jpg',
  '/img/plan/FW.jpg',
  '/img/plan/FG.jpg',
  '/img/plan/A3.jpg',
  '/img/plan/A4.jpg',
  '/img/plan/A5.jpg',
  '/img/plan/A6.jpg',
];

// ── Install: Shell precachen ──
self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then((cache) => cache.addAll(PRECACHE_URLS))
      .then(() => self.skipWaiting())
  );
});

// ── Activate: Alte Caches aufräumen + sofort übernehmen ──
self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(
        keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))
      ))
      .then(() => self.clients.claim())
  );
});

// ── Fetch Handler ──
self.addEventListener('fetch', (event) => {
  const { request } = event;
  const url = new URL(request.url);

  // Nur GET-Requests cachen
  if (request.method !== 'GET') return;

  // Nur same-origin
  if (url.origin !== self.location.origin) return;

  // ── 1) API-Daten → Network-First ──
  if (url.pathname.startsWith('/api/') && url.pathname.endsWith('.json')) {
    event.respondWith(networkFirst(request));
    return;
  }

  // ── 2) Statische Assets → Stale-While-Revalidate ──
  //     Sofort aus Cache liefern, im Hintergrund frische Version holen
  if (isStaticAsset(url.pathname)) {
    event.respondWith(staleWhileRevalidate(request));
    return;
  }

  // ── 3) HTML/Navigation → Network-First + Offline-Fallback ──
  if (request.headers.get('accept')?.includes('text/html')) {
    event.respondWith(networkFirstWithOffline(request));
    return;
  }

  // ── 4) Alles andere → Network mit Cache-Fallback ──
  event.respondWith(networkFirst(request));
});

// ══════════════════════════════════════════════════════════
// Strategien
// ══════════════════════════════════════════════════════════

/** Cache-First: Aus Cache, nur bei Miss aus dem Netzwerk */
async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    return new Response('Offline', { status: 503 });
  }
}

/** Stale-While-Revalidate: Sofort aus Cache, im Hintergrund aktualisieren */
async function staleWhileRevalidate(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);

  // Im Hintergrund frische Version holen und cachen
  const fetchPromise = fetch(request).then((response) => {
    if (response.ok) {
      cache.put(request, response.clone());
    }
    return response;
  }).catch(() => null);

  // Sofort cached Version liefern, oder auf Netzwerk warten
  return cached || await fetchPromise || new Response('Offline', { status: 503 });
}

/** Network-First: Aus Netzwerk, bei Fehler aus Cache */
async function networkFirst(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached = await caches.match(request);
    return cached || new Response('Offline', { status: 503 });
  }
}

/** Network-First für HTML – bei Offline → offline.html */
async function networkFirstWithOffline(request) {
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    const cached = await caches.match(request);
    if (cached) return cached;
    // Fallback: Offline-Seite
    return caches.match('/offline.html') || new Response('Offline', { status: 503 });
  }
}

/** Prüft ob ein Pfad ein statisches Asset ist */
function isStaticAsset(pathname) {
  return pathname.startsWith('/css/') ||
         pathname.startsWith('/js/') ||
         pathname.startsWith('/img/') ||
         pathname === '/manifest.json';
}
