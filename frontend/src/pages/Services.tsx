import { useCallback, useEffect, useState } from 'react'
import { api, peek } from '../api/client'
import { usePulse } from '../pulse'
import { useAuth } from '../auth/AuthContext'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import type { ServiceItem } from '../types'
import { Icon } from '../components/Icon'
import { toast } from '../toast'

/** Servir : le fidele demande a rejoindre un ou plusieurs departements ; le responsable valide chaque demande. */
export default function Services() {
  const { refresh } = useAuth()
  const [items, setItems] = useState<ServiceItem[] | null>(() => peek<{ services: ServiceItem[] }>('/me/services')?.services ?? null)
  const [busy, setBusy] = useState<number | null>(null)
  const [confirmJoin, setConfirmJoin] = useState<ServiceItem | null>(null)
  const [query, setQuery] = useState('')

  const load = useCallback(() => {
    api<{ services: ServiceItem[] }>('/me/services').then((r) => setItems(r.services)).catch(() => setItems((p) => p ?? []))
  }, [])
  useEffect(() => { load() }, [load])
  // Demande acceptee ou refusee par le responsable : la liste se met a jour d'elle-meme.
  usePulse('services', load)

  async function join(s: ServiceItem) {
    setConfirmJoin(null); setBusy(s.id)
    try {
      const r = await api<{ message: string }>(`/me/services/${s.id}/join`, { method: 'POST', toast: false })
      toast.success(r.message)
      load(); refresh()
    } catch { /* le toast d'erreur est affiche automatiquement */ } finally { setBusy(null) }
  }

  async function leave(s: ServiceItem) {
    if (!confirm(s.pending ? `Retirer votre demande pour le service ${s.name} ?` : `Quitter le service ${s.name} ? Ses responsables seront prévenus.`)) return
    setBusy(s.id)
    try {
      await api(`/me/services/${s.id}`, { method: 'DELETE' })
      load(); refresh()
    } catch { /* toast automatique */ } finally { setBusy(null) }
  }

  const mine = (items ?? []).filter((s) => s.joined)
  const waiting = (items ?? []).filter((s) => s.pending)
  const q = query.trim().toLowerCase()
  const others = (items ?? []).filter((s) => !s.joined && !s.pending && (!q || s.name.toLowerCase().includes(q) || (s.description ?? '').toLowerCase().includes(q)))

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
            <p className="helper serve-empty">Vous ne servez dans aucun département pour l'instant. Choisissez un service ci-dessous : son responsable validera votre inscription.</p>
          ) : (
            <div className="serve-grid">
              {mine.map((s) => <ServiceCard key={s.id} s={s} busy={busy === s.id} onJoin={() => setConfirmJoin(s)} onLeave={() => leave(s)} />)}
            </div>
          )}

          {waiting.length > 0 && (
            <>
              <h3 className="section-label">En attente de validation ({waiting.length})</h3>
              <div className="serve-grid">
                {waiting.map((s) => <ServiceCard key={s.id} s={s} busy={busy === s.id} onJoin={() => setConfirmJoin(s)} onLeave={() => leave(s)} />)}
              </div>
            </>
          )}

          <div className="toolbar serve-toolbar">
            <h3 className="section-label" style={{ margin: 0 }}>Services disponibles ({others.length})</h3>
            <input aria-label="Rechercher un service" className="input serve-search" placeholder="Rechercher un service…" value={query} onChange={(e) => setQuery(e.target.value)} />
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
            <p className="event-desc">Votre demande est envoyée au responsable du service, qui la valide :</p>
            <ul className="notif-help">
              <li>{confirmJoin.leader ? `${confirmJoin.leader}, responsable du service, reçoit votre demande` : 'Les responsables du service reçoivent votre demande'}</li>
              <li>Vous êtes prévenu(e) dès qu’elle est validée</li>
              <li>Ensuite, vous recevez les annonces et les événements du service, et ses réunions apparaissent dans votre calendrier</li>
            </ul>
            <div className="editor-actions mt">
              <button className="btn btn-primary" onClick={() => join(confirmJoin)}>Envoyer ma demande</button>
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
        {s.pending && <span className="status-pill pending">En attente</span>}
      </div>
      {s.description && <p className="serve-desc">{s.description}</p>}
      <div className="serve-meta">
        {s.leader && (
          <span className="serve-leader">
            {s.leader_photo_url ? <img src={s.leader_photo_url} alt="" /> : <Icon name="profile" size={20} />}
            Responsable : {s.leader}
          </span>
        )}
        {s.next_event && (
          <span className="with-icon"><Icon name="calendar" size={14} />{s.next_event.title} · {new Date(s.next_event.starts_at).toLocaleDateString('fr-CA', { weekday: 'short', day: 'numeric', month: 'short' })}</span>
        )}
        {s.joined && s.joined_at && <span>Depuis le {new Date(s.joined_at + 'T00:00').toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' })}</span>}
      </div>
      <div className="serve-actions">
        {s.joined
          ? <button className="btn-link" disabled={busy} onClick={onLeave}>{busy ? <span className="spinner" /> : 'Quitter ce service'}</button>
          : s.pending
            ? <button className="btn-link" disabled={busy} onClick={onLeave}>{busy ? <span className="spinner" /> : 'Retirer ma demande'}</button>
            : <button className="btn btn-primary small" disabled={busy} onClick={onJoin}>{busy ? <span className="spinner" /> : "M'inscrire"}</button>}
      </div>
    </article>
  )
}
