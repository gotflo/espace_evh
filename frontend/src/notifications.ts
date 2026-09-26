import type { IconName } from './utils/icons'
// Etat partage des notifications (compteur non lu) entre la cloche, la page
// Notifications et la pastille de l'icone de l'application.
import { useEffect, useState } from 'react'

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

/** Icone (au trait, voir utils/icons.ts) par type de notification. */
export const NOTIF_ICON: Record<string, IconName> = {
  announcement: 'announce',
  event: 'events',
  event_reminder: 'clock',
  task: 'fiss',
  task_reminder: 'clock',
  request: 'requests',
  request_reply: 'mail',
  evaluation: 'star',
  role: 'roles',
  member: 'members',
  service: 'serve',
  birthday: 'gift',
  fiss: 'fiss',
  fiss_request: 'fiss',
  tribe_change: 'swap',
  family: 'members',
  profile: 'profile',
  wedding: 'spiritual',
  activity: 'reports',
  report: 'reports',
  system: 'bell',
}
