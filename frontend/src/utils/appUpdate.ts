// Mise a jour automatique de l'application ouverte : apres une mise en ligne, un onglet (ou l'application
// installee) deja ouvert garde l'ancienne version en memoire. On compare regulierement le fichier
// principal annonce par index.html a celui qui tourne ; s'il a change, la nouvelle version est chargee
// au prochain changement de page, ou au retour dans l'application (jamais pendant une saisie, ni tant qu'un
// formulaire modifie n'est pas enregistre).
import { hasUnsavedInput } from './dirty'

const CHECK_MS = 5 * 60 * 1000
const MAIN = /\/assets\/index-[\w-]+\.js/

let pending = false
let lastCheck = 0

function running(): string | null {
  const src = document.querySelector<HTMLScriptElement>('script[type="module"][src*="/assets/index-"]')?.getAttribute('src')
  return src?.match(MAIN)?.[0] ?? null
}

function typing(): boolean {
  const el = document.activeElement
  return !!el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || (el as HTMLElement).isContentEditable)
}

async function check(): Promise<void> {
  const mine = running()
  if (!mine || pending || Date.now() - lastCheck < 30000) return // serveur de developpement : rien a comparer
  lastCheck = Date.now()
  try {
    const html = await fetch('/index.html', { cache: 'no-store' }).then((r) => (r.ok ? r.text() : ''))
    const online = html.match(MAIN)?.[0]
    if (online && online !== mine) pending = true
  } catch { /* hors ligne : on reessaiera */ }
}

/** Nouvelle version en attente : chargee maintenant (appele a chaque changement de page). */
export function applyPendingUpdate(): void {
  if (pending && !typing() && !hasUnsavedInput()) window.location.reload()
}

// Version bien demarree : le service worker en fait sa page hors-ligne et retire les fichiers des anciennes versions.
const ANNOUNCE_AFTER_MS = 20000

let started = false
export function startAppUpdates(): void {
  const main = running()
  if (started || !main) return
  started = true
  window.setTimeout(() => {
    navigator.serviceWorker?.ready.then((reg) => reg.active?.postMessage({ type: 'evh-build-started', main })).catch(() => {})
  }, ANNOUNCE_AFTER_MS)
  window.setInterval(() => { if (document.visibilityState === 'visible') void check() }, CHECK_MS)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return
    // Retour dans l'application : version deja reperee => rechargee tout de suite ; sinon on verifie.
    if (pending) applyPendingUpdate()
    else void check().then(() => applyPendingUpdate())
  })
  void check()
}
