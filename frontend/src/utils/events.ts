import { api } from '../api/client'
import type { EventCategory, EventOccurrence } from '../types'

export const CAT_LABEL: Record<EventCategory, string> = {
  culte: 'Culte', priere: 'Prière', formation: 'Formation',
  reunion: 'Réunion', sortie: 'Sortie', autre: 'Autre',
}

/** Date locale au format AAAA-MM-JJ (sans decalage UTC). */
export function ymd(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}

/** AAAA-MM-JJ -> Date locale a minuit. */
export function parseYmd(s: string): Date {
  const [y, m, d] = s.split('-').map(Number)
  return new Date(y, m - 1, d)
}

/** « 19h00 » */
export function hhmm(iso: string): string {
  const d = new Date(iso)
  return `${d.getHours()}h${String(d.getMinutes()).padStart(2, '0')}`
}

/** « Aujourd'hui », « Demain », « Dans 3 jours », sinon null. */
export function relativeDay(dateYmd: string): string | null {
  const today = parseYmd(ymd(new Date()))
  const diff = Math.round((parseYmd(dateYmd).getTime() - today.getTime()) / 86400000)
  if (diff === 0) return "Aujourd'hui"
  if (diff === 1) return 'Demain'
  if (diff > 1 && diff < 7) return `Dans ${diff} jours`
  return null
}

export function longDay(dateYmd: string): string {
  const s = parseYmd(dateYmd).toLocaleDateString('fr-CA', { weekday: 'long', day: 'numeric', month: 'long' })
  return s.charAt(0).toUpperCase() + s.slice(1)
}

/** Horaire lisible d'une occurrence : « 19h00 – 21h00 » ou « Toute la journée ». */
export function timeRange(e: EventOccurrence): string {
  if (e.all_day) return 'Toute la journée'
  return e.ends_at && ymd(new Date(e.ends_at)) === e.occurs_on
    ? `${hhmm(e.starts_at)} – ${hhmm(e.ends_at)}`
    : hhmm(e.starts_at)
}

/** Reponse a une occurrence precise ; renvoie l'occurrence mise a jour (optimiste). */
export async function rsvpOccurrence(e: EventOccurrence, response: 'present' | 'absent', volunteer?: boolean): Promise<EventOccurrence> {
  const vol = response === 'present' ? (volunteer ?? e.my_volunteer) : false
  const r = await api<{ going_count: number }>(`/me/events/${e.event_id}/rsvp`, {
    method: 'POST', body: { response, volunteer: vol, date: e.occurs_on },
  })
  return { ...e, my_response: response, my_volunteer: vol, going_count: r.going_count }
}

export function optimisticRsvp(e: EventOccurrence, response: 'present' | 'absent', volunteer?: boolean): EventOccurrence {
  const vol = response === 'present' ? (volunteer ?? e.my_volunteer) : false
  const going = e.going_count
    + (response === 'present' && e.my_response !== 'present' ? 1 : 0)
    - (response !== 'present' && e.my_response === 'present' ? 1 : 0)
  return { ...e, my_response: response, my_volunteer: vol, going_count: Math.max(0, going) }
}
