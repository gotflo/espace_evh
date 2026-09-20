import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAutoRefresh } from '../hooks/useAutoRefresh'
import type { MyAnnouncement } from '../types'

const CAT: Record<string, string> = { info: 'Info', important: 'Important', evenement: 'Événement' }

export function AnnouncementsFeed() {
  const [items, setItems] = useState<MyAnnouncement[]>([])

  const load = useCallback(() => {
    api<{ announcements: MyAnnouncement[] }>('/me/announcements').then((r) => setItems(r.announcements)).catch(() => {})
  }, [])

  useEffect(() => { load() }, [load])
  useAutoRefresh(load)

  if (items.length === 0) return null

  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Annonces</h3></div>
      <div className="feed">
        {items.map((a) => (
          <article key={a.id} className={`feed-item ${a.read ? '' : 'unread'} cat-border-${a.category}`}>
            <div className="feed-head">
              <span className={`cat-badge cat-${a.category}`}>{CAT[a.category]}</span>
              <span className="feed-date">{a.created_at}</span>
            </div>
            {a.title && <h4 className="feed-title">{a.title}</h4>}
            {a.body && <p className="feed-body">{a.body}</p>}
            {a.image_url && <img className="feed-image" src={a.image_url} alt={a.title ?? 'Annonce'} loading="lazy" />}
          </article>
        ))}
      </div>
    </section>
  )
}
