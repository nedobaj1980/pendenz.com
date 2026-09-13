const CACHE_NAME = 'pendenz-v33'; // Master version bump
const IMAGE_CACHE = 'pendenz-images-v1';

// ... (Rest of ASSETS_TO_CACHE remains the same)
const ASSETS_TO_CACHE = [
  './',
  './index.php',
  './assets/style.css',
  './assets/nav.css',
  './assets/css/notifications.css',
  './assets/js/offline_sync.js',
  './assets/js/notifications_dropdown.js',
  './manifest.json'
];

self.addEventListener('install', (event) => {
  self.skipWaiting();
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS_TO_CACHE))
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys
        .filter((key) => key !== CACHE_NAME && key !== IMAGE_CACHE)
        .map((key) => caches.delete(key))
    )).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);
  
  // Strategy: Network First for dynamic pages (with query params), Stale-While-Revalidate for others
  const isDynamic = url.search.length > 0 || url.pathname.endsWith('.php');

  if (isDynamic) {
    // NETWORK FIRST (with Cache Fallback)
    event.respondWith(
      fetch(event.request, { cache: 'no-store' }).then((networkResponse) => {
        if (networkResponse && networkResponse.status === 200) {
          const cacheCopy = networkResponse.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, cacheCopy));
        }
        return networkResponse;
      }).catch(() => {
        // Fallback to cache (ignore search params for higher hit rate)
        return caches.match(event.request, { ignoreSearch: true }).then((cachedResponse) => {
          return cachedResponse || caches.match('./index.php').then(home => {
             return home || new Response('<html><body style="font-family:sans-serif;padding:40px;text-align:center;"><h2>Offline</h2><p>Diese Seite ist lokal noch nicht gespeichert.</p><button onclick="window.location.reload()" style="padding:10px 20px;border-radius:10px;border:none;background:#0ea5e9;color:#fff;font-weight:bold;">Erneut versuchen</button></body></html>', {
                 headers: { 'Content-Type': 'text/html; charset=utf-8' }
             });
          });
        });
      })
    );
  } else {
    // STALE-WHILE-REVALIDATE for static assets
    event.respondWith(
      caches.open(CACHE_NAME).then((cache) => {
        return cache.match(event.request).then((cachedResponse) => {
          const fetchPromise = fetch(event.request, { cache: 'no-store' }).then((networkResponse) => {
            if (networkResponse && networkResponse.status === 200) {
              cache.put(event.request, networkResponse.clone());
            }
            return networkResponse;
          }).catch(() => null);

          return cachedResponse || fetchPromise;
        });
      })
    );
  }
});
