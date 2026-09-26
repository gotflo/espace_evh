import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { SkeletonCard } from '../../components/Skeleton'
import { usePulse } from '../../pulse'
import type { ValidationFissItem, ValidationsData, ValidationTribeItem } from '../../types'

const SIDE_LABEL: Record<string, string> = { from: 'la tribu de départ', to: "la tribu d'arrivée", both: 'les deux tribus' }

function ago(iso: string): string {
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  return days <= 0 ? "aujourd'hui" : days === 1 ? 'hier' : `il y a ${days} jours`
}

/** Decision avec commentaire (obligatoire pour un refus). */
function DecisionBox({ onDecide, busy }: { onDecide: (approve: boolean, comment: string) => void; busy: boolean }) {
  const [refusing, setRefusing] = useState(false)
  const [comment, setComment] = useState('')
  return refusing ? (
    <div className="decision-box">
      <textarea className="input" rows={2} autoFocus value={comment} onChange={(e) => setComment(e.target.value)} placeholder="Motif du refus (communiqué au membre)" />
      <div className="decision-actions">
        <button className="btn btn-ghost small" onClick={() => setRefusing(false)}>Retour</button>
        <button className="btn btn-danger small" disabled={busy || comment.trim().length < 3} onClick={() => onDecide(false, comment.trim())}>Confirmer le refus</button>
      </div>
    </div>
  ) : (
    <div className="decision-actions">
      <button className="btn btn-ghost small" disabled={busy} onClick={() => setRefusing(true)}>Refuser</button>
      <button className="btn btn-primary small" disabled={busy} onClick={() => onDecide(true, '')}>Approuver</button>
    </div>
  )
}

export default function Validations() {
  const navigate = useNavigate()
  const [data, setData] = useState<ValidationsData | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const load = useCallback(() => {
    api<ValidationsData>('/admin/validations').then(setData).catch(() => setData({ fiss: [], tribes: [], count: 0 }))
  }, [])
  useEffect(() => { load() }, [load])
  usePulse('validations', load)

  async function decideFiss(item: ValidationFissItem, approve: boolean, comment: string) {
    setBusy(`f${item.id}`)
    try {
      await api(`/admin/validations/fiss/${item.id}`, { method: 'POST', body: { decision: approve ? 'approved' : 'rejected', comment: comment || null } })
      load()
    } catch { /* toast */ } finally { setBusy(null) }
  }

  async function decideTribe(item: ValidationTribeItem, approve: boolean, comment: string) {
    setBusy(`t${item.id}`)
    try {
      await api(`/admin/validations/tribe/${item.id}`, { method: 'POST', body: { decision: approve ? 'approved' : 'rejected', comment: comment || null } })
      load()
    } catch { /* toast */ } finally { setBusy(null) }
  }

  return (
    <AppLayout title="Validations" subtitle={data ? `${data.count} demande(s) à traiter` : 'Demandes de vos membres'}>
      {data === null ? (
        <div className="validation-grid"><SkeletonCard /><SkeletonCard /></div>
      ) : data.count === 0 ? (
        <div className="empty-state">
          <span aria-hidden>✅</span>
          <h3>Tout est à jour</h3>
          <p>Aucune demande de modification de FISS ni de changement de tribu en attente.</p>
        </div>
      ) : (
        <>
          {data.fiss.length > 0 && (
            <section className="mt-0">
              <h3 className="section-label">Modifications de FISS ({data.fiss.length})</h3>
              <div className="validation-grid">
                {data.fiss.map((r) => (
                  <article key={r.id} className="validation-card">
                    <div className="validation-head">
                      <span className="validation-icon" aria-hidden>🩺</span>
                      <div>
                        <button className="btn-link strong" onClick={() => navigate(`/admin/membres/${r.member.user_id}`)}>{r.member.name}</button>
                        <small>{r.member.tribe ?? 'Sans tribu'} · {ago(r.created_at)}</small>
                      </div>
                      <span className="chip">Demande {r.request_number}/2</span>
                    </div>
                    <p className="validation-subject">Fiche de {r.period_label}</p>
                    <blockquote className="validation-reason">{r.reason}</blockquote>
                    <p className="helper">Si vous approuvez, la fiche sera modifiable une seule fois pendant 7 jours, puis reverrouillée.</p>
                    <DecisionBox busy={busy === `f${r.id}`} onDecide={(a, c) => decideFiss(r, a, c)} />
                  </article>
                ))}
              </div>
            </section>
          )}

          {data.tribes.length > 0 && (
            <section className="mt">
              <h3 className="section-label">Changements de tribu ({data.tribes.length})</h3>
              <div className="validation-grid">
                {data.tribes.map((r) => (
                  <article key={r.id} className="validation-card">
                    <div className="validation-head">
                      <span className="validation-icon" aria-hidden>🔁</span>
                      <div>
                        <button className="btn-link strong" onClick={() => navigate(`/admin/membres/${r.member.user_id}`)}>{r.member.name}</button>
                        <small>{ago(r.created_at)}</small>
                      </div>
                    </div>
                    <p className="validation-subject">{r.from ?? 'Aucune tribu'} <span aria-hidden>→</span> <strong>{r.to}</strong></p>
                    {r.reason && <blockquote className="validation-reason">{r.reason}</blockquote>}
                    <ul className="approval-steps">
                      {r.required_sides.map((side) => {
                        const a = r.approvals.find((x) => x.side === side || x.side === 'both')
                        return (
                          <li key={side} className={a ? 'done' : ''}>
                            <span aria-hidden>{a ? '✓' : '○'}</span>
                            {side === 'from' ? `Tribu ${r.from}` : `Tribu ${r.to}`}
                            {a ? ` · approuvé par ${a.by ?? 'un responsable'}` : ' · en attente'}
                          </li>
                        )
                      })}
                    </ul>
                    <p className="helper">Vous vous prononcez pour {r.my_sides.map((s) => SIDE_LABEL[s]).join(' et ')}.</p>
                    <DecisionBox busy={busy === `t${r.id}`} onDecide={(a, c) => decideTribe(r, a, c)} />
                  </article>
                ))}
              </div>
            </section>
          )}
        </>
      )}
    </AppLayout>
  )
}
