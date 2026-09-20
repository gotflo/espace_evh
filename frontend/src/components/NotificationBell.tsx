import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../api/client'
import type { MyAnnouncement } from '../types'

const CAT: Record<string, string> = { info: 'Info', important: 'Important', evenement: 'Événement' }

export function NotificationBell() {
  const [count, setCount] = useState(0)
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<MyAnnouncement[]>([])
  const ref = useRef<HTMLDivElement>(null)

  const loadCount = useCallback(() => {
    api<{ count: number }>('/me/announcements/unread-count').then((r) => setCount(r.count)).catch(() => {})
  }, [])

  // Rafraichit le compteur automatiquement (quasi temps reel) toutes les 30 s.
  useEffect(() => {
    loadCount()
    const id = setInterval(loadCount, 30000)
    const onFocus = () => loadCount()
    window.addEventListener('focus', onFocus)
    return () => { clearInterval(id); window.removeEventListener('focus', onFocus) }
  }, [loadCount])

  useEffect(() => {
    function onDoc(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  async function toggle() {
    const next = !open
    setOpen(next)
    if (next) {
      const r = await api<{ announcements: MyAnnouncement[] }>('/me/announcements')
      setItems(r.announcements.slice(0, 8))
      if (count > 0) { await api('/me/announcements/read', { method: 'POST' }); setCount(0) }
    }
  }

  return (
    <div className="bell" ref={ref}>
      <button className="bell-btn" onClick={toggle} aria-label="Notifications">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0" /></svg>
        {count > 0 && <span className="bell-badge">{count > 9 ? '9+' : count}</span>}
      </button>

      {open && (
        <div className="bell-menu">
          <div className="bell-head">Notifications</div>
          <div className="bell-list">
            {items.map((a) => (
              <div key={a.id} className={`bell-item ${a.read ? '' : 'unread'}`}>
                <span className={`cat-dot cat-${a.category}`} />
                <div>
                  <span className="bell-item-title">{a.title}</span>
                  <span className="bell-item-meta">{CAT[a.category]} · {a.created_at}</span>
                </div>
              </div>
            ))}
            {items.length === 0 && <p className="helper" style={{ padding: '0.6rem' }}>Aucune notification.</p>}
          </div>
        </div>
      )}
    </div>
  )
}
