import { useState } from 'react'
import type { FissForm } from '../types'
import { compareFiss, TREND_ARROW, TREND_LABEL, type FissDiffField } from '../utils/fiss'

function fieldValue(v: number | string | null): string {
  if (v == null) return '-'
  if (typeof v === 'string') return v === 'bien' ? 'Bien' : v === 'moyen' ? 'Moyen' : 'Mal'
  return String(v)
}

function DiffRow({ f }: { f: FissDiffField }) {
  return (
    <div className={`fiss-diff-row fiss-diff-${f.dir}`}>
      <span className="fiss-diff-label">{f.label}</span>
      <span className="fiss-diff-change">
        {fieldValue(f.prev)} <span className="fiss-diff-arrow">{TREND_ARROW[f.dir]}</span> {fieldValue(f.cur)}
      </span>
    </div>
  )
}

/**
 * Indicateur de tendance de la fiche courante par rapport à la precedente,
 * cliquable pour voir le detail de ce qui a change.
 */
export function FissEvolution({ current, previous, previousLabel }: {
  current: FissForm
  previous?: FissForm | null
  previousLabel?: string
}) {
  const [open, setOpen] = useState(false)
  const cmp = compareFiss(current, previous)
  if (!cmp) return null

  const changed = cmp.fields.filter((f) => f.dir !== 'stable')

  return (
    <section className={`fiss-evolution fiss-evolution-${cmp.overall}`}>
      <button className="fiss-evolution-head" onClick={() => setOpen((o) => !o)}>
        <span className="fiss-evolution-icon">{TREND_ARROW[cmp.overall]}</span>
        <div className="fiss-evolution-text">
          <strong>Evolution : {TREND_LABEL[cmp.overall]}{cmp.diff !== 0 ? ` (${cmp.diff > 0 ? '+' : ''}${cmp.diff} pts)` : ''}</strong>
          <small>Par rapport a {previousLabel ?? 'le mois précédent'} · {changed.length} element(s) modifie(s)</small>
        </div>
        <span className="fiss-evolution-toggle">{open ? 'Masquer' : 'Voir ce qui a changé'}</span>
      </button>
      {open && (
        <div className="fiss-evolution-body">
          {cmp.fields.map((f) => <DiffRow key={f.key} f={f} />)}
        </div>
      )}
    </section>
  )
}
