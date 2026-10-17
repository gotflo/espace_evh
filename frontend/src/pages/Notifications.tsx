import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError, peek } from '../api/client'
import { toast } from '../toast'
import { addDays, ymd } from '../utils/events'
import { AppLayout } from '../components/AppLayout'
import { PushSettings } from '../components/PushSettings'
import { NotificationPrefs } from '../components/NotificationPrefs'
import { SkeletonCard } from '../components/Skeleton'
import { usePulse } from '../pulse'
import { getUnread, NOTIF_ICON, setUnread, timeAgo, useUnreadCount } from '../notifications'
import type { AppNotification, NotificationPage } from '../types'
import { Icon } from '../components/Icon'

/**
 * Regroupement : Aujourd'hui / Hier / Cette semaine / Plus ancien. Jours de calendrier compares
 * (AAAA-MM-JJ), et non des tranches de 24 h : juste aussi le lendemain d'un changement d'heure.
 */
function bucket(iso: string | null): string {
  if (!iso) return 'Plus ancien'
  const day = ymd(new Date(iso))
  const today = ymd(new Date())
  if (day >= today) return "Aujourd'hui"
  if (day === addDays(today, -1)) return 'Hier'
  if (day > addDays(today, -7)) return 'Cette semaine'
  return 'Plus ancien'
}

export default function Notifications() {
  const navigate = useNavigate()
  const unread = useUnreadCount()
  const [filter, setFilter] = useState<'all' | 'unread'>('all')
  const [items, setItems] = useState<AppNotification[] | null>(() => peek<NotificationPage>('/me/notifications?limit=30')?.notifications ?? null)
  const [hasMore, setHasMore] = useState(() => peek<NotificationPage>('/me/notifications?limit=30')?.has_more ?? false)
  const [loadingMore, setLoadingMore] = useState(false)

  const load = useCallback(() => {
    api<NotificationPage>(`/me/notifications?limit=30${filter === 'unread' ? '&unread=1' : ''}`)
      .then((r) => { setItems(r.notifications); setHasMore(r.has_more); setUnread(r.unread) })
      .catch(() => setItems([]))
  }, [filter])

  // Liste deja vue : affichee tout de suite, la mise a jour se fait en silence.
  useEffect(() => {
    const known = peek<NotificationPage>(`/me/notifications?limit=30${filter === 'unread' ? '&unread=1' : ''}`)
    setItems(known?.notifications ?? null)
    if (known) setHasMore(known.has_more)
    load()
  }, [load, filter])
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
    const at = items?.findIndex((x) => x.id === n.id) ?? -1
    setItems((prev) => prev?.filter((x) => x.id !== n.id) ?? null)
    if (!n.read) setUnread(unread - 1)
    try {
      await api(`/me/notifications/${n.id}`, { method: 'DELETE', toast: false })
    } catch (err) {
      if (err instanceof ApiError && err.status === 404) return // deja retiree (autre appareil)
      // Suppression refusee ou reseau coupe : la notification revient a sa place, avec l'erreur.
      setItems((prev) => (!prev || prev.some((x) => x.id === n.id) ? prev
        : at < 0 ? [n, ...prev] : [...prev.slice(0, at), n, ...prev.slice(at)]))
      if (!n.read) setUnread(getUnread() + 1)
      toast.error(err instanceof ApiError ? err.firstMessage : 'La notification n’a pas pu être retirée. Réessayez.')
    }
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
              <Icon name="bell" size={28} />
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
                        <span className="notif-icon lg"><Icon name={NOTIF_ICON[n.type] ?? 'bell'} size={18} /></span>
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
          <NotificationPrefs />
          <section className="panel">
            <div className="panel-head"><h3>Vous êtes prévenu pour</h3></div>
            <ul className="notif-help">
              <li>Les annonces qui vous concernent</li>
              <li>Les nouveaux événements, et un rappel la veille et 1 h avant</li>
              <li>Les cultes : le programme la veille au soir et un rappel 30 min avant</li>
              <li>Les exercices à faire et leur date limite</li>
              <li>Votre fiche FISS du mois</li>
              <li>Les réponses à vos demandes</li>
              <li>Votre anniversaire (et celui de vos fidèles si vous êtes responsable)</li>
            </ul>
          </section>
        </aside>
      </div>
    </AppLayout>
  )
}
