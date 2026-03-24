const CACHE_NAME = 'mpw-doc-tracker-v3';
const APP_BASE = '/document-tracker';
const OFFLINE_URL = APP_BASE + '/public/offline.php';
const CORE_ASSETS = [
  APP_BASE + '/public/index.php',
  APP_BASE + '/public/login.php',
  APP_BASE + '/public/scan.php',
  OFFLINE_URL,
  APP_BASE + '/assets/mpwlogo1.png',
  APP_BASE + '/assets/icons/icon-192.png',
  APP_BASE + '/assets/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.map((key) => key !== CACHE_NAME ? caches.delete(key) : Promise.resolve()))).then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  if (request.method !== 'GET') return;

  const url = new URL(request.url);
  if (!url.pathname.startsWith(APP_BASE)) return;

  const isApi = url.pathname.startsWith(APP_BASE + '/api/');
  const isDynamicPhp = url.pathname.endsWith('.php');
  const isCodeAsset = url.pathname.startsWith(APP_BASE + '/assets/css/') || url.pathname.startsWith(APP_BASE + '/assets/js/');

  if (isApi || isCodeAsset) {
    event.respondWith(
      fetch(request, { cache: 'no-store' }).catch(async () => {
        if (isCodeAsset) {
          const cached = await caches.match(request);
          if (cached) return cached;
        }
        return caches.match(OFFLINE_URL);
      })
    );
    return;
  }

  if (request.mode === 'navigate' || isDynamicPhp) {
    event.respondWith(
      fetch(request, { cache: 'no-store' })
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)).catch(() => {});
          return response;
        })
        .catch(async () => {
          const cached = await caches.match(request);
          return cached || caches.match(OFFLINE_URL);
        })
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      if (cached) return cached;
      return fetch(request)
        .then((response) => {
          if (response && response.status === 200 && url.origin === self.location.origin) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy)).catch(() => {});
          }
          return response;
        })
        .catch(() => caches.match(OFFLINE_URL));
    })
  );
});
