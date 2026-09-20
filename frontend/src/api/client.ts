// Client API : appels au backend Laravel via /api (proxy Vite en dev).

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

type Options = { method?: string; body?: unknown; auth?: boolean }

export async function api<T = unknown>(path: string, opts: Options = {}): Promise<T> {
  const { method = 'GET', body, auth: needAuth = true } = opts

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
    throw new ApiError(res.status, data.message ?? 'Une erreur est survenue.', data.errors ?? {})
  }

  return data as T
}
