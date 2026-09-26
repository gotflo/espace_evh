import { useCallback, useEffect, useState } from 'react'
import { api } from '../../api/client'
import type { MyTribeRequest, Tribe } from '../../types'

const STATUS: Record<string, string> = { pending: 'En attente', approved: 'Validée', rejected: 'Refusée', cancelled: 'Annulée' }

/**
 * La tribu ne se change pas librement : le membre fait une demande, validee par les
 * responsables de l'ancienne et de la nouvelle tribu. Suivi des etapes en direct.
 */
export function TribeChange({ currentTribe, tribes }: { currentTribe: Tribe | null | undefined; tribes: Tribe[] }) {
  const [requests, setRequests] = useState<MyTribeRequest[] | null>(null)
  const [open, setOpen] = useState(false)
  const [to, setTo] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api<{ requests: MyTribeRequest[] }>('/me/tribe-change').then((r) => setRequests(r.requests)).catch(() => setRequests([]))
  }, [])
  useEffect(() => { load() }, [load])

  const pending = requests?.find((r) => r.status === 'pending')
  const last = requests?.find((r) => r.status !== 'pending')

  async function send() {
    setBusy(true)
    try {
      await api('/me/tribe-change', { method: 'POST', body: { to_tribe_id: Number(to), reason: reason.trim() } })
      setOpen(false); setTo(''); setReason(''); load()
    } catch { /* toast */ } finally { setBusy(false) }
  }

  async function cancel(id: number) {
    if (!confirm('Annuler votre demande de changement de tribu ?')) return
    try { await api(`/me/tribe-change/${id}`, { method: 'DELETE' }); load() } catch { /* toast */ }
  }

  return (
    <div className="field">
      <label>Tribu</label>
      <div className="tribe-box">
        <div className="tribe-current">
          <span className="tribe-badge" aria-hidden>🏕️</span>
          <strong>{currentTribe?.name ?? 'Aucune'}</strong>
        </div>
        {!pending && <button type="button" className="btn btn-ghost small" onClick={() => setOpen(true)}>Demander un changement</button>}
      </div>

      {pending && (
        <div className="tribe-request">
          <div className="tribe-request-head">
            <span>Demande vers <strong>{pending.to}</strong> · {STATUS.pending}</span>
            <button type="button" className="btn-link" onClick={() => cancel(pending.id)}>Annuler</button>
          </div>
          <ol className="approval-steps">
            {pending.required_sides.map((side) => {
              const done = pending.approved_sides.includes(side)
              return (
                <li key={side} className={done ? 'done' : ''}>
                  <span aria-hidden>{done ? '✓' : '○'}</span>
                  {side === 'from' ? `Responsables de ${pending.from}` : `Responsables de ${pending.to}`} · {done ? 'validé' : 'en attente'}
                </li>
              )
            })}
          </ol>
        </div>
      )}
      {!pending && last && last.status === 'rejected' && (
        <p className="helper">Dernière demande (vers {last.to}) refusée{last.approvals.find((a) => a.decision === 'rejected')?.comment ? ` : « ${last.approvals.find((a) => a.decision === 'rejected')?.comment} »` : '.'}</p>
      )}
      {!pending && <p className="helper">Le changement de tribu est validé par les responsables des deux tribus.</p>}

      {open && (
        <div className="modal-overlay" onClick={() => setOpen(false)}>
          <div className="modal-box" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head"><h3>Changer de tribu</h3><button className="btn-link" onClick={() => setOpen(false)}>Fermer</button></div>
            <div className="field">
              <label>Nouvelle tribu</label>
              <select className="select" value={to} onChange={(e) => setTo(e.target.value)}>
                <option value="">Choisir…</option>
                {tribes.filter((t) => t.id !== currentTribe?.id).map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            </div>
            <div className="field">
              <label>Motif</label>
              <textarea className="input" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Ex. déménagement, rapprochement familial…" />
            </div>
            <p className="helper">Les responsables de {currentTribe?.name ?? 'votre tribu'} et de la nouvelle tribu seront prévenus.</p>
            <button className="btn btn-primary mt" disabled={busy || !to || reason.trim().length < 5} onClick={send}>
              {busy ? <span className="spinner" /> : 'Envoyer la demande'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}
