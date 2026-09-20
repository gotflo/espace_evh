import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAutoRefresh } from '../hooks/useAutoRefresh'
import type { EventCategory, MyEvent } from '../types'

const CAT_LABEL: Record<EventCategory, string> = {
  culte: 'Culte', priere: 'Prière', formation: 'Formation',
  reunion: 'Réunion', sortie: 'Sortie', autre: 'Autre',
}

function dayParts(iso: string) {
  const d = new Date(iso)
  return {
    day: d.toLocaleDateString('fr-CA', { day: '2-digit' }),
    month: d.toLocaleDateString('fr-CA', { month: 'short' }),
    time: d.toLocaleTimeString('fr-CA', { hour: '2-digit', minute: '2-digit' }),
    full: d.toLocaleDateString('fr-CA', { weekday: 'long', day: 'numeric', month: 'long' }),
  }
}

export function EventsFeed() {
  const [items, setItems] = useState<MyEvent[]>([])

  const load = useCallback(() => {
    api<{ events: MyEvent[] }>('/me/events').then((r) => setItems(r.events)).catch(() => {})
  }, [])

  useEffect(() => { load() }, [load])
  useAutoRefresh(load)

  async function rsvp(ev: MyEvent, response: 'present' | 'absent', volunteer?: boolean) {
    const vol = volunteer ?? (response === 'present' ? ev.my_volunteer : false)
    // Mise a jour optimiste.
    setItems((prev) => prev.map((e) => e.id === ev.id
      ? {
          ...e, my_response: response, my_volunteer: response === 'present' ? vol : false,
          going_count: e.going_count + (response === 'present' && ev.my_response !== 'present' ? 1 : 0)
            - (response !== 'present' && ev.my_response === 'present' ? 1 : 0),
        }
      : e))
    try { await api(`/me/events/${ev.id}/rsvp`, { method: 'POST', body: { response, volunteer: vol } }) }
    catch { /* on laisse l'etat optimiste ; rechargement au prochain acces */ }
  }

  if (items.length === 0) return null

  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Événements à venir</h3></div>
      <div className="event-grid">
        {items.map((e) => {
          const p = dayParts(e.starts_at)
          return (
            <article key={e.id} className={`event-card evt-border-${e.category}`}>
              <div className="event-date-chip">
                <span className="event-day">{p.day}</span>
                <span className="event-month">{p.month}</span>
              </div>
              <div className="event-body">
                <span className={`cat-badge evt-${e.category}`}>{CAT_LABEL[e.category]}</span>
                <h4 className="event-title">{e.title}</h4>
                <p className="event-meta">🕒 {p.full} · {p.time}{e.location ? ` · 📍 ${e.location}` : ''}</p>

                <div className="rsvp">
                  <button className={`rsvp-btn ${e.my_response === 'present' ? 'on' : ''}`} onClick={() => rsvp(e, 'present')}>Je serai présent</button>
                  <button className={`rsvp-btn ${e.my_response === 'absent' ? 'off' : ''}`} onClick={() => rsvp(e, 'absent')}>Absent</button>
                  {e.going_count > 0 && <span className="rsvp-count">{e.going_count} inscrit(s)</span>}
                </div>
                {e.my_response === 'present' && (
                  <label className="rsvp-volunteer">
                    <input type="checkbox" checked={e.my_volunteer} onChange={(ev) => rsvp(e, 'present', ev.target.checked)} />
                    Je souhaite me porter volontaire
                  </label>
                )}
              </div>
            </article>
          )
        })}
      </div>
    </section>
  )
}
