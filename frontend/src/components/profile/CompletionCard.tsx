import type { ProfileCompletion } from '../../types'

/** Progression du profil et informations manquantes (questionnaire dynamique). */
export function CompletionCard({ completion, onGo }: { completion: ProfileCompletion | null; onGo: (key: string) => void }) {
  if (!completion || (completion.percent >= 100 && completion.recommended.length === 0)) return null
  const done = completion.percent >= 100
  const r = 26
  const c = 2 * Math.PI * r

  return (
    <section className={`completion-card ${done ? 'done' : ''}`} aria-label="Complétion du profil">
      <svg className="completion-ring" width="68" height="68" viewBox="0 0 68 68" role="img" aria-label={`${completion.percent} %`}>
        <circle cx="34" cy="34" r={r} className="ring-track" />
        <circle cx="34" cy="34" r={r} className="ring-fill" strokeDasharray={c} strokeDashoffset={c * (1 - completion.percent / 100)} />
        <text x="34" y="39" textAnchor="middle">{completion.percent}%</text>
      </svg>
      <div className="completion-body">
        <strong>{done ? 'Profil complet 🎉' : 'Complétez votre profil'}</strong>
        {!done && <span>Ces informations aident vos responsables à mieux vous accompagner.</span>}
        <div className="completion-items">
          {completion.missing.map((m) => (
            <button key={m.key} className="completion-item" onClick={() => onGo(m.key)}>+ {m.label}</button>
          ))}
          {completion.recommended.map((m) => (
            <button key={m.key} className="completion-item soft" onClick={() => onGo(m.key)}>{m.label} (conseillé)</button>
          ))}
        </div>
      </div>
    </section>
  )
}
