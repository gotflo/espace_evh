import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { previousVisit } from '../utils/visit'

interface WhatsNew {
  since: string
  announcements: { count: number; items: { id: number; title: string }[] }
  events: { count: number; items: { id: number; title: string; date: string }[] }
  exercises: { count: number; items: { id: number; title: string }[] }
  unread: number
}

const DISMISSED_KEY = 'evh_welcome_back_seen'
const ABSENCE_DAYS = 4

const day = (iso: string) => new Date(`${iso}T12:00:00`).toLocaleDateString('fr-CA', { weekday: 'short', day: 'numeric', month: 'short' })

/**
 * « Content de vous revoir » : apres plus de 4 jours d'absence, un resume de ce qui est
 * arrive entre-temps (annonces, evenements, exercices), avec un lien direct pour chacun.
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
  const nothing = !data.announcements.count && !data.events.count && !data.exercises.count

  return (
    <section className="welcome-back" role="status" aria-label="Nouveautés depuis votre dernière visite">
      <button className="welcome-close" onClick={close} aria-label="Fermer">×</button>
      <h2>Content de vous revoir{profile?.first_name ? `, ${profile.first_name}` : ''} 👋</h2>
      {nothing ? (
        <p>Tout est à jour. Bonne visite !</p>
      ) : (
        <>
          <p>Depuis votre dernière visite :</p>
          <ul>
            {data.announcements.count > 0 && (
              <li><span aria-hidden>📢</span> <Link to="/tableau-de-bord#annonces" onClick={close}>{data.announcements.count} annonce(s)</Link>
                {data.announcements.items[0] && <small> · {data.announcements.items[0].title}</small>}</li>
            )}
            {data.events.count > 0 && (
              <li><span aria-hidden>📅</span> <Link to={`/calendrier?date=${data.events.items[0]?.date ?? ''}`} onClick={close}>{data.events.count} nouvel(s) événement(s) à venir</Link>
                {data.events.items[0] && <small> · {data.events.items[0].title}, {day(data.events.items[0].date)}</small>}</li>
            )}
            {data.exercises.count > 0 && (
              <li><span aria-hidden>🎬</span> <Link to={data.exercises.count === 1 ? `/exercices/${data.exercises.items[0].id}` : '/exercices'} onClick={close}>{data.exercises.count} exercice(s) pour vous</Link>
                {data.exercises.items[0] && <small> · {data.exercises.items[0].title}</small>}</li>
            )}
          </ul>
        </>
      )}
    </section>
  )
}
