// Service worker de la PWA My vasesdhonneur (Vases d'Honneur Chicoutimi).
// - Fichiers /assets/ (JS/CSS au nom horodate, donc immuables) : cache d'abord,
//   l'application s'ouvre donc instantanement une fois installee.
// - Navigation (routes) : reseau d'abord, repli sur le cache hors-ligne.
// - L'API et les medias /storage passent toujours par le reseau (jamais en cache).
const CACHE = 'evh-app-v5'
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
// Accuse de reception au serveur : il sait si le message est arrive sur l'appareil et s'il a
// pu etre affiche (diagnostic : php artisan app:push-check).
async function reportReceipt(status, error) {
  try {
    const sub = await self.registration.pushManager.getSubscription()
    if (!sub) return
    await fetch('/api/push/receipt', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ endpoint: sub.endpoint, status, error }),
    })
  } catch { /* hors ligne : sans importance */ }
}

self.addEventListener('push', (event) => {
  let data = {}
  try { data = event.data ? event.data.json() : {} } catch { data = { title: event.data ? event.data.text() : '' } }

  const title = data.title || "My vasesdhonneur"
  const options = {
    body: data.body || '',
    icon: '/icon-192.png',
    badge: '/icon-192.png',
    tag: data.tag || undefined,
    data: { url: data.url || '/tableau-de-bord' },
  }

  event.waitUntil((async () => {
    let status = 'shown'
    let error = null
    try {
      await self.registration.showNotification(title, options)
    } catch (e) {
      status = 'error'
      error = String((e && e.message) || e)
      // Dernier recours : notification minimale (titre seul).
      try { await self.registration.showNotification(title) } catch { /* rien de plus a faire */ }
    }
    await reportReceipt(status, error)
    // Pastille sur l'icone de l'application (Android / ordinateur / iPhone installe).
    try {
      const list = await self.registration.getNotifications()
      if (self.navigator && 'setAppBadge' in self.navigator) await self.navigator.setAppBadge(list.length)
    } catch { /* non supporte */ }
    // Previent les onglets ouverts : la cloche se met a jour immediatement
    // (avec le texte : si l'application est ouverte, elle l'affiche elle-meme, car l'iPhone
    // n'affiche pas la notification systeme quand l'application est au premier plan).
    try {
      const clients = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      clients.forEach((c) => c.postMessage({ type: 'evh-push', title, body: options.body }))
    } catch { /* ignore */ }
  })())
})

// Clic sur une notification : ouvre (ou reutilise) l'application sur la bonne page.
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  // Uniquement une page de l'application (jamais un site exterieur).
  let target = new URL(event.notification.data?.url || '/tableau-de-bord', self.location.origin)
  if (target.origin !== self.location.origin) target = new URL('/tableau-de-bord', self.location.origin)
  target = target.href

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
