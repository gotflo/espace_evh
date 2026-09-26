import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { NOTIF_ICON, setUnread, timeAgo, useUnreadCount } from '../notifications'
import { startPulse } from '../pulse'
import type { AppNotification, NotificationPage } from '../types'

export function NotificationBell() {
  const count = useUnreadCount()
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<AppNotification[] | null>(null)
  const ref = useRef<HTMLDivElement>(null)
  const navigate = useNavigate()

  const loadList = useCallback(() => {
    api<NotificationPage>('/me/notifications?limit=8')
      .then((r) => { setItems(r.notifications); setUnread(r.unread) })
      .catch(() => setItems([]))
  }, [])

  // Le compteur est tenu a jour par le pouls de l'application (pulse.ts) : toutes les ~45 s,
  // au retour sur l'appli et a chaque push recu.
  useEffect(() => { startPulse() }, [])

  useEffect(() => {
    function onDoc(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) setOpen(false)
    }
    function onKey(e: KeyboardEvent) { if (e.key === 'Escape') setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    document.addEventListener('keydown', onKey)
    return () => { document.removeEventListener('mousedown', onDoc); document.removeEventListener('keydown', onKey) }
  }, [])

  function toggle() {
    const next = !open
    setOpen(next)
    if (next) loadList()
  }

  async function openItem(n: AppNotification) {
    setOpen(false)
    if (!n.read) {
      setItems((prev) => prev?.map((x) => x.id === n.id ? { ...x, read: true } : x) ?? null)
      setUnread(count - 1)
      api(`/me/notifications/${n.id}/read`, { method: 'POST', toast: false }).catch(() => {})
    }
    if (n.url) navigate(n.url)
  }

  async function readAll() {
    setItems((prev) => prev?.map((x) => ({ ...x, read: true })) ?? null)
    setUnread(0)
    await api('/me/notifications/read-all', { method: 'POST', toast: false }).catch(() => {})
  }

  return (
    <div className="bell" ref={ref}>
      <button className="bell-btn" onClick={toggle} aria-label={`Notifications${count ? ` (${count} non lues)` : ''}`} aria-expanded={open}>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0" /></svg>
        {count > 0 && <span className="bell-badge">{count > 9 ? '9+' : count}</span>}
      </button>

      {open && (
        <div className="bell-menu" role="dialog" aria-label="Notifications">
          <div className="bell-head">
            <span>Notifications</span>
            {count > 0 && <button className="btn-link" onClick={readAll}>Tout marquer lu</button>}
          </div>
          <div className="bell-list">
            {items === null && <p className="helper" style={{ padding: '0.8rem 1rem' }}>Chargement…</p>}
            {items?.map((n) => (
              <button key={n.id} className={`bell-item ${n.read ? '' : 'unread'}`} onClick={() => openItem(n)}>
                <span className="notif-icon" aria-hidden>{NOTIF_ICON[n.type] ?? '🔔'}</span>
                <span className="bell-item-text">
                  <span className="bell-item-title">{n.title}</span>
                  {n.body && <span className="bell-item-body">{n.body}</span>}
                  <span className="bell-item-meta">{n.type_label} · {timeAgo(n.created_at)}</span>
                </span>
                {!n.read && <span className="unread-dot" aria-label="Non lue" />}
              </button>
            ))}
            {items?.length === 0 && (
              <div className="bell-empty">
                <span aria-hidden>🔔</span>
                <p>Aucune notification pour l'instant.</p>
              </div>
            )}
          </div>
          <button className="bell-foot" onClick={() => { setOpen(false); navigate('/notifications') }}>
            Voir toutes les notifications
          </button>
        </div>
      )}
    </div>
  )
}
