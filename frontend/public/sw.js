// Service worker de la PWA Vases d'Honneur.
// - Fichiers /assets/ (JS/CSS au nom horodate, donc immuables) : cache d'abord,
//   l'application s'ouvre donc instantanement une fois installee.
// - Navigation (routes) : reseau d'abord, repli sur le cache hors-ligne.
// - L'API et les medias /storage passent toujours par le reseau (jamais en cache).
const CACHE = 'evh-app-v2'
const APP_SHELL = ['/', '/index.html', '/manifest.webmanifest', '/logo-vh.png', '/icon-192.png']

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE).then((c) => c.addAll(APP_SHELL)).catch(() => {}))
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k)))),
  )
  self.clients.claim()
})

self.addEventListener('fetch', (event) => {
  const req = event.request
  if (req.method !== 'GET') return
  const url = new URL(req.url)
  if (url.origin !== self.location.origin) return
  // Jamais de cache pour l'API ni les medias dynamiques.
  if (url.pathname.startsWith('/api') || url.pathname.startsWith('/storage')) return

  // Navigation (routes SPA) : reseau d'abord, repli sur index.html hors-ligne.
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(() => caches.match(req).then((r) => r || caches.match('/index.html'))),
    )
    return
  }

  // Fichiers build /assets/ : noms horodates (immuables) => cache d'abord (ouverture immediate).
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(
      caches.match(req).then((hit) => hit || fetch(req).then((res) => {
        const copy = res.clone()
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {})
        return res
      })),
    )
    return
  }

  // Autres ressources (images, icones...) : reseau d'abord + mise en cache, repli cache.
  event.respondWith(
    fetch(req)
      .then((res) => {
        const copy = res.clone()
        caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {})
        return res
      })
      .catch(() => caches.match(req)),
  )
})
