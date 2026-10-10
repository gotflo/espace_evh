import type { VertumetreAnswers as Answers, VertumetreSection } from '../types'

/** Reponses d'un Vertumetre en lecture seule (le membre, ses responsables), section par section. */
export function VertumetreAnswers({ sections, answers }: { sections: VertumetreSection[]; answers: Answers }) {
  const label = (q: VertumetreSection['questions'][number], v: string) => q.options.find((o) => o.value === v)?.label ?? v
  return (
    <div className="vt-read">
      {sections.map((s) => (
        <section key={s.key} className="vt-read-section">
          <h4>{s.title}</h4>
          <dl>
            {s.questions.map((q) => {
              const value = answers[q.key]
              const extra = q.other ? answers[q.other.key] : q.details ? answers[q.details.key] : null
              const shown = Array.isArray(value) ? value.map((v) => label(q, v)).join(', ') : typeof value === 'string' ? (q.type === 'text' ? value : label(q, value)) : null
              if (!shown && !extra) return null
              return (
                <div key={q.key} className="vt-read-row">
                  <dt>{q.label}</dt>
                  <dd>{shown}{extra && <span className="vt-read-extra">{extra}</span>}</dd>
                </div>
              )
            })}
          </dl>
        </section>
      ))}
    </div>
  )
}
