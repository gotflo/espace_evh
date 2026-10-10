import { useState } from 'react'
import { ModalLayer } from './ModalLayer'

/**
 * Fenetre de confirmation, avec un motif facultatif ou obligatoire (communique a la personne
 * concernee). Remplace confirm() / prompt(), peu lisibles sur telephone.
 */
export function ConfirmDialog({ title, text, confirmLabel, danger = false, reason, onConfirm, onClose }: {
  title: string
  text?: string
  confirmLabel: string
  danger?: boolean
  /** Champ « motif » : absent, facultatif ou obligatoire. */
  reason?: { label: string; required: boolean; placeholder?: string }
  onConfirm: (reason: string) => Promise<void> | void
  onClose: () => void
}) {
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)
  const ok = !reason?.required || value.trim().length >= 3

  async function confirm() {
    setBusy(true)
    try { await onConfirm(value.trim()); onClose() } catch { /* toast */ } finally { setBusy(false) }
  }

  return (
    <ModalLayer className="modal-overlay" onClick={onClose}>
      <div className="modal-box confirm-dialog" role="dialog" aria-modal="true" aria-label={title} onClick={(e) => e.stopPropagation()}>
        <h3>{title}</h3>
        {text && <p className="helper">{text}</p>}
        {reason && (
          <div className="field">
            <label htmlFor="confirm-reason">{reason.label}{reason.required ? '' : ' (facultatif)'}</label>
            <textarea id="confirm-reason" className="input" rows={3} maxLength={500} value={value} onChange={(e) => setValue(e.target.value)} placeholder={reason.placeholder} autoFocus />
          </div>
        )}
        <div className="row-actions mt">
          <button className="btn btn-ghost small" onClick={onClose}>Retour</button>
          <button className={`btn small ${danger ? 'btn-danger' : 'btn-primary'}`} disabled={busy || !ok} onClick={confirm}>
            {busy ? <span className="spinner" /> : confirmLabel}
          </button>
        </div>
      </div>
    </ModalLayer>
  )
}
