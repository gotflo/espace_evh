import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { previousVisit } from '../utils/visit'
import { Icon } from './Icon'

interface WhatsNew {
  since: string
  announcements: { count: number; items: { id: number; title: string }[] }
  events: { count: number; items: { id: number; title: string; date: string }[] }
  exercises: { count: number; items: { id: number; title: string }[] }
  assignments?: { count: number; items: { id: number; title: string }[] }
  unread: number
}

const DISMISSED_KEY = 'evh_welcome_back_seen'
const ABSENCE_DAYS = 4

const day = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('fr-CA', { weekday: 'short', day: 'numeric', month: 'short' })

/**
 * « Content de vous revoir » : apres plus de 4 jours d'absence, un resume de ce qui est
 * arrive entre-temps (annonces, evenements, exercices, devoirs), avec un lien direct pour chacun.
 */
export function WelcomeBack() {
  const { profile } = useAuth()
  const [data, setData] = useState<WhatsNew | null>(null)
  const since = previousVisit()
  const absent = since !== null && Date.now() - new Date(since).getTime() > ABSENCE_DAYS * 86400000

  useEffect(() => {
    if (!absent || !since) return
    try { if (sessionStorage.getItem(DISMISSED_KEY) === since) return } catch { /* rien */ }
    api<WhatsNew>(`/me/welcome-back?since=${encodeURIComponent(since)}`, { toast: false }).then(setData).catch(() => {})
  }, [absent, since])

  if (!data) return null
  const close = () => {
    try { sessionStorage.setItem(DISMISSED_KEY, since ?? '') } catch { /* rien */ }
    setData(null)
  }
  const devoirs = data.assignments ?? { count: 0, items: [] }
  const nothing = !data.announcements.count && !data.events.count && !data.exercises.count && !devoirs.count

  return (
    <section className="welcome-back" role="status" aria-label="Nouveautés depuis votre dernière visite">
      <button className="welcome-close" onClick={close} aria-label="Fermer">×</button>
      <h2>Content de vous revoir{profile?.first_name ? `, ${profile.first_name}` : ''}</h2>
      {nothing ? (
        <p>Tout est à jour. Bonne visite !</p>
      ) : (
        <>
          <p>Depuis votre dernière visite :</p>
          <ul>
            {data.announcements.count > 0 && (
              <li><Icon name="announce" size={16} /> <Link to="/tableau-de-bord#annonces" onClick={close}>{data.announcements.count} annonce(s)</Link>
                {data.announcements.items[0] && <small> · {data.announcements.items[0].title}</small>}</li>
            )}
            {data.events.count > 0 && (
              <li><Icon name="events" size={16} /> <Link to={`/calendrier?date=${data.events.items[0]?.date ?? ''}`} onClick={close}>{data.events.count} nouvel(s) événement(s) à venir</Link>
                {data.events.items[0] && <small> · {data.events.items[0].title}, {day(data.events.items[0].date)}</small>}</li>
            )}
            {data.exercises.count > 0 && (
              <li><Icon name="play" size={16} /> <Link to={data.exercises.count === 1 ? `/exercices/${data.exercises.items[0].id}` : '/exercices'} onClick={close}>{data.exercises.count} vidéo(s), lecture(s) ou méditation(s) pour vous</Link>
                {data.exercises.items[0] && <small> · {data.exercises.items[0].title}</small>}</li>
            )}
            {devoirs.count > 0 && (
              <li><Icon name="devoir" size={16} /> <Link to={devoirs.count === 1 ? `/mon-devoir?d=${devoirs.items[0].id}` : '/mon-devoir'} onClick={close}>{devoirs.count} nouveau{devoirs.count > 1 ? 'x' : ''} devoir{devoirs.count > 1 ? 's' : ''} (DS)</Link>
                {devoirs.items[0] && <small> · {devoirs.items[0].title}</small>}</li>
            )}
          </ul>
        </>
      )}
    </section>
  )
}
