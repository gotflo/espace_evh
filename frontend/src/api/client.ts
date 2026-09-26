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

  const res = await fetch(`/api${path}`, { method, headers, body: payload })

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
