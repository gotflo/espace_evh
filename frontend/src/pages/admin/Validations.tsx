import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useAuth } from '../../auth/AuthContext'
import { api, ApiError, peek } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { LoadError } from '../../components/LoadError'
import { SkeletonCard } from '../../components/Skeleton'
import { usePulse } from '../../pulse'
import type { MembershipRequest, ValidationFissItem, ValidationsData, ValidationTribeItem } from '../../types'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Icon } from '../../components/Icon'
import { de } from '../../utils/french'

interface BlockedPhone { id: number; phone: string; member_name: string | null; reason: string | null; created_at: string | null }

/** Numeros des comptes supprimes (refuses a l'inscription) : le Pasteur Resident peut les debloquer. */
function BlockedPhones() {
  const [items, setItems] = useState<BlockedPhone[] | null>(null)
  const [open, setOpen] = useState(false)
  const load = useCallback(() => { api<{ phones: BlockedPhone[] }>('/admin/blocked-phones', { toast: false }).then((r) => setItems(r.phones)).catch(() => setItems([])) }, [])
  useEffect(() => { if (open && items === null) load() }, [open, items, load])
  async function unblock(b: BlockedPhone) {
    if (!confirm(`Débloquer le ${b.phone} ? Il pourra de nouveau servir à une inscription.`)) return
    try { await api(`/admin/blocked-phones/${b.id}`, { method: 'DELETE' }); load() } catch { /* toast */ }
  }
  return (
    <details className="panel mt blocked-phones" onToggle={(e) => setOpen((e.target as HTMLDetailsElement).open)}>
      <summary><strong>Numéros bloqués</strong> <span className="helper">comptes supprimés, refusés à l'inscription</span></summary>
      {items === null ? <span className="spinner" /> : items.length === 0 ? <p className="helper">Aucun numéro bloqué.</p> : (
        <ul className="gr-member-list">
          {items.map((b) => (
            <li key={b.id} className="gr-member">
              <span className="gr-member-text"><strong>{b.member_name ?? 'Compte supprimé'}</strong><small>{b.phone}{b.reason ? ` · ${b.reason}` : ''}</small></span>
              <button className="btn btn-ghost small" onClick={() => unblock(b)}>Débloquer</button>
            </li>
          ))}
        </ul>
      )}
    </details>
  )
}

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
      <textarea className="input" rows={2} aria-label="Motif du refus" autoFocus value={comment} onChange={(e) => setComment(e.target.value)} placeholder="Motif du refus (communiqué au membre)" />
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

/** Nouvelle inscription : profil complet, puis « Valider » ou « Rejeter » (un compte refuse peut etre valide). */
function MembershipCard({ m, busy, onDecide }: { m: MembershipRequest; busy: boolean; onDecide: (approve: boolean) => void }) {
  const initials = `${m.first_name?.[0] ?? ''}${m.last_name?.[0] ?? ''}`.toUpperCase() || '?'
  const rejected = m.status === 'rejected'
  return (
    <article className={`validation-card membership-card ${rejected ? 'is-rejected' : ''}`}>
      <div className="membership-head">
        {m.photo_url ? <img className="membership-photo" src={m.photo_url} alt="" /> : <span className="membership-photo">{initials}</span>}
        <div className="membership-who">
          <strong>{m.name}</strong>
          <small>{rejected ? `Refusée ${m.decided_at ? ago(m.decided_at) : ''}` : `Nouvelle inscription · ${m.requested_at ? ago(m.requested_at) : ''}`}</small>
        </div>
      </div>
      <dl className="membership-details">
        {m.details.filter((d) => !['Prénoms', 'Nom', 'Demande envoyée'].includes(d.label)).map((d) => (
          <div key={d.label}><dt>{d.label}</dt><dd>{d.label === 'Téléphone' ? <a href={`tel:${d.value}`}>{d.value}</a> : d.value}</dd></div>
        ))}
      </dl>
      <div className="decision-actions">
        {!rejected && <button className="btn btn-ghost small" disabled={busy} onClick={() => onDecide(false)}>Rejeter</button>}
        <button className="btn btn-primary small" disabled={busy} onClick={() => onDecide(true)}>{busy ? <span className="spinner" /> : 'Valider'}</button>
      </div>
    </article>
  )
}

interface DeletionOutcome { member_name: string; status: string; decided_by: string | null }

/**
 * Retour d'un bouton « Accepter / Rejeter » du courriel : la decision est deja faite, on la confirme
 * ici (?suppression=id&resultat=approuvee | refusee | deja | expire | refuse).
 */
