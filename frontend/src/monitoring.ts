// Remontee des erreurs d'affichage vers la supervision (console > Journaux, source « Navigateur »).
// Seulement le message, l'extrait de pile et le chemin de la page (jamais les parametres d'URL ni
// le contenu saisi). Bornee : au plus 10 envois par page ouverte, une meme erreur une seule fois.
import { auth } from './api/client'
import { isStaleBuildError } from './utils/reload'

type Kind = 'error' | 'unhandledrejection' | 'render' | 'chunk'

const sent = new Set<string>()
let count = 0

export function reportClientError(kind: Kind, error: unknown, extra: { source?: string; line?: number; column?: number; component?: string } = {}) {
  try {
    const err = error instanceof Error ? error : null
    const message = (err ? `${err.name}: ${err.message}` : String(error ?? 'Erreur inconnue')).slice(0, 1000)
    // Mise a jour du site (ancien fichier introuvable) : rechargement prevu, pas une panne.
    if (isStaleBuildError(error) || /ResizeObserver loop/i.test(message)) return
    const key = `${kind}|${message}`
    if (sent.has(key) || count >= 10) return
    sent.add(key)
    count++

    const body = JSON.stringify({
      kind,
      message,
      stack: err?.stack?.slice(0, 6000),
      source: extra.source?.slice(0, 300),
      line: extra.line,
      column: extra.column,
      component: extra.component?.slice(0, 2000),
      path: window.location.pathname,
    })
    const headers: Record<string, string> = { 'Content-Type': 'application/json', Accept: 'application/json' }
    const token = auth.get()
    if (token) headers.Authorization = `Bearer ${token}`
    void fetch('/api/monitor/client-errors', { method: 'POST', headers, body, keepalive: true }).catch(() => {})
  } catch {
    // la remontee ne doit jamais provoquer d'erreur elle-meme
  }
}

export function installClientErrorReporting() {
  window.addEventListener('error', (e) => {
    // Erreur de chargement d'une ressource (image...) : sans objet ici.
    if (!(e instanceof ErrorEvent) || (!e.error && !e.message)) return
    reportClientError('error', e.error ?? e.message, { source: e.filename, line: e.lineno, column: e.colno })
  })
  window.addEventListener('unhandledrejection', (e) => {
    // Erreurs d'API deja traitees (message a l'ecran) : seules les vraies erreurs de code remontent.
    const reason = e.reason
    if (reason && typeof reason === 'object' && 'status' in reason) return
    reportClientError('unhandledrejection', reason)
  })
}
