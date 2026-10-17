// Client API : appels au backend Laravel via /api (proxy Vite en dev).
import { toast } from '../toast'

const TOKEN_KEY = 'evh_token'

export const auth = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t: string) => { cache.clear(); cachedAt.clear(); expiredShown = false; localStorage.setItem(TOKEN_KEY, t) },
  clear: () => { cache.clear(); cachedAt.clear(); localStorage.removeItem(TOKEN_KEY) },
}

/**
 * Session expiree (401 sur un appel authentifie, hors connexion) : l'etat de connexion est remis
 * a zero (AuthContext s'y abonne, la page protegee renvoie vers /connexion) et la personne est
 * prevenue une seule fois, meme si plusieurs lectures echouent en meme temps.
 */
let expiredShown = false
let expired: (() => void) | null = null
export function onSessionExpired(cb: (() => void) | null) { expired = cb }
function sessionExpired() {
  if (expiredShown) return
  expiredShown = true
  toast.warning('Votre session a expiré : reconnectez-vous.')
  expired?.()
}

/**
 * Dernieres lectures (GET) reussies, en memoire seulement (rien n'est ecrit sur l'appareil),
 * propres a la session et videes a la deconnexion. Une page deja visitee s'affiche tout de
 * suite avec ces donnees (peek) puis se met a jour en silence quand la nouvelle lecture arrive.
 */
const CACHE_MAX = 80
const cache = new Map<string, unknown>()
const cachedAt = new Map<string, number>()
function remember(key: string, data: unknown) {
  cache.delete(key)
  cache.set(key, data)
  cachedAt.set(key, Date.now())
  if (cache.size > CACHE_MAX) {
    const oldest = cache.keys().next().value as string
    cache.delete(oldest)
    cachedAt.delete(oldest)
  }
}

/**
 * Lecture anticipee (survol ou toucher d'un lien) : la page suivante a deja ses donnees en
 * arrivant. Rien n'est relu si la derniere lecture a moins de 20 s ou si elle est en cours.
 */
export function warm(path: string) {
  const token = auth.get()
  if (!token) return
  const key = `${token} ${path}`
  if (inflight.has(key) || Date.now() - (cachedAt.get(key) ?? 0) < 20000) return
  api(path, { toast: false }).catch(() => {})
}

/** Derniere reponse connue d'une lecture (ou undefined) : affichage immediat avant la mise a jour. */
export function peek<T>(path: string): T | undefined {
  return cache.get(`${auth.get() ?? ''} ${path}`) as T | undefined
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
  /** Premier message d'erreur exploitable a afficher. */
  get firstMessage(): string {
    const first = Object.values(this.errors)[0]
    return first?.[0] ?? this.message
  }
}

/**
 * toast : retour visuel automatique des actions (POST/PUT/PATCH/DELETE).
 * - absent : message renvoye par le serveur en cas de succes, message d'erreur sinon ;
 * - texte : message de succes personnalise ;
 * - false : aucune notification (appels d'arriere-plan).
 */
type Options = { method?: string; body?: unknown; auth?: boolean; toast?: string | false }

// Lectures identiques lancees en meme temps (deux blocs d'un meme ecran qui lisent la meme
// donnee) : une seule requete au serveur, le resultat est partage.
const inflight = new Map<string, Promise<unknown>>()

/** Une lecture de moins de 2,5 s est reutilisee telle quelle (page ouverte juste apres un survol,
 *  deux blocs qui lisent la meme donnee) ; toute modification ou tout changement signale par le
 *  pouls la rend aussitot perimee (markStale). */
const FRESH_MS = 2500
export function markStale() { cachedAt.clear() }

export function api<T = unknown>(path: string, opts: Options = {}): Promise<T> {
  const method = (opts.method ?? 'GET').toUpperCase()
  if (method !== 'GET' || opts.body !== undefined) return send<T>(path, method, opts)
  const key = `${opts.auth === false ? '' : auth.get() ?? ''} ${path}`
  const pending = inflight.get(key)
  if (pending) return pending as Promise<T>
  if (opts.auth !== false && cache.has(key) && Date.now() - (cachedAt.get(key) ?? 0) < FRESH_MS) {
    return Promise.resolve(cache.get(key) as T)
  }
  const promise = send<T>(path, method, opts)
    .then((data) => { if (opts.auth !== false) remember(key, data); return data })
    .finally(() => inflight.delete(key))
  inflight.set(key, promise)
  return promise
}

/**
 * Plusieurs lectures d'un meme ecran en un seul appel (/me/home) : chaque chemin est mis
 * « en cours » tout de suite, et les blocs qui le demandent ensuite recoivent la reponse
 * groupee. Si le serveur ne connait pas cet appel ou omet un chemin, ce chemin est lu seul.
 * A appeler pendant le premier rendu de l'ecran, avant les effets de ses blocs.
 */
