import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'

interface DeletionInfo {
  can_delete: boolean
  can_request: boolean
  pending_request: { id: number; reason: string; by: string | null; created_at: string } | null
  has_pending?: boolean
  impact: { deleted: { label: string; count: number }[] } | null
}

/**
 * Fiche d'un membre : le Pasteur Resident supprime (definitif, numero bloque pour une nouvelle
 * inscription) ; le patriarche de la tribu et les responsables de ses departements le demandent.
 */
export function MemberDeletion({ userId, name }: { userId: string; name: string }) {
  const navigate = useNavigate()
  const [info, setInfo] = useState<DeletionInfo | null>(null)
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [sure, setSure] = useState(false)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => { api<DeletionInfo>(`/admin/members/${userId}/deletion`, { toast: false }).then(setInfo).catch(() => setInfo(null)) }, [userId])
  useEffect(() => { load() }, [load])

  if (!info || (!info.can_delete && !info.can_request)) return null

  async function submit() {
    setBusy(true)
    try {
      if (info!.can_delete) {
        await api(`/admin/members/${userId}`, { method: 'DELETE', body: { reason: reason.trim() } })
        navigate('/admin/membres', { replace: true })
      } else {
        await api(`/admin/members/${userId}/deletion-requests`, { method: 'POST', body: { reason: reason.trim() } })
        setOpen(false); setReason(''); load()
      }
    } catch { /* toast */ } finally { setBusy(false) }
  }

  return (
    <section className="panel mt danger-zone">
      <div className="panel-head"><h3>{info.can_delete ? 'Supprimer ce membre' : 'Demander la suppression'}</h3></div>
      {info.pending_request && (
        <p className="helper">Demande en attente{info.pending_request.by ? ` de ${info.pending_request.by}` : ''} : « {info.pending_request.reason} ».</p>
      )}
      {!info.pending_request && info.has_pending && (
        <p className="helper">Une demande de suppression est déjà en attente auprès du Pasteur Résident.</p>
      )}
      {!open ? (
        <>
          <p className="helper">
            {info.can_delete
              ? 'Suppression définitive du compte et de ses données. Son numéro ne pourra plus servir à une nouvelle inscription (vous pourrez le débloquer dans Validations).'
              : 'Le Pasteur Résident recevra votre demande et décidera.'}
          </p>
          {(info.can_delete || !(info.pending_request || info.has_pending)) && (
            <button className="btn btn-ghost small" onClick={() => setOpen(true)}>{info.can_delete ? 'Supprimer…' : 'Demander la suppression…'}</button>
          )}
        </>
      ) : (
        <>
          {info.impact && info.impact.deleted.length > 0 && (
            <ul className="danger-impact">
              {info.impact.deleted.map((d) => <li key={d.label}><b>{d.count}</b> · {d.label}</li>)}
            </ul>
          )}
          <div className="field">
            <label htmlFor="del-reason">Motif</label>
            <textarea id="del-reason" className="input" rows={2} maxLength={1000} value={reason} onChange={(e) => setReason(e.target.value)}
              placeholder={info.can_delete ? 'Ex. compte en double, a quitté l’église' : 'Pourquoi ce membre devrait-il être supprimé ?'} />
          </div>
          {info.can_delete && (
            <label className="switch-line mt">
              <input type="checkbox" checked={sure} onChange={(e) => setSure(e.target.checked)} />
              <span>Je comprends que la suppression de {name} est définitive.</span>
            </label>
          )}
          <div className="row-actions mt">
            <button className="btn btn-ghost small" onClick={() => { setOpen(false); setSure(false) }}>Annuler</button>
            <button className="btn btn-danger small" disabled={busy || reason.trim().length < 5 || (info.can_delete && !sure)} onClick={submit}>
              {busy ? <span className="spinner" /> : info.can_delete ? 'Supprimer définitivement' : 'Envoyer la demande'}
            </button>
          </div>
        </>
      )}
    </section>
  )
}
