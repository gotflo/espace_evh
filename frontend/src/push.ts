// Notifications push (Web Push) : abonnement de l'appareil courant.
// Fonctionne sur Android / ordinateur (Chrome, Edge, Firefox) et sur iPhone
// uniquement quand l'application est installee sur l'ecran d'accueil (iOS 16.4+).
import { api } from './api/client'

export type PushState = 'unsupported' | 'ios-install' | 'denied' | 'off' | 'on'

/** Erreur d'abonnement avec un message clair pour l'utilisateur. */
export class PushError extends Error {}

function isIos(): boolean {
  return /iphone|ipad|ipod/i.test(navigator.userAgent)
}
function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches
    || (navigator as unknown as { standalone?: boolean }).standalone === true
}

export function pushSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out
}

function sameKey(a: ArrayBuffer | null | undefined, b: Uint8Array): boolean {
  if (!a) return false
  const x = new Uint8Array(a)
  return x.length === b.length && x.every((v, i) => v === b[i])
}

/** Service worker actif (enregistre si besoin), avec delai maximal. */
async function registration(): Promise<ServiceWorkerRegistration> {
  const existing = await navigator.serviceWorker.getRegistration('/')
  if (!existing) await navigator.serviceWorker.register('/sw.js')
  return Promise.race([
    navigator.serviceWorker.ready,
    new Promise<never>((_, reject) => setTimeout(() => reject(new PushError(
      "Le service de l'application ne répond pas. Rechargez la page puis réessayez.",
    )), 10000)),
  ])
}

async function currentSubscription(): Promise<PushSubscription | null> {
  const reg = await navigator.serviceWorker.getRegistration('/')
  return reg ? reg.pushManager.getSubscription() : null
}

export async function getPushState(): Promise<PushState> {
  if (!pushSupported()) return isIos() && !isStandalone() ? 'ios-install' : 'unsupported'
  if (Notification.permission === 'denied') return 'denied'
  if (Notification.permission !== 'granted') return 'off'
  try { return (await currentSubscription()) ? 'on' : 'off' } catch { return 'off' }
}

async function sendToServer(sub: PushSubscription): Promise<void> {
  const json = sub.toJSON()
  await api('/me/push/subscribe', {
    method: 'POST', toast: false,
    body: { endpoint: json.endpoint, keys: json.keys, content_encoding: 'aes128gcm' },
  })
}

/** Traduit les erreurs techniques du navigateur. */
function explain(err: unknown): PushError {
  if (err instanceof PushError) return err
  const e = err as { name?: string; message?: string }
  if (e?.name === 'NotAllowedError') return new PushError('Les notifications sont bloquées pour ce site dans le navigateur.')
  if (e?.name === 'AbortError' || /push service/i.test(e?.message ?? '')) {
    return new PushError("Le service de notification du navigateur est indisponible (navigateur sans services Google, mode privé ou réseau). Essayez avec Chrome ou Edge à jour.")
  }
  if (e?.name === 'InvalidStateError') return new PushError('Un ancien abonnement bloque l’activation. Rechargez la page puis réessayez.')
  return new PushError("Impossible d'activer les notifications sur cet appareil" + (e?.message ? ` (${e.message})` : '.'))
}

/** Demande l'autorisation (a appeler depuis un clic) puis abonne l'appareil. */
export async function enablePush(): Promise<PushState> {
  if (!pushSupported()) return getPushState()
  const permission = await Notification.requestPermission()
  if (permission !== 'granted') return permission === 'denied' ? 'denied' : 'off'

  try {
    const { public_key: key } = await api<{ public_key: string | null }>('/me/push')
    if (!key) throw new PushError('Les notifications push ne sont pas activées sur le serveur.')
    const serverKey = urlBase64ToUint8Array(key)

    const reg = await registration()
    let sub = await reg.pushManager.getSubscription()
    // Abonnement laisse par une autre application sur la meme adresse (ou ancienne cle) : on le remplace.
    if (sub && !sameKey(sub.options.applicationServerKey, serverKey)) {
      await sub.unsubscribe().catch(() => {})
      sub = null
    }
    if (!sub) sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: serverKey })
    await sendToServer(sub)
    return 'on'
  } catch (err) {
    throw explain(err)
  }
}

export async function disablePush(): Promise<PushState> {
  try {
    const sub = await currentSubscription()
    if (sub) {
      await api('/me/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint }, toast: false }).catch(() => {})
      await sub.unsubscribe()
    }
  } catch { /* ignore */ }
  return getPushState()
}

/**
 * Au demarrage (utilisateur connecte) : si la permission est deja accordee, on
 * re-synchronise l'abonnement avec le serveur (nouvel appareil, cle renouvelee...).
 */
export async function syncPush(): Promise<void> {
  if (!pushSupported() || Notification.permission !== 'granted') return
  try { await enablePush() } catch { /* silencieux au demarrage */ }
}

/** A la deconnexion : cet appareil ne doit plus recevoir les notifications du compte. */
export async function forgetPushOnThisDevice(): Promise<void> {
  if (!pushSupported()) return
  try {
    const sub = await currentSubscription()
    if (sub) {
      await api('/me/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint }, toast: false }).catch(() => {})
      await sub.unsubscribe()
    }
  } catch { /* ignore */ }
}