export function prefetch(paths: string[]) {
  const token = auth.get()
  if (!token) return
  const fresh = (k: string) => cache.has(k) && Date.now() - (cachedAt.get(k) ?? 0) < FRESH_MS
  const todo = paths.filter((p) => !inflight.has(`${token} ${p}`) && !fresh(`${token} ${p}`))
  if (!todo.length) return
  const query = todo.map((p) => `paths[]=${encodeURIComponent(p)}`).join('&')
  const bundle = send<{ responses: Record<string, { status: number; body?: unknown }> }>(`/me/home?${query}`, 'GET', {})
  for (const path of todo) {
    const key = `${token} ${path}`
    const promise = bundle
      .then(
        (r) => {
          const one = r.responses?.[path]
          return one?.status === 200 ? one.body : send(path, 'GET', {})
        },
        // Serveur sans /me/home (version precedente) : lectures une par une. Serveur sature ou
        // injoignable : pas de repli (6 requetes de plus aggraveraient la file) ; chaque bloc
        // affiche son etat d'erreur habituel.
        (err) => {
          if (err instanceof ApiError && (err.status === 404 || err.status === 422)) return send(path, 'GET', {})
          throw err
        },
      )
      .then((data) => { remember(key, data); return data })
      .finally(() => inflight.delete(key))
    inflight.set(key, promise)
    promise.catch(() => { /* chaque bloc qui lit cette donnee gere lui-meme l'erreur */ })
  }
}

/** Rappel apres chaque action reussie de l'utilisateur (le « pouls » s'y abonne : temps reel). */
let changed: (() => void) | null = null
export function onDataChanged(cb: () => void) { changed = cb }

async function send<T>(path: string, method: string, opts: Options): Promise<T> {
  const isAction = method !== 'GET' && opts.toast !== false
  try {
    const data = await request<T>(path, method, opts)
    // Toute ecriture rend les lectures recentes perimees (sauf l'entretien : notifications lues, appareil).
    if (method !== 'GET' && !/^\/(me\/notifications|me\/push|monitor|me\/announcements\/read)/.test(path)) markStale()
    if (isAction) changed?.()
    if (isAction) {
      const text = typeof opts.toast === 'string' ? opts.toast : (data as { message?: unknown } | undefined)?.message
      if (typeof text === 'string' && text && text !== 'ok') toast.success(text)
    }
    return data
  } catch (err) {
    if (isAction && !(err instanceof ApiError && err.status === 401)) {
      toast.error(err instanceof ApiError ? err.firstMessage : 'Connexion impossible. Vérifiez votre réseau puis réessayez.')
    }
    throw err
  }
}

async function request<T>(path: string, method: string, opts: Options): Promise<T> {
  const { body, auth: needAuth = true } = opts

  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = auth.get()
  if (needAuth && token) headers.Authorization = `Bearer ${token}`

  let payload: BodyInit | undefined
  if (body instanceof FormData) {
    payload = body // le navigateur pose le bon Content-Type (multipart)
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    payload = JSON.stringify(body)
  }

  // Lectures (GET) : jusqu'a 2 nouvelles tentatives si le reseau coupe ou si le serveur est
  // momentanement sature (502/503/504), avec une attente croissante et un peu d'aleatoire
  // (tous les appareils ne reessaient pas a la meme seconde). Pas de nouvelle tentative apres
  // un delai depasse : le serveur traite encore la premiere demande, la renvoyer ne ferait
  // qu'allonger la file (mesure lors du test de pic). Les ecritures ne sont jamais renvoyees
  // automatiquement (pas de double enregistrement).
  const retries = method === 'GET' ? 2 : 0
  const timeoutMs = body instanceof FormData ? 90000 : 25000
  let res: Response | null = null
  for (let attempt = 0; ; attempt++) {
    const controller = new AbortController()
    const timer = window.setTimeout(() => controller.abort(), timeoutMs)
    try {
      res = await fetch(`/api${path}`, { method, headers, body: payload, signal: controller.signal })
    } catch (err) {
      res = null
      const aborted = err instanceof DOMException && err.name === 'AbortError'
      if (attempt >= retries || aborted) {
        throw new ApiError(0, aborted ? 'Le serveur met trop de temps à répondre. Réessayez dans un instant.' : 'Connexion impossible. Vérifiez votre réseau puis réessayez.')
      }
    } finally {
      window.clearTimeout(timer)
    }
    if (res && !([502, 503, 504].includes(res.status) && attempt < retries)) break
    const wait = Number(res?.headers.get('Retry-After')) * 1000 || 700 * (attempt + 1)
    await new Promise((r) => window.setTimeout(r, wait + Math.random() * 500))
  }

  if (res.status === 204) return undefined as T

  const data = await res.json().catch(() => ({}))

  if (!res.ok) {
    // Jeton refuse : oublie seulement s'il est encore celui de la session (une reponse tardive ne
    // deconnecte pas un compte ouvert entre-temps). Les appels de connexion (/auth/...) ne
    // signalent pas une session expiree.
    if (res.status === 401 && needAuth && token && auth.get() === token) {
      auth.clear()
      if (!path.startsWith('/auth/')) sessionExpired()
    }
    // Messages techniques (anglais) remplaces par un texte clair, y compris le 404 de Laravel
    // (« No query results for model [App\Models\Event] 42 »).
    const technical = !data.message
      || /^(Server Error|Too Many Attempts\.|This action is unauthorized\.|Unauthenticated\.|Not Found)$/i.test(data.message)
      || (res.status === 404 && /^No query results for model/i.test(data.message))
    const friendly = res.status === 429 ? 'Trop de tentatives. Patientez une minute puis réessayez.'
      : res.status >= 500 ? 'Le serveur a rencontré un problème. Réessayez dans un instant.'
        : res.status === 403 ? "Vous n'avez pas l'autorisation d'effectuer cette action."
          : res.status === 404 ? 'Élément introuvable (il a peut-être été supprimé).'
            : res.status === 401 ? 'Votre session a expiré. Reconnectez-vous.'
              : 'Une erreur est survenue.'
    throw new ApiError(res.status, technical ? friendly : data.message, data.errors ?? {})
  }

  return data as T
}
