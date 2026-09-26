// Etat partage des notifications (compteur non lu) entre la cloche, la page
// Notifications et la pastille de l'icone de l'application.
import { useEffect, useState } from 'react'
import { api } from './api/client'

type Listener = (count: number) => void
let unread = 0
const listeners = new Set<Listener>()

export function setUnread(count: number) {
  unread = Math.max(0, count)
  listeners.forEach((l) => l(unread))
  // Pastille sur l'icone de l'application installee (si supporte).
  const nav = navigator as Navigator & { setAppBadge?: (n?: number) => Promise<void>; clearAppBadge?: () => Promise<void> }
  try {
    if (unread > 0) nav.setAppBadge?.(unread)?.catch(() => {})
    else nav.clearAppBadge?.()?.catch(() => {})
  } catch { /* non supporte */ }
}

export function getUnread(): number { return unread }

export async function refreshUnread(): Promise<number> {
  try {
    const r = await api<{ count: number }>('/me/notifications/unread-count')
    setUnread(r.count)
  } catch { /* hors ligne */ }
  return unread
}

export function useUnreadCount(): number {
  const [count, setCount] = useState(unread)
  useEffect(() => {
    listeners.add(setCount)
    return () => { listeners.delete(setCount) }
  }, [])
  return count
}

/** « a l'instant », « il y a 5 min », « hier », « 12 sept. » */
export function timeAgo(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  const diff = (Date.now() - d.getTime()) / 1000
  if (diff < 60) return "à l'instant"
  if (diff < 3600) return `il y a ${Math.floor(diff / 60)} min`
  if (diff < 86400) return `il y a ${Math.floor(diff / 3600)} h`
  if (diff < 172800) return 'hier'
  if (diff < 604800) return `il y a ${Math.floor(diff / 86400)} j`
  return d.toLocaleDateString('fr-CA', { day: 'numeric', month: 'short' })
}

/** Icone (emoji) par type de notification. */
export const NOTIF_ICON: Record<string, string> = {
  announcement: '📢',
  event: '📅',
  event_reminder: '⏰',
  task: '📝',
  task_reminder: '📝',
  request: '💬',
  request_reply: '✉️',
  evaluation: '⭐',
  role: '🎖️',
  member: '👋',
  service: '🙌',
  birthday: '🎂',
  fiss: '🩺',
  system: '🔔',
}