function EmailDecisionBanner() {
  const [params, setParams] = useSearchParams()
  if (params.get('inscription')) return <MembershipDecisionBanner />
  return <DeletionDecisionBanner params={params} onClose={() => setParams({}, { replace: true })} />
}

/** Retour d'un bouton « Valider / Rejeter » du courriel d'inscription (?inscription=id&resultat=...). */
function MembershipDecisionBanner() {
  const [params, setParams] = useSearchParams()
  const id = params.get('inscription')
  const result = params.get('resultat')
  const [info, setInfo] = useState<MembershipRequest | null>(null)
  useEffect(() => {
    if (id && /^\d+$/.test(id)) api<MembershipRequest>(`/admin/membership-requests/${id}`, { toast: false }).then(setInfo).catch(() => setInfo(null))
  }, [id])
  if (!id || !result) return null
  const name = info?.name ?? 'Cette personne'
  const by = info?.decided_by ? ` par ${info.decided_by}` : ''
  const view = {
    validee: { tone: 'ok', icon: 'check', title: 'Compte validé', text: `${name} a maintenant accès à My vasesdhonneur et en est informé(e).` },
    refusee: { tone: 'ok', icon: 'check', title: 'Inscription refusée', text: `${name} n’a pas accès à la plateforme. En cas d’erreur, vous pouvez encore valider son compte plus bas.` },
    deja: { tone: 'info', icon: 'alert', title: 'Demande déjà traitée', text: info?.status === 'approved' ? `Le compte de ${name} avait déjà été validé${by}. Rien n’a été modifié.` : `L’inscription de ${name} avait déjà été refusée${by}. Rien n’a été modifié.` },
    expire: { tone: 'info', icon: 'alert', title: 'Lien expiré', text: 'Ce lien n’est plus valable : rien n’a été fait. Décidez ci-dessous si la demande est encore en attente.' },
    refuse: { tone: 'info', icon: 'alert', title: 'Lien non valable', text: 'Ce lien est réservé au pasteur : rien n’a été fait.' },
  }[result as 'validee'] ?? null
  if (!view) return null
  return (
    <section className={`email-decision is-${view.tone}`} role="status">
      <span className="email-decision-icon"><Icon name={view.icon as 'check'} size={20} /></span>
      <div><strong>{view.title}</strong><p>{view.text}</p></div>
      <button className="btn-link" aria-label="Fermer" onClick={() => setParams({}, { replace: true })}>×</button>
    </section>
  )
}

function DeletionDecisionBanner({ params, onClose }: { params: URLSearchParams; onClose: () => void }) {
  const id = params.get('suppression')
  const result = params.get('resultat')
  const [info, setInfo] = useState<DeletionOutcome | null>(null)
  useEffect(() => {
    if (id && /^\d+$/.test(id)) api<DeletionOutcome>(`/admin/deletion-requests/${id}`, { toast: false }).then(setInfo).catch(() => setInfo(null))
  }, [id])
  if (!id || !result) return null
  const name = info?.member_name ?? 'ce membre'
  const by = info?.decided_by ? ` par ${info.decided_by}` : ''
  const view = {
    approuvee: { tone: 'ok', icon: 'check', title: 'Compte supprimé', text: `Le compte de ${name} est supprimé et son numéro est bloqué pour une nouvelle inscription. La personne qui a fait la demande est prévenue.` },
    refusee: { tone: 'ok', icon: 'check', title: 'Demande refusée', text: `Le compte de ${name} est conservé. La personne qui a fait la demande est prévenue.` },
    deja: { tone: 'info', icon: 'alert', title: 'Demande déjà traitée', text: info?.status === 'approved' ? `Le compte de ${name} avait déjà été supprimé${by}. Rien n’a été modifié.` : `La demande concernant ${name} avait déjà été refusée${by}. Rien n’a été modifié.` },
    expire: { tone: 'info', icon: 'alert', title: 'Lien expiré', text: 'Ce lien n’est plus valable : rien n’a été fait. Décidez ci-dessous si la demande est encore en attente.' },
    refuse: { tone: 'info', icon: 'alert', title: 'Lien non valable', text: 'Ce lien est réservé au Pasteur Résident : rien n’a été fait.' },
  }[result as 'approuvee'] ?? null
  if (!view) return null
  return (
    <section className={`email-decision is-${view.tone}`} role="status">
      <span className="email-decision-icon"><Icon name={view.icon as 'check'} size={20} /></span>
      <div><strong>{view.title}</strong><p>{view.text}</p></div>
      <button className="btn-link" aria-label="Fermer" onClick={onClose}>×</button>
    </section>
  )
}

