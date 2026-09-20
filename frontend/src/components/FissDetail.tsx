import type { FissForm } from '../types'

function sanctBadge(v: string | null) {
  if (!v) return <span className="fiss-detail-na">-</span>
  const label = v === 'bien' ? 'Bien' : v === 'moyen' ? 'Moyen' : 'Mal'
  return <span className={`sanct-badge sanct-${v}`}>{label}</span>
}

/** Affichage lecture seule d'une fiche FISS (reutilise : historique, cote responsable). */
export function FissDetail({ form }: { form: FissForm }) {
  return (
    <div className="fiss-detail">
      <div className="fiss-detail-totals">
        <span>Vie spirituelle <b>{form.vie_spirituelle_total ?? '-'}/60</b></span>
        <span>Vie sociale <b>{form.vie_sociale_total ?? '-'}</b></span>
      </div>
      <div className="fiss-detail-grid">
        <span>Méditation <b>{form.meditation ?? '-'}/20</b></span>
        <span>Prière <b>{form.priere ?? '-'}/20</b></span>
        <span>Jeûne <b>{form.jeune ?? '-'}/20</b></span>
        <span>Financière <b>{form.situation_financiere ?? '-'}/20</b></span>
        <span>Familiale <b>{form.situation_familiale ?? '-'}/20</b></span>
        {form.situation_conjugale != null && <span>Conjugale <b>{form.situation_conjugale}/20</b></span>}
      </div>
      <div className="fiss-detail-sanct">
        <span>Corps {sanctBadge(form.sanctification_corps)}</span>
        <span>Ame {sanctBadge(form.sanctification_ame)}</span>
        <span>Esprit {sanctBadge(form.sanctification_esprit)}</span>
      </div>
      {form.comment && <p className="fiss-admin-comment">{form.comment}</p>}
    </div>
  )
}
