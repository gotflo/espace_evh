import type { LeaderReportContext, ReportAnswers, ReportQuestion, ReportSoul, ReportStep } from '../../types'
import { fmtDay, isEmpty, isVisible } from '../../utils/leaderReports'

/** Ames gagnees du mois : nombre et noms (inscriptions du mois + noms ajoutes a la main). */
export function SoulsBlock({ souls, others = [] }: { souls: ReportSoul[]; others?: string[] }) {
  const total = souls.length + others.length
  return (
    <div className="lr-context">
      <div className="lr-souls-count">
        <strong>{total}</strong>
        <span>{total > 1 ? 'âmes gagnées ce mois-ci' : 'âme gagnée ce mois-ci'}</span>
      </div>
      {souls.length === 0 ? (
        <p className="helper">Aucune nouvelle inscription enregistrée dans l'application pour ce mois.</p>
      ) : (
        <ul className="people-list">
          {souls.map((s) => (
            <li key={s.user_id}>
              <span>{s.name}</span>
              <small>
                {fmtDay(s.date)}
                {s.integrated !== null && <span className={`status-pill ${s.integrated ? 'on' : ''}`}>{s.integrated ? 'Accueilli(e)' : 'À accueillir'}</span>}
              </small>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function GemsBlock({ gems }: { gems: LeaderReportContext['gems'] }) {
  return (
    <div className="lr-context">
      <p className="lr-context-title">Les GEMs de votre tribu</p>
      {gems.length === 0 ? <p className="helper">Aucun GEM n'est encore créé dans cette tribu.</p> : (
        <ul className="people-list">
          {gems.map((g) => (
            <li key={g.id}>
              <span>{g.name}</span>
              <small>{g.leader ? `Garde : ${g.leader}` : 'Sans Garde'} · {g.members_count} membre(s)</small>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

export function IndicatorsBlock({ indicators }: { indicators: LeaderReportContext['indicators'] }) {
  if (indicators.length === 0) return null
  return (
    <div className="lr-context">
      <p className="lr-context-title">Repères du mois</p>
      <div className="kpi-mini-row">
        {indicators.map((i) => <div key={i.label} className="kpi-mini"><strong>{i.value}</strong><span>{i.label}</span></div>)}
      </div>
    </div>
  )
}

function Answer({ q, value }: { q: ReportQuestion; value: ReportAnswers[string] }) {
  if (isEmpty(value)) return <p className="lr-answer lr-answer-empty">Non renseigné</p>
  if (q.type === 'choice') return <p className="lr-answer"><span className="chip-soft">{q.options?.find((o) => o.key === value)?.label ?? String(value)}</span></p>
  if (q.type === 'checks' && value && typeof value === 'object' && !Array.isArray(value)) {
    return (
      <ul className="lr-answer-list">
        {q.options?.filter((o) => o.key in value).map((o) => (
          <li key={o.key}><strong>{o.label}</strong>{value[o.key] && <span>{value[o.key]}</span>}</li>
        ))}
      </ul>
    )
  }
  if (Array.isArray(value)) return <p className="lr-answer chip-list">{value.map((n) => <span key={n} className="chip-soft">{n}</span>)}</p>
  return <p className="lr-answer">{String(value)}</p>
}

/** Lecture d'un rapport : chaque etape avec ses questions posees et leurs reponses. */
export function ReportView({ steps, answers, souls, onEdit }: {
  steps: ReportStep[]
  answers: ReportAnswers
  souls: ReportSoul[]
  onEdit?: (stepIndex: number) => void
}) {
  const others = Array.isArray(answers.ames_autres) ? answers.ames_autres : []
  return (
    <div className="lr-view">
      {steps.map((step, i) => (
        <section key={step.key} className="lr-view-step">
          <div className="lr-view-head">
            <h4><span className="lr-view-num">{i + 1}</span>{step.title}</h4>
            {onEdit && <button type="button" className="btn-link" onClick={() => onEdit(i)}>Modifier</button>}
          </div>
          {step.context === 'souls' && <SoulsBlock souls={souls} others={others} />}
          {step.questions.filter((q) => isVisible(q, answers) && (q.required || !isEmpty(answers[q.key]))).map((q) => (
            <div key={q.key} className="lr-view-item">
              <p className="lr-view-label">{q.label}</p>
              <Answer q={q} value={answers[q.key]} />
            </div>
          ))}
        </section>
      ))}
    </div>
  )
}
