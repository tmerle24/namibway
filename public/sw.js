// Minimal service worker: makes NamibWay installable and shows a branded
// offline page on failed navigations. Deliberately does not cache app pages,
// API responses, or hashed build assets — itinerary/booking data must always
// come from the network, never a stale cache.
const CACHE_VERSION = 'namibway-shell-v1';
const OFFLINE_URL = '/offline.html';
const PRECACHE_URLS = [OFFLINE_URL, '/images/pwa/icon-192.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(CACHE_VERSION)
            .then((cache) => cache.addAll(PRECACHE_URLS))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== CACHE_VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(fetch(event.request).catch(() => caches.match(OFFLINE_URL)));
});
