// Brouillons locaux : un texte saisi n'est jamais perdu (reseau coupe, page fermee,
// telephone verrouille). Conserves 14 jours sur l'appareil, effaces apres l'envoi.
import { useEffect, useRef } from 'react'

const PREFIX = 'evh_draft:'
const MAX_AGE = 14 * 86400000

export function readDraft<T>(key: string): T | null {
  try {
    const raw = localStorage.getItem(PREFIX + key)
    if (!raw) return null
    const { v, at } = JSON.parse(raw) as { v: T; at: number }
    if (Date.now() - at > MAX_AGE) { localStorage.removeItem(PREFIX + key); return null }
    return v
  } catch { return null }
}

export function clearDraft(key: string) {
  try { localStorage.removeItem(PREFIX + key) } catch { /* stockage indisponible */ }
}

/** Enregistre `value` sous `key` (400 ms apres la derniere frappe) ; rien si `key` est null. */
export function useDraft<T>(key: string | null, value: T, isEmpty: (v: T) => boolean) {
  const emptyRef = useRef(isEmpty)
  emptyRef.current = isEmpty
  useEffect(() => {
    if (!key) return
    const t = window.setTimeout(() => {
      try {
        if (emptyRef.current(value)) localStorage.removeItem(PREFIX + key)
        else localStorage.setItem(PREFIX + key, JSON.stringify({ v: value, at: Date.now() }))
      } catch { /* stockage plein ou indisponible */ }
    }, 400)
    return () => window.clearTimeout(t)
  }, [key, value])
}
