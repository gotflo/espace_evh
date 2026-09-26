import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { usePulse } from '../pulse'
import type { EventOccurrence } from '../types'
import { CAT_LABEL, longDay, optimisticRsvp, parseYmd, relativeDay, rsvpOccurrence, timeRange } from '../utils/events'
import { Skeleton } from './Skeleton'

/**
 * « Evenements a venir – Les 30 prochains jours » : visible par tous, regroupe par jour,
 * evenements recurrents deroules, reponse (present / absent / volontaire) par occurrence.
 */
export function EventsFeed({ days = 30, limit = 8 }: { days?: number; limit?: number }) {
  const navigate = useNavigate()
  const [items, setItems] = useState<EventOccurrence[] | null>(null)
  const [expanded, setExpanded] = useState(false)
  const [open, setOpen] = useState<string | null>(null)

  const load = useCallback(() => {
    api<{ events: EventOccurrence[] }>(`/me/events?days=${days}`).then((r) => setItems(r.events)).catch(() => setItems((p) => p ?? []))
  }, [days])

  useEffect(() => { load() }, [load])
  usePulse('events', load)

  async function rsvp(ev: EventOccurrence, response: 'present' | 'absent', volunteer?: boolean) {
    setItems((prev) => prev?.map((e) => e.key === ev.key ? optimisticRsvp(e, response, volunteer) : e) ?? null)
    try {
      const updated = await rsvpOccurrence(ev, response, volunteer)
      setItems((prev) => prev?.map((e) => e.key === ev.key ? updated : e) ?? null)
    } catch { load() }
  }

  const visible = expanded ? items ?? [] : (items ?? []).slice(0, limit)
  const groups: { date: string; list: EventOccurrence[] }[] = []
  for (const e of visible) {
    const g = groups.find((x) => x.date === e.occurs_on)
    if (g) g.list.push(e); else groups.push({ date: e.occurs_on, list: [e] })
  }

  return (
    <section className="panel upcoming" id="evenements">
      <div className="panel-head">
        <div>
          <h3>Événements à venir</h3>
          <p className="panel-sub">Les {days} prochains jours{items && items.length > 0 ? ` · ${items.length} événement(s)` : ''}</p>
        </div>
        <button className="btn-link" onClick={() => navigate('/calendrier')}>Calendrier →</button>
      </div>

      {items === null && (
        <div className="upcoming-list">
          {[0, 1, 2].map((i) => <Skeleton key={i} className="skeleton-row" />)}
        </div>
      )}

      {items?.length === 0 && (
        <div className="empty-state compact">
          <span aria-hidden>📅</span>
          <p>Aucun événement prévu dans les {days} prochains jours.</p>
        </div>
      )}

      <div className="upcoming-list">
        {groups.map((g) => {
          const d = parseYmd(g.date)
          const rel = relativeDay(g.date)
          return (
            <div key={g.date} className="upcoming-day">
              <div className={`upcoming-date ${rel === "Aujourd'hui" ? 'today' : ''}`}>
                <span className="upcoming-dow">{d.toLocaleDateString('fr-CA', { weekday: 'short' }).replace('.', '')}</span>
                <span className="upcoming-num">{d.getDate()}</span>
                <span className="upcoming-mon">{d.toLocaleDateString('fr-CA', { month: 'short' }).replace('.', '')}</span>
              </div>
              <div className="upcoming-events">
                {rel && <span className="upcoming-rel">{rel}</span>}
                {g.list.map((e) => {
                  const isOpen = open === e.key
                  return (
                    <article key={e.key} className={`upcoming-item evt-border-${e.category}`}>
                      <button className="upcoming-head" onClick={() => setOpen(isOpen ? null : e.key)} aria-expanded={isOpen}>
                        <span className="upcoming-time">{timeRange(e)}</span>
                        <span className="upcoming-title">
                          {e.recurring && <span className="recur-icon" title={e.recurrence_label ?? 'Récurrent'} aria-label="Récurrent">↻</span>}
                          {e.title}
                        </span>
                        <span className="upcoming-meta">
                          <span className={`cat-badge evt-${e.category}`}>{CAT_LABEL[e.category]}</span>
                          {e.location && <span>📍 {e.location}</span>}
                          {e.my_response === 'present' && <span className="going-tag">✓ Inscrit</span>}
                        </span>
                      </button>
                      {isOpen && (
                        <div className="upcoming-detail">
                          <p className="event-meta">{longDay(e.occurs_on)} · {timeRange(e)}{e.recurring ? ` · ${e.recurrence_label}` : ''}</p>
                          <p className="event-meta-soft">{e.target}</p>
                          {e.description && <p className="event-desc">{e.description}</p>}
                          {e.image_url && <img className="event-image mt" src={e.image_url} alt={e.title} loading="lazy" />}
                        </div>
                      )}
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
                    </article>
                  )
                })}
              </div>
            </div>
          )
        })}
      </div>

      {items && items.length > limit && (
        <button className="btn-link upcoming-more" onClick={() => setExpanded(!expanded)}>
          {expanded ? 'Réduire' : `Voir les ${items.length - limit} autre(s)`}
        </button>
      )}
    </section>
  )
}
