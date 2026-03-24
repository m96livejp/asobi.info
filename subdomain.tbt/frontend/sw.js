const CACHE_NAME = 'tournament-battle-v16';

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('fetch', (event) => {
    // API呼び出しはキャッシュしない
    if (event.request.url.includes('/api/')) {
        return;
    }
    // 外部リソース（広告等）はキャッシュしない
    if (!event.request.url.startsWith(self.location.origin)) {
        return;
    }
    // network-first: ネットワーク優先、失敗時キャッシュ
    event.respondWith(
        fetch(event.request)
            .then((response) => {
                if (response.ok) {
                    const clone = response.clone();
                    caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
                }
                return response;
            })
            .catch(() => caches.match(event.request))
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k)))
        ).then(() => self.clients.claim())
    );
});
