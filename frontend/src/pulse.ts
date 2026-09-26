// « Pouls » de l'application : un seul appel leger (/me/pulse) toutes les ~45 s quand
// l'application est visible. Chaque ecran s'abonne aux donnees qui l'interessent et ne se
// recharge que si elles ont change. Remplace les rafraichissements periodiques de chaque
// composant (qui multipliaient les requetes par le nombre de membres connectes).
import { useEffect, useRef } from 'react'
import { api, auth } from './api/client'
import { setUnread } from './notifications'

export type PulseKey = 'announcements' | 'events' | 'exercises' | 'requests' | 'members' | 'validations' | 'fiss' | 'attendance' | 'unread'

type Versions = Partial<Record<PulseKey, string>>
type Listener = (v: Versions) => void

const BASE_INTERVAL = 45000
let versions: Versions = {}
let lastPoll = 0
let inflight: Promise<void> | null = null
let timer: number | undefined
const listeners = new Set<Listener>()

export function pollNow(): Promise<void> {
  if (!auth.get()) return Promise.resolve()
  if (inflight) return inflight
  lastPoll = Date.now()
  inflight = api<{ unread: number; versions: Versions }>('/me/pulse')
    .then((r) => {
      setUnread(r.unread)
      versions = { ...r.versions, unread: String(r.unread) }
      listeners.forEach((l) => l(versions))
    })
    .catch(() => { /* hors ligne ou serveur occupe : on reessaiera au prochain passage */ })
    .finally(() => { inflight = null })
  return inflight
}

function schedule() {
  window.clearTimeout(timer)
  // Etalement aleatoire : les appareils ne se synchronisent pas tous a la meme seconde.
  const delay = BASE_INTERVAL + Math.round((Math.random() - 0.5) * 20000)
  timer = window.setTimeout(() => {
    if (document.visibilityState === 'visible') void pollNow()
    schedule()
  }, delay)
}

let started = false
/** Demarre le pouls (une fois, apres connexion). */
export function startPulse() {
  if (started) return
  started = true
  void pollNow()
  schedule()
  const onVisible = () => {
    if (document.visibilityState === 'visible' && Date.now() - lastPoll > 15000) void pollNow()
  }
  document.addEventListener('visibilitychange', onVisible)
  window.addEventListener('focus', onVisible)
  window.addEventListener('online', () => void pollNow())
  // Push recu : on met a jour tout de suite.
  navigator.serviceWorker?.addEventListener('message', (e: MessageEvent) => {
    if (e.data?.type === 'evh-push') void pollNow()
  })
}

/**
 * Recharge `reload` quand l'une des donnees `keys` change sur le serveur.
 * Le composant garde la main sur son chargement initial.
 */
export function usePulse(keys: PulseKey | PulseKey[], reload: () => void, enabled = true) {
  const cb = useRef(reload)
  cb.current = reload
  const list = Array.isArray(keys) ? keys : [keys]
  const signature = list.join(',')

  useEffect(() => {
    if (!enabled) return
    const watched = signature.split(',') as PulseKey[]
    const snapshot = (v: Versions) => watched.map((k) => v[k] ?? '').join('|')
    let seen = snapshot(versions)
    const listener: Listener = (v) => {
      const now = snapshot(v)
      if (now !== seen) {
        const first = seen.replace(/\|/g, '') === ''
        seen = now
        if (!first) cb.current()
      }
    }
    listeners.add(listener)
    return () => { listeners.delete(listener) }
  }, [signature, enabled])
}