export default function Validations() {
  const navigate = useNavigate()
  const { isSuperAdmin } = useAuth()
  const [data, setData] = useState<ValidationsData | null>(() => peek<ValidationsData>('/admin/validations') ?? null)
  const [busy, setBusy] = useState<string | null>(null)
  const [rejecting, setRejecting] = useState<MembershipRequest | null>(null)
  const [loadError, setLoadError] = useState('')

  // Echec de lecture : jamais « Tout est à jour » (des demandes peuvent attendre) ; la derniere
  // liste connue reste affichee, sinon message et « Réessayer ».
  const load = useCallback(() => {
    setLoadError('')
    api<ValidationsData>('/admin/validations').then(setData)
      .catch((err) => setLoadError(err instanceof ApiError ? err.firstMessage : 'Erreur.'))
  }, [])
  useEffect(() => { load() }, [load])
  usePulse('validations', load)

  async function decideDeletion(id: number, name: string, approve: boolean, comment: string) {
    if (approve && !confirm(`Supprimer définitivement ${name} ?`)) return
    setBusy(`d${id}`)
    try {
      await api(`/admin/validations/deletions/${id}`, { method: 'POST', body: { decision: approve ? 'approved' : 'rejected', comment: comment || null } })
      load()
    } catch { /* toast */ } finally { setBusy(null) }
  }

  async function decideMembership(m: MembershipRequest, approve: boolean) {
    setBusy(`m${m.user_id}`)
    try {
      await api(`/admin/validations/memberships/${m.user_id}`, { method: 'POST', body: { decision: approve ? 'approved' : 'rejected' } })
      load()
    } finally { setBusy(null) }
  }

  async function decideService(id: number, approve: boolean) {
    setBusy(`s${id}`)
    try {
      await api(`/admin/validations/services/${id}`, { method: 'POST', body: { decision: approve ? 'approved' : 'rejected' } })
      load()
    } catch { /* toast */ } finally { setBusy(null) }
  }

  async function decideVertumetre(id: number, approve: boolean, comment: string) {
    setBusy(`v${id}`)
    try {
      await api(`/admin/validations/vertumetre/${id}`, { method: 'POST', body: { decision: approve ? 'approved' : 'rejected', comment: comment || null } })
      load()
    } catch { /* toast */ } finally { setBusy(null) }
  }

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
      <EmailDecisionBanner />
      {data && (data.memberships?.length ?? 0) > 0 && (
        <section className="mb-section">
          <h3 className="ex-group">Nouvelles inscriptions <span className="count-soft">{data.memberships!.length}</span></h3>
          <div className="validation-grid">
            {data.memberships!.map((m) => (
              <MembershipCard key={m.user_id} m={m} busy={busy === `m${m.user_id}`}
                onDecide={(approve) => (approve ? void decideMembership(m, true).catch(() => {}) : setRejecting(m))} />
            ))}
          </div>
        </section>
      )}
      {rejecting && (
        <ConfirmDialog title={`Rejeter l’inscription de ${rejecting.name} ?`} danger confirmLabel="Rejeter"
          text="La personne n’aura pas accès à la plateforme. Vous pourrez encore la valider plus tard."
          onConfirm={() => decideMembership(rejecting, false)} onClose={() => setRejecting(null)} />
      )}
      {data && (data.services?.length ?? 0) > 0 && (
        <section className="mb-section" id="services">
          <h3 className="ex-group">Inscriptions aux services <span className="count-soft">{data.services!.length}</span></h3>
          <div className="validation-grid">
            {data.services!.map((r) => (
              <article key={r.id} className="validation-card">
                <div className="validation-head">
                  {r.member.photo_url ? <img className="mini-avatar" src={r.member.photo_url} alt="" /> : <span className="validation-icon"><Icon name="serve" size={20} /></span>}
                  <div>
                    <button className="btn-link strong" onClick={() => navigate(`/admin/membres/${r.member.user_id}`)}>{r.member.name}</button>
                    <small>{r.member.tribe ? `Tribu ${r.member.tribe}` : 'Sans tribu'}{r.created_at ? ` · ${ago(r.created_at)}` : ''}</small>
                  </div>
                </div>
                <p className="validation-subject">Souhaite rejoindre le service <strong>{r.department.name}</strong></p>
                <div className="row-actions">
                  <button className="btn btn-primary small" disabled={busy === `s${r.id}`} onClick={() => decideService(r.id, true)}>{busy === `s${r.id}` ? <span className="spinner" /> : 'Valider'}</button>
                  <button className="btn btn-ghost small" disabled={busy === `s${r.id}`} onClick={() => { if (confirm(`Refuser la demande de ${r.member.name} pour ${r.department.name} ?`)) void decideService(r.id, false) }}>Refuser</button>
                </div>
              </article>
            ))}
          </div>
        </section>
      )}
      {data && (data.deletions?.length ?? 0) > 0 && (
        <section className="mb-section">
          <h3 className="ex-group">Demandes de suppression <span className="count-soft">{data.deletions!.length}</span></h3>
          <div className="validation-grid">
            {data.deletions!.map((r) => (
              <article key={r.id} className="validation-card danger-zone">
                <div className="validation-head">
                  <span className="validation-icon"><Icon name="trash" size={20} /></span>
                  <div>
                    {r.member.user_id ? <button className="btn-link strong" onClick={() => navigate(`/admin/membres/${r.member.user_id}`)}>{r.member.name}</button> : <strong>{r.member.name}</strong>}
                    <small>{r.member.tribe ?? 'Sans tribu'} · demandée par {r.requested_by ?? 'un responsable'} · {ago(r.created_at)}</small>
                  </div>
                </div>
                <blockquote className="validation-reason">{r.reason}</blockquote>
                <p className="helper">En approuvant, le compte est effacé définitivement et son numéro est bloqué pour une nouvelle inscription.</p>
                <DecisionBox busy={busy === `d${r.id}`} onDecide={(a, c) => decideDeletion(r.id, r.member.name, a, c)} />
              </article>
            ))}
          </div>
        </section>
      )}

      {data && (data.vertumetre?.length ?? 0) > 0 && (
        <section className="mb-section">
          <h3 className="ex-group">Modifications du Vertumètre <span className="count-soft">{data.vertumetre!.length}</span></h3>
          <div className="validation-grid">
            {data.vertumetre!.map((r) => (
              <article key={r.id} className="validation-card">
                <div className="validation-head">
                  <span className="validation-icon"><Icon name="reports" size={20} /></span>
                  <div>
                    <button className="btn-link strong" onClick={() => navigate(`/admin/membres/${r.member.user_id}`)}>{r.member.name}</button>
                    <small>{r.member.tribe ?? 'Sans tribu'} · {ago(r.created_at)}</small>
                  </div>
                  <span className="chip">Demande {r.request_number}/2</span>
                </div>
                <p className="validation-subject">Vertumètre {de(r.period_label)}</p>
                <blockquote className="validation-reason">{r.reason}</blockquote>
                <p className="helper">Si vous approuvez, le Vertumètre sera modifiable une seule fois pendant 7 jours, puis reverrouillé.</p>
                <DecisionBox busy={busy === `v${r.id}`} onDecide={(a, c) => decideVertumetre(r.id, a, c)} />
              </article>
            ))}
          </div>
        </section>
      )}

      {data === null ? (
        loadError ? <LoadError message={loadError} onRetry={load} /> : <div className="validation-grid"><SkeletonCard /><SkeletonCard /></div>
      ) : data.count === 0 ? (
        <div className="empty-state">
          <Icon name="validate" size={28} />
          <h3>Tout est à jour</h3>
          <p>Aucune demande en attente.</p>
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
                      <span className="validation-icon"><Icon name="fiss" size={20} /></span>
                      <div>
                        <button className="btn-link strong" onClick={() => navigate(`/admin/membres/${r.member.user_id}`)}>{r.member.name}</button>
                        <small>{r.member.tribe ?? 'Sans tribu'} · {ago(r.created_at)}</small>
                      </div>
                      <span className="chip">Demande {r.request_number}/2</span>
                    </div>
                    <p className="validation-subject">Fiche {de(r.period_label)}</p>
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
                      <span className="validation-icon"><Icon name="swap" size={20} /></span>
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
      {data && (data.memberships_rejected?.length ?? 0) > 0 && (
        <details className="panel mt blocked-phones">
          <summary><strong>Inscriptions refusées</strong> <span className="helper">30 derniers jours · une erreur se corrige ici</span></summary>
          <div className="validation-grid mt-sm">
            {data.memberships_rejected!.map((m) => (
              <MembershipCard key={m.user_id} m={m} busy={busy === `m${m.user_id}`} onDecide={() => void decideMembership(m, true).catch(() => {})} />
            ))}
          </div>
        </details>
      )}
      {isSuperAdmin && <BlockedPhones />}
    </AppLayout>
  )
}
