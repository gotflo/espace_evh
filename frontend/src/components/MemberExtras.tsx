import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import type { AuditEntry, FamilyOverview, ProfileCompletion } from '../types'

/** Completion du profil vue par un responsable : pourcentage et champs manquants. */
export function MemberCompletion({ completion }: { completion: ProfileCompletion }) {
  const tone = completion.percent >= 100 ? 'good' : completion.percent >= 60 ? 'mid' : 'low'
  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Profil complété</h3><strong className={`completion-pct ${tone}`}>{completion.percent} %</strong></div>
      <div className="completion-bar" role="progressbar" aria-valuenow={completion.percent} aria-valuemin={0} aria-valuemax={100}>
        <span className={tone} style={{ width: `${completion.percent}%` }} />
      </div>
      {completion.missing.length > 0 ? (
        <div className="chip-list mt-sm">
          <span className="mini-label">Manquant :</span>
          {completion.missing.map((m) => <span key={m.key} className="chip-soft warn">{m.label}</span>)}
        </div>
      ) : <p className="helper">Toutes les informations essentielles sont renseignées.</p>}
      {completion.recommended.length > 0 && (
        <div className="chip-list mt-sm">
          <span className="mini-label">Recommandé :</span>
          {completion.recommended.map((m) => <span key={m.key} className="chip-soft">{m.label}</span>)}
        </div>
      )}
    </section>
  )
}

/** Famille du membre (lecture seule cote responsable). */
export function MemberFamily({ family, maritalStatus, wedding }: { family: FamilyOverview; maritalStatus?: string | null; wedding?: string | null }) {
  const spouse = family.spouse
  const spouseName = spouse?.name ?? family.spouse_name
  if (!spouseName && family.children.length === 0 && !maritalStatus) return null
  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Famille</h3></div>
      <div className="detail-list">
        {maritalStatus && <div className="detail-line"><span>Situation</span><strong>{maritalStatus}</strong></div>}
        {spouseName && (
          <div className="detail-line">
            <span>Conjoint(e)</span>
            <strong>
              {spouse?.user_id ? <Link to={`/admin/membres/${spouse.user_id}`}>{spouseName}</Link> : spouseName}
              {spouse?.status === 'pending' && <em className="helper-inline"> (en attente de confirmation)</em>}
              {!spouse?.user_id && <em className="helper-inline"> (non inscrit)</em>}
            </strong>
          </div>
        )}
        {wedding && <div className="detail-line"><span>Anniversaire de mariage</span><strong>{wedding}</strong></div>}
        {family.children.length > 0 && (
          <div className="detail-line">
            <span>Enfants ({family.children.length})</span>
            <strong>
              {family.children.map((c, i) => (
                <span key={c.id}>
                  {i > 0 && ', '}
                  {c.user_id ? <Link to={`/admin/membres/${c.user_id}`}>{c.name}</Link> : c.name}
                  {c.birth_year ? ` (${c.birth_year})` : ''}
                </span>
              ))}
            </strong>
          </div>
        )}
      </div>
    </section>
  )
}

/** Historique complet des FISS (creation, verrouillage, demandes, modifications). */
export function MemberFissHistory({ userId }: { userId: string }) {
  const [items, setItems] = useState<AuditEntry[] | null>(null)
  const [open, setOpen] = useState(false)

  function toggle() {
    setOpen(!open)
    if (!items) api<{ history: AuditEntry[] }>(`/admin/members/${userId}/fiss-history`).then((r) => setItems(r.history)).catch(() => setItems([]))
  }

  return (
    <section className="panel mt">
      <button className="panel-head panel-toggle" onClick={toggle} aria-expanded={open}>
        <h3>Historique des FISS</h3><span aria-hidden>{open ? '▴' : '▾'}</span>
      </button>
      {open && (items === null ? <span className="spinner" /> : items.length === 0 ? (
        <p className="helper">Aucun historique.</p>
      ) : (
        <ul className="timeline">
          {items.map((h) => (
            <li key={h.id}>
              <strong>{h.label}</strong>{h.period ? ` · ${h.period}` : ''}
              <small>{h.actor} · {h.created_at ? new Date(h.created_at).toLocaleString('fr-CA', { dateStyle: 'medium', timeStyle: 'short' }) : ''}</small>
              {typeof h.new?.reason === 'string' && <em>« {h.new.reason} »</em>}
              {typeof h.new?.comment === 'string' && h.new.comment && <em>Commentaire : {h.new.comment}</em>}
            </li>
          ))}
        </ul>
      ))}
    </section>
  )
}
