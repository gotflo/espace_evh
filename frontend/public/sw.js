// Service worker de la PWA My vasesdhonneur (Vases d'Honneur Chicoutimi).
// - Fichiers /assets/ (JS/CSS au nom horodate, donc immuables) : cache d'abord,
//   l'application s'ouvre donc instantanement une fois installee.
// - Navigation (routes) : reseau d'abord, repli sur le cache hors-ligne.
// - L'API et les medias /storage passent toujours par le reseau (jamais en cache).
// - Seules les reponses reussies vont en cache : une erreur passagere (404, 503) n'y reste jamais.
const CACHE = 'evh-app-v5'
const APP_SHELL = ['/', '/index.html', '/manifest.webmanifest', '/logo-vh.png', '/icon-192.png']

function remember(req, res) {
  if (!res.ok) return res
  const copy = res.clone()
  caches.open(CACHE).then((c) => c.put(req, copy)).catch(() => {})
  return res
}

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
  // Video : lue par morceaux, directement sur le reseau (trop lourde pour le cache).
  if (url.pathname.endsWith('.mp4')) return

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
      caches.match(req).then((hit) => (hit && hit.ok ? hit : fetch(req).then((res) => remember(req, res)))),
    )
    return
  }

  // Autres ressources (images, icones...) : reseau d'abord + mise en cache, repli cache.
  event.respondWith(
    fetch(req)
      .then((res) => remember(req, res))
      .catch(() => caches.match(req)),
  )
})

// ---------------------------------------------------------------- Entretien du cache
// Les noms de fichiers changent a chaque mise en ligne : sans entretien, le cache grossirait sans fin. L'application
// annonce la version qui a bien demarre (utils/appUpdate.ts) ; c'est le seul moment sur pour :
// - faire de sa page la page hors-ligne : ses fichiers sont en cache, ce qui n'est pas sur juste apres un chargement ;
// - retirer les fichiers des anciennes versions, seulement si aucun autre onglet ne tourne (il peut en avoir besoin).
// index.html ne cite que le demarrage : les pages chargees a la demande, polices et drapeaux sont cites par les fichiers
// JS et CSS, qu'on suit de proche en proche. Un fichier absent du cache n'a rien a retirer et n'est pas telecharge.
const FILE_NAME = /[\w@~.-]+\.(?:js|css|woff2?|ttf|otf|svg|png|jpe?g|webp|gif|ico|mp3|mp4|webm|json)\b/g
const assetName = (url) => new URL(url).pathname.slice('/assets/'.length)

async function assetsInUse(cache, html, cached) {
  const inUse = new Set()
  const todo = html.match(FILE_NAME) || []
  while (todo.length) {
    const name = todo.pop()
    const req = cached.get(name)
    if (!req || inUse.has(name)) continue
    inUse.add(name)
    if (!/\.(?:js|css)$/.test(name)) continue
    const res = await cache.match(req)
    const found = (res ? await res.text() : '').match(FILE_NAME) || []
    for (const other of found) todo.push(other)
  }
  return inUse
}

async function buildStarted(main) {
  const cache = await caches.open(CACHE)
  const cached = new Map()
  for (const req of await cache.keys()) {
    if (new URL(req.url).pathname.startsWith('/assets/')) cached.set(assetName(req.url), req)
  }
  if (!cached.has(main.slice('/assets/'.length))) return // premiere visite : la page n'est pas encore en cache

  // La page en ligne doit annoncer cette version : sinon cet onglet est en retard, on ne touche a rien.
  const res = await fetch('/index.html', { cache: 'no-store' })
  if (!res.ok) return
  const html = await res.clone().text()
  if (!html.includes(main)) return
  await cache.put('/index.html', res.clone())
  await cache.put('/', res)

  const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
  if (windows.length > 1) return
  const inUse = await assetsInUse(cache, html, cached)
  await Promise.all([...cached].filter(([name]) => !inUse.has(name)).map(([, req]) => cache.delete(req)))
}

self.addEventListener('message', (event) => {
  if (event.data?.type === 'evh-build-started' && typeof event.data.main === 'string') {
    event.waitUntil(buildStarted(event.data.main).catch(() => {}))
  }
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
