// Service worker de la PWA Vases d'Honneur.
// - Fichiers /assets/ (JS/CSS au nom horodate, donc immuables) : cache d'abord,
//   l'application s'ouvre donc instantanement une fois installee.
// - Navigation (routes) : reseau d'abord, repli sur le cache hors-ligne.
// - L'API et les medias /storage passent toujours par le reseau (jamais en cache).
const CACHE = 'evh-app-v3'
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

// ---------------------------------------------------------------- Notifications push
// Le serveur envoie { title, body, url, type, tag } chiffre (Web Push / VAPID).
self.addEventListener('push', (event) => {
  let data = {}
  try { data = event.data ? event.data.json() : {} } catch { data = { title: event.data ? event.data.text() : '' } }

  const title = data.title || "Vases d'Honneur"
  const options = {
    body: data.body || '',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    tag: data.tag || undefined,
    renotify: !!data.tag,
    data: { url: data.url || '/tableau-de-bord' },
    lang: 'fr',
  }

  event.waitUntil((async () => {
    await self.registration.showNotification(title, options)
    // Pastille sur l'icone de l'application (Android / ordinateur / iPhone installe).
    try {
      const list = await self.registration.getNotifications()
      if (self.navigator && 'setAppBadge' in self.navigator) await self.navigator.setAppBadge(list.length)
    } catch { /* non supporte */ }
    // Previent les onglets ouverts : la cloche se met a jour immediatement.
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    clients.forEach((c) => c.postMessage({ type: 'evh-push' }))
  })())
})

// Clic sur une notification : ouvre (ou reutilise) l'application sur la bonne page.
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const target = new URL(event.notification.data?.url || '/tableau-de-bord', self.location.origin).href

  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    for (const client of clients) {
      if (client.url.startsWith(self.location.origin) && 'focus' in client) {
        await client.focus()
        client.postMessage({ type: 'evh-navigate', url: target })
        return
      }
    }
    await self.clients.openWindow(target)
  })())
})

// Abonnement renouvele par le navigateur : on le reenregistre aupres du serveur au prochain lancement.
self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
    clients.forEach((c) => c.postMessage({ type: 'evh-push-resubscribe' }))
  })())
})
