/**
 * EleGuard Rail — Service Worker
 * Milestone 9C Stage 4E.4.2-A: PWA Foundation
 *
 * PRIVACY & SECURITY DIRECTIVE:
 * - NEVER cache authenticated API responses containing sensitive wildlife information.
 * - NEVER cache precise wildlife coordinates in shared or public caches.
 * - NEVER cache login responses, tokens, or sensitive session information.
 * - Strictly enforce network-only for all /api/ and /api/v2/ endpoints.
 * - Cache only public static shell assets and offline fallback.
 */

const CACHE_NAME = 'eleguard-field-v1';

// Base static shell assets to pre-cache on install
const PRECACHE_ASSETS = [
    './offline.html',
    './manifest.webmanifest',
    './assets/css/field_pwa.css',
    './assets/js/field_crypto_storage.js',
    './assets/js/field_sync_controller.js',
    './assets/js/field_pwa.js',
    './assets/images/icons/icon.svg',
    './assets/images/icons/icon-192.png',
    './assets/images/icons/icon-512.png',
    './assets/images/icons/icon-maskable-192.png',
    './assets/images/icons/icon-maskable-512.png'
];

/**
 * Service Worker Installation
 */
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                // Cache shell assets safely (best effort for individual files)
                return Promise.allSettled(
                    PRECACHE_ASSETS.map((asset) => {
                        return cache.add(asset).catch((err) => {
                            console.warn('[SW] Non-fatal precache skip for:', asset, err.message);
                        });
                    })
                );
            })
            .then(() => {
                // Activate immediately without waiting for existing clients to close
                return self.skipWaiting();
            })
    );
});

/**
 * Service Worker Activation & Cache Cleanup
 */
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((name) => {
                    if (name !== CACHE_NAME) {
                        console.log('[SW] Purging outdated cache:', name);
                        return caches.delete(name);
                    }
                })
            );
        }).then(() => {
            // Claim clients immediately
            return self.clients.claim();
        })
    );
});

/**
 * Fetch Request Handler & Security Filters
 */
self.addEventListener('fetch', (event) => {
    const request = event.request;

    // RULE 1: Only handle GET requests; all mutations (POST, PUT, DELETE) bypass SW entirely
    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);

    // RULE 2: STRICT CACHE BAN for API, Auth, and Sensitive Wildlife Data
    const isRestrictedPath = 
        url.pathname.includes('/api/') || 
        url.pathname.includes('/api/v2/') || 
        url.pathname.endsWith('/login.php') || 
        url.pathname.endsWith('/logout.php') || 
        url.pathname.endsWith('/register.php') || 
        url.searchParams.has('nocache');

    if (isRestrictedPath) {
        // Direct network fetch only — NEVER inspect, store, or cache in Cache Storage
        return;
    }

    // RULE 3: HTML Navigation Requests (Network-First with Offline Fallback)
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .catch(() => {
                    // When network fails during navigation, serve cached offline fallback
                    return caches.match('./offline.html')
                        .then((fallback) => {
                            if (fallback) return fallback;
                            // Search across all caches for offline.html
                            return caches.match('offline.html');
                        });
                })
        );
        return;
    }

    // RULE 4: Static Shell Assets (Cache-First / Stale-While-Revalidate)
    // Applies strictly to static files (.css, .js, .svg, .png, .jpg, .woff2, .json)
    const isStaticAsset = /\.(css|js|svg|png|jpg|jpeg|webp|woff2|woff|ttf|webmanifest)$/i.test(url.pathname);

    if (isStaticAsset) {
        event.respondWith(
            caches.match(request).then((cachedResponse) => {
                if (cachedResponse) {
                    // Return cached asset immediately
                    return cachedResponse;
                }

                // If not cached, fetch from network and cache if valid
                return fetch(request).then((networkResponse) => {
                    // Only cache valid GET responses for non-PHP, non-API static files
                    if (
                        networkResponse && 
                        networkResponse.status === 200 && 
                        networkResponse.type === 'basic' && 
                        !url.pathname.endsWith('.php') && 
                        !url.pathname.includes('/api/')
                    ) {
                        const responseClone = networkResponse.clone();
                        caches.open(CACHE_NAME).then((cache) => {
                            cache.put(request, responseClone);
                        });
                    }
                    return networkResponse;
                }).catch(() => {
                    // Return empty or fallback if both cache and network fail
                    return new Response('', { status: 408, statusText: 'Request Timeout (Offline)' });
                });
            })
        );
    }
});
