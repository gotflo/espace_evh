// Client API : appels au backend Laravel via /api (proxy Vite en dev).
import { toast } from '../toast'

const TOKEN_KEY = 'evh_token'

export const auth = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t: string) => localStorage.setItem(TOKEN_KEY, t),
  clear: () => localStorage.removeItem(TOKEN_KEY),
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

export async function api<T = unknown>(path: string, opts: Options = {}): Promise<T> {
  const method = (opts.method ?? 'GET').toUpperCase()
  const isAction = method !== 'GET' && opts.toast !== false
  try {
    const data = await request<T>(path, method, opts)
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
  // (tous les appareils ne reessaient pas a la meme seconde). Les ecritures ne sont jamais
  // renvoyees automatiquement (pas de double enregistrement).
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
      if (attempt >= retries) {
        const aborted = err instanceof DOMException && err.name === 'AbortError'
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
    if (res.status === 401) auth.clear()
    // Messages techniques (anglais) remplaces par un texte clair.
    const technical = !data.message || /^(Server Error|Too Many Attempts\.|This action is unauthorized\.|Unauthenticated\.|Not Found)$/i.test(data.message)
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
