import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { CalendarData, EventOccurrence } from '../types'

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const time = (iso: string) => {
  const d = new Date(iso)
  return `${d.getHours()}h${String(d.getMinutes()).padStart(2, '0')}`
}

/**
 * Programme regulier de l'eglise (rendez-vous « rappel a tous » : cultes, priere...),
 * regroupe par jour de la semaine a partir des 7 prochains jours du calendrier.
 */
export function ServiceSchedule() {
  const [days, setDays] = useState<{ day: string; date: string; items: EventOccurrence[] }[] | null>(null)

  useEffect(() => {
    const from = new Date()
    const to = new Date(from.getTime() + 6 * 86400000)
    api<CalendarData>(`/calendar?from=${ymd(from)}&to=${ymd(to)}`, { toast: false }).then((r) => {
      const byDay = new Map<string, EventOccurrence[]>()
      r.events.filter((e) => e.remind_all).sort((a, b) => a.starts_at.localeCompare(b.starts_at)).forEach((e) => {
        const l = byDay.get(e.occurs_on)
        if (l) l.push(e); else byDay.set(e.occurs_on, [e])
      })
      setDays([...byDay.entries()].map(([date, items]) => ({
        date, items,
        day: new Date(`${date}T12:00:00`).toLocaleDateString('fr-CA', { weekday: 'long' }),
      })))
    }).catch(() => setDays([]))
  }, [])

  if (!days || days.length === 0) return null

  return (
    <section className="panel service-schedule" aria-label="Nos rendez-vous">
      <div className="panel-head"><h3>Nos rendez-vous</h3><Link className="btn-link" to="/calendrier">Calendrier</Link></div>
      {days.map((d) => (
        <div key={d.date} className="service-day">
          <Link to={`/calendrier?date=${d.date}`} className="service-day-name">{d.day}</Link>
          <ul className="service-times">
            {d.items.map((e) => (
              <li key={e.key}>
                <span className="service-time">{e.ends_at && d.items.length === 1 ? `${time(e.starts_at)} à ${time(e.ends_at)}` : time(e.starts_at)}</span>
                <span className="service-title">{e.title}</span>
              </li>
            ))}
          </ul>
        </div>
      ))}
      <p className="helper">Un rappel vous est envoyé la veille au soir et 30 min avant chaque rendez-vous.</p>
    </section>
  )
}
