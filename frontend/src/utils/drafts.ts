// Brouillons locaux : un texte saisi n'est jamais perdu (reseau coupe, page fermee,
// telephone verrouille). Conserves 14 jours sur l'appareil, effaces apres l'envoi.
// Chaque brouillon appartient a un compte : sur un telephone partage, personne ne voit celui d'un autre.
import { useEffect, useRef } from 'react'

const PREFIX = 'evh_draft:'
const MAX_AGE = 14 * 86400000

let account: number | null = null
let legacyPurged = false

/** Fiche nouvelle : l'ancienne cle n'avait pas de mois, on le retrouve avec la date du brouillon. */
function currentKey(key: string, raw: string): string {
  if (key !== 'fiss:new') return key
  const d = new Date((JSON.parse(raw) as { at: number }).at)
  return `fiss:new:${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}

/**
 * Compte connecte (null : personne, aucun brouillon lu ni ecrit). Appele par AuthContext.
 * resumed : session deja ouverte sur cet appareil. Ses anciens brouillons sans compte lui reviennent (elle les voyait
 * deja, une saisie en cours n'est pas perdue a la mise a jour). Nouvelle connexion : impossible de savoir a qui ils
 * sont, ils ne doivent reapparaitre chez personne.
 */
/**
 * Deconnexion volontaire (« Se déconnecter ») : les brouillons de ce compte sont effaces de l'appareil (FISS,
 * meditation, demandes de priere...) : sur un telephone partage, personne ne peut les relire. Une session qui
 * expire seulement les garde (rien n'est perdu d'une saisie en cours).
 */
export function clearAccountDrafts(id: number | null) {
  if (id === null) return
  try {
    const mine: string[] = []
    for (let i = 0; i < localStorage.length; i++) {
      const k = localStorage.key(i)
      if (k && k.startsWith(`${PREFIX}u${id}:`)) mine.push(k)
    }
    mine.forEach((k) => localStorage.removeItem(k))
  } catch { /* stockage indisponible */ }
}

export function setDraftAccount(id: number | null, resumed = false) {
  account = id
  if (id === null || legacyPurged) return
  legacyPurged = true
  try {
    const old: string[] = []
    for (let i = 0; i < localStorage.length; i++) {
      const k = localStorage.key(i)
      if (k?.startsWith(PREFIX) && !/^u\d+:/.test(k.slice(PREFIX.length))) old.push(k)
    }
    old.forEach((k) => {
      const raw = localStorage.getItem(k)
      if (resumed && raw) {
        try { localStorage.setItem(`${PREFIX}u${id}:${currentKey(k.slice(PREFIX.length), raw)}`, raw) } catch { /* illisible : retire */ }
      }
      localStorage.removeItem(k)
    })
  } catch { /* stockage indisponible */ }
}

const stored = (key: string): string | null => (account === null ? null : `${PREFIX}u${account}:${key}`)

export function readDraft<T>(key: string): T | null {
  return readDraftEntry<T>(key)?.v ?? null
}

/** Brouillon et son heure d'enregistrement (ms) : a comparer a la derniere version du serveur. */
export function readDraftEntry<T>(key: string): { v: T; at: number } | null {
  const full = stored(key)
  if (!full) return null
  try {
    const raw = localStorage.getItem(full)
    if (!raw) return null
    const { v, at } = JSON.parse(raw) as { v: T; at: number }
    if (Date.now() - at > MAX_AGE) { localStorage.removeItem(full); return null }
    return { v, at }
  } catch { return null }
}

export function clearDraft(key: string) {
  const full = stored(key)
  if (!full) return
  try { localStorage.removeItem(full) } catch { /* stockage indisponible */ }
}

/** Enregistre `value` sous `key` (400 ms apres la derniere frappe) ; rien si `key` est null. */
export function useDraft<T>(key: string | null, value: T, isEmpty: (v: T) => boolean) {
  const emptyRef = useRef(isEmpty)
  emptyRef.current = isEmpty
  useEffect(() => {
    const full = key ? stored(key) : null
    if (!full) return
    const t = window.setTimeout(() => {
      try {
        if (emptyRef.current(value)) localStorage.removeItem(full)
        else localStorage.setItem(full, JSON.stringify({ v: value, at: Date.now() }))
      } catch { /* stockage plein ou indisponible */ }
    }, 400)
    return () => window.clearTimeout(t)
  }, [key, value])
}
