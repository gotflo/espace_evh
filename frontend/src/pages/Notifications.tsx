import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { PushSettings } from '../components/PushSettings'
import { SkeletonCard } from '../components/Skeleton'
import { usePulse } from '../pulse'
import { NOTIF_ICON, setUnread, timeAgo, useUnreadCount } from '../notifications'
import type { AppNotification, NotificationPage } from '../types'

/** Regroupement : Aujourd'hui / Hier / Cette semaine / Plus ancien. */
function bucket(iso: string | null): string {
  if (!iso) return 'Plus ancien'
  const d = new Date(iso)
  const today = new Date(); today.setHours(0, 0, 0, 0)
  const diffDays = Math.floor((today.getTime() - new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()) / 86400000)
  if (diffDays <= 0) return "Aujourd'hui"
  if (diffDays === 1) return 'Hier'
  if (diffDays < 7) return 'Cette semaine'
  return 'Plus ancien'
}

export default function Notifications() {
  const navigate = useNavigate()
  const unread = useUnreadCount()
  const [filter, setFilter] = useState<'all' | 'unread'>('all')
  const [items, setItems] = useState<AppNotification[] | null>(null)
  const [hasMore, setHasMore] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)

  const load = useCallback(() => {
    api<NotificationPage>(`/me/notifications?limit=30${filter === 'unread' ? '&unread=1' : ''}`)
      .then((r) => { setItems(r.notifications); setHasMore(r.has_more); setUnread(r.unread) })
      .catch(() => setItems([]))
  }, [filter])

  useEffect(() => { setItems(null); load() }, [load])
  usePulse('unread', load)

  async function more() {
    if (!items?.length) return
    setLoadingMore(true)
    try {
      const r = await api<NotificationPage>(`/me/notifications?limit=30&before=${items[items.length - 1].id}${filter === 'unread' ? '&unread=1' : ''}`)
      setItems([...items, ...r.notifications]); setHasMore(r.has_more)
    } finally { setLoadingMore(false) }
  }

  function open(n: AppNotification) {
    if (!n.read) {
      setItems((prev) => prev?.map((x) => x.id === n.id ? { ...x, read: true } : x) ?? null)
      setUnread(unread - 1)
      api(`/me/notifications/${n.id}/read`, { method: 'POST', toast: false }).catch(() => {})
    }
    if (n.url) navigate(n.url)
  }

  async function remove(n: AppNotification) {
    setItems((prev) => prev?.filter((x) => x.id !== n.id) ?? null)
    if (!n.read) setUnread(unread - 1)
    await api(`/me/notifications/${n.id}`, { method: 'DELETE', toast: false }).catch(() => {})
  }

  async function readAll() {
    setItems((prev) => prev?.map((x) => ({ ...x, read: true })) ?? null)
    setUnread(0)
    await api('/me/notifications/read-all', { method: 'POST', toast: 'Tout est marqué comme lu.' }).catch(() => {})
    if (filter === 'unread') load()
  }

  async function clearRead() {
    if (!confirm('Retirer toutes les notifications déjà lues ?')) return
    await api('/me/notifications/clear-read', { method: 'DELETE' }).catch(() => {})
    load()
  }

  const groups: { label: string; list: AppNotification[] }[] = []
  for (const n of items ?? []) {
    const label = bucket(n.created_at)
    const g = groups.find((x) => x.label === label)
    if (g) g.list.push(n); else groups.push({ label, list: [n] })
  }

  return (
    <AppLayout title="Notifications" subtitle={unread > 0 ? `${unread} non lue(s)` : 'Tout est à jour'}>
      <div className="notif-layout">
        <div>
          <div className="toolbar">
            <div className="tabs">
              <button className={`tab ${filter === 'all' ? 'active' : ''}`} onClick={() => setFilter('all')}>Toutes</button>
              <button className={`tab ${filter === 'unread' ? 'active' : ''}`} onClick={() => setFilter('unread')}>
                Non lues{unread > 0 && <span className="count-pill">{unread}</span>}
              </button>
            </div>
            <div className="toolbar-actions">
              {unread > 0 && <button className="btn-link" onClick={readAll}>Tout marquer lu</button>}
              <button className="btn-link" onClick={clearRead}>Retirer les lues</button>
            </div>
          </div>

          {items === null ? (
            <div className="notif-list"><SkeletonCard /><SkeletonCard /><SkeletonCard /></div>
          ) : items.length === 0 ? (
            <div className="empty-state">
              <span aria-hidden>🔔</span>
              <h3>{filter === 'unread' ? 'Aucune notification non lue' : 'Aucune notification'}</h3>
              <p>Les annonces, rappels d'événements, tâches et réponses apparaîtront ici.</p>
            </div>
          ) : (
            groups.map((g) => (
              <div key={g.label} className="notif-group">
                <h3 className="section-label">{g.label}</h3>
                <div className="notif-list">
                  {g.list.map((n) => (
                    <article key={n.id} className={`notif-row ${n.read ? '' : 'unread'}`}>
                      <button className="notif-main" onClick={() => open(n)}>
                        <span className="notif-icon lg" aria-hidden>{NOTIF_ICON[n.type] ?? '🔔'}</span>
                        <span className="notif-text">
                          <span className="notif-title">{n.title}</span>
                          {n.body && <span className="notif-body">{n.body}</span>}
                          <span className="notif-meta">{n.type_label} · {timeAgo(n.created_at)}</span>
                        </span>
                        {!n.read && <span className="unread-dot" aria-label="Non lue" />}
                      </button>
                      <button className="org-del" onClick={() => remove(n)} aria-label="Retirer">×</button>
                    </article>
                  ))}
                </div>
              </div>
            ))
          )}
          {hasMore && (
            <button className="btn btn-ghost small mt" disabled={loadingMore} onClick={more}>
              {loadingMore ? <span className="spinner" /> : 'Afficher plus'}
            </button>
          )}
        </div>

        <aside className="notif-side">
          <PushSettings />
          <section className="panel">
            <div className="panel-head"><h3>Vous êtes prévenu pour</h3></div>
            <ul className="notif-help">
              <li>📢 Les annonces qui vous concernent</li>
              <li>📅 Les nouveaux événements, et un rappel la veille et 1 h avant</li>
              <li>📝 Les exercices à faire et leur date limite</li>
              <li>🩺 Votre fiche FISS du mois</li>
              <li>✉️ Les réponses à vos demandes</li>
              <li>🎂 Votre anniversaire (et celui de vos fidèles si vous êtes responsable)</li>
            </ul>
          </section>
        </aside>
      </div>
    </AppLayout>
  )
}
