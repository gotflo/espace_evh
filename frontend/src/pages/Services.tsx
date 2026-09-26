import { useCallback, useEffect, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import type { ServiceItem } from '../types'

/** Servir : le fidele choisit le(s) departement(s) ou il veut servir et les rejoint aussitot. */
export default function Services() {
  const { refresh } = useAuth()
  const [items, setItems] = useState<ServiceItem[] | null>(null)
  const [busy, setBusy] = useState<number | null>(null)
  const [confirmJoin, setConfirmJoin] = useState<ServiceItem | null>(null)
  const [query, setQuery] = useState('')

  const load = useCallback(() => {
    api<{ services: ServiceItem[] }>('/me/services').then((r) => setItems(r.services)).catch(() => setItems([]))
  }, [])
  useEffect(() => { load() }, [load])

  async function join(s: ServiceItem) {
    setConfirmJoin(null); setBusy(s.id)
    try {
      await api(`/me/services/${s.id}/join`, { method: 'POST' })
      load(); refresh()
    } catch { /* le toast d'erreur est affiche automatiquement */ } finally { setBusy(null) }
  }

  async function leave(s: ServiceItem) {
    if (!confirm(`Quitter le service ${s.name} ? Ses responsables seront prévenus.`)) return
    setBusy(s.id)
    try {
      await api(`/me/services/${s.id}`, { method: 'DELETE' })
      load(); refresh()
    } catch { /* toast automatique */ } finally { setBusy(null) }
  }

  const mine = (items ?? []).filter((s) => s.joined)
  const q = query.trim().toLowerCase()
  const others = (items ?? []).filter((s) => !s.joined && (!q || s.name.toLowerCase().includes(q) || (s.description ?? '').toLowerCase().includes(q)))

  return (
    <AppLayout title="Service" subtitle="Rejoignez un service et mettez vos dons au service de l'église">
      <div className="serve-hero">
        <p className="serve-verse">« Comme de bons dispensateurs des diverses grâces de Dieu, que chacun de vous mette au service des autres le don qu'il a reçu. »</p>
        <span className="verse-ref">1 Pierre 4.10</span>
      </div>


      {items === null ? (
        <div className="serve-grid"><SkeletonCard /><SkeletonCard /><SkeletonCard /></div>
      ) : (
        <>
          <h3 className="section-label">Mes services ({mine.length})</h3>
          {mine.length === 0 ? (
            <p className="helper serve-empty">Vous ne servez dans aucun département pour l'instant. Choisissez un service ci-dessous : l'inscription est immédiate et son responsable sera prévenu.</p>
          ) : (
            <div className="serve-grid">
              {mine.map((s) => <ServiceCard key={s.id} s={s} busy={busy === s.id} onJoin={() => setConfirmJoin(s)} onLeave={() => leave(s)} />)}
            </div>
          )}

          <div className="toolbar serve-toolbar">
            <h3 className="section-label" style={{ margin: 0 }}>Services disponibles ({others.length})</h3>
            <input className="input serve-search" placeholder="Rechercher un service…" value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
          <div className="serve-grid">
            {others.map((s) => <ServiceCard key={s.id} s={s} busy={busy === s.id} onJoin={() => setConfirmJoin(s)} onLeave={() => leave(s)} />)}
            {others.length === 0 && <p className="helper">{q ? 'Aucun service ne correspond à la recherche.' : 'Vous servez déjà dans tous les services disponibles. Merci !'}</p>}
          </div>
        </>
      )}

      {confirmJoin && (
        <div className="modal-overlay" onClick={() => setConfirmJoin(null)}>
          <div className="modal-box" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head"><h3>Servir dans {confirmJoin.name}</h3></div>
            <p className="event-desc">En vous inscrivant, vous intégrez ce département et commencez à y servir :</p>
            <ul className="notif-help">
              <li>🤝 {confirmJoin.leader ? `${confirmJoin.leader}, responsable du service, est prévenu(e) pour vous accueillir` : 'Les responsables du service sont prévenus pour vous accueillir'}</li>
              <li>📢 Vous recevez les annonces et les événements du service</li>
              <li>📅 Ses répétitions et réunions apparaissent dans votre calendrier</li>
            </ul>
            <div className="editor-actions mt">
              <button className="btn btn-primary" onClick={() => join(confirmJoin)}>Je m'inscris</button>
              <button className="btn btn-ghost" onClick={() => setConfirmJoin(null)}>Annuler</button>
            </div>
          </div>
        </div>
      )}
    </AppLayout>
  )
}

function ServiceCard({ s, busy, onJoin, onLeave }: { s: ServiceItem; busy: boolean; onJoin: () => void; onLeave: () => void }) {
  return (
    <article className={`serve-card ${s.joined ? 'joined' : ''}`}>
      <div className="serve-card-head">
        <span className="serve-initial" aria-hidden>{s.name.charAt(0).toUpperCase()}</span>
        <div className="serve-card-title">
          <h4>{s.name}</h4>
          <span className="serve-count">{s.members_count} serviteur(s)</span>
        </div>
        {s.joined && <span className="status-pill on">Je sers ici</span>}
      </div>
      {s.description && <p className="serve-desc">{s.description}</p>}
      <div className="serve-meta">
        {s.leader && (
          <span className="serve-leader">
            {s.leader_photo_url ? <img src={s.leader_photo_url} alt="" /> : <span aria-hidden>👤</span>}
            Responsable : {s.leader}
          </span>
        )}
        {s.next_event && (
          <span>📅 {s.next_event.title} · {new Date(s.next_event.starts_at).toLocaleDateString('fr-CA', { weekday: 'short', day: 'numeric', month: 'short' })}</span>
        )}
        {s.joined && s.joined_at && <span>Depuis le {new Date(s.joined_at + 'T00:00').toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' })}</span>}
      </div>
      <div className="serve-actions">
        {s.joined
          ? <button className="btn-link" disabled={busy} onClick={onLeave}>{busy ? <span className="spinner" /> : 'Quitter ce service'}</button>
          : <button className="btn btn-primary small" disabled={busy} onClick={onJoin}>{busy ? <span className="spinner" /> : "M'inscrire"}</button>}
      </div>
    </article>
  )
}
