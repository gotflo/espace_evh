import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '../../api/client'
import { clearDraft, readDraft, useDraft } from '../../utils/drafts'
import { fmtDay, isVisible, KIND_LABEL, stepErrors, visibleAnswers } from '../../utils/leaderReports'
import type { LeaderReportForm, LeaderReportKind, ReportAnswer, ReportAnswers, ReportQuestion } from '../../types'
import { SkeletonCard } from '../Skeleton'
import { Icon } from '../Icon'
import { GemsBlock, IndicatorsBlock, ReportView, SoulsBlock } from './ReportView'

type SetAnswer = (value: ReportAnswer) => void

function ChecksField({ q, value, onChange }: { q: ReportQuestion; value: Record<string, string>; onChange: SetAnswer }) {
  const exclusive = new Set(q.options?.filter((o) => o.exclusive).map((o) => o.key))
  function toggle(key: string) {
    if (key in value) {
      const rest = { ...value }
      delete rest[key]
      onChange(rest)
    } else if (exclusive.has(key)) {
      onChange({ [key]: '' })
    } else {
      // Cocher une activite retire « aucune activite ».
      onChange({ ...Object.fromEntries(Object.entries(value).filter(([k]) => !exclusive.has(k))), [key]: '' })
    }
  }
  return (
    <div className="lr-checks">
      {q.options?.map((o) => {
        const on = o.key in value
        return (
          <div key={o.key} className={`lr-check ${on ? 'on' : ''}`}>
            <label className="check-line">
              <input type="checkbox" checked={on} onChange={() => toggle(o.key)} />
              <span>{o.label}</span>
            </label>
            {on && (
              <textarea className="input" rows={2} maxLength={1000} aria-label={`${o.label} : précisions`} placeholder={o.placeholder}
                value={value[o.key]} onChange={(e) => onChange({ ...value, [o.key]: e.target.value })} />
            )}
          </div>
        )
      })}
    </div>
  )
}

function NamesField({ q, value, onChange }: { q: ReportQuestion; value: string[]; onChange: SetAnswer }) {
  const [name, setName] = useState('')
  function add() {
    const clean = name.trim()
    if (clean && !value.includes(clean)) onChange([...value, clean])
    setName('')
  }
  return (
    <div>
      {value.length > 0 && (
        <div className="chip-list lr-names">
          {value.map((n) => (
            <span key={n} className="chip-soft">
              {n}
              <button type="button" className="lr-name-remove" aria-label={`Retirer ${n}`} onClick={() => onChange(value.filter((x) => x !== n))}>×</button>
            </span>
          ))}
        </div>
      )}
      <div className="lr-name-add">
        <input className="input" maxLength={80} aria-label={q.label} placeholder="Prénom et nom" value={name}
          onChange={(e) => setName(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); add() } }} />
        <button type="button" className="btn btn-ghost small" disabled={!name.trim()} onClick={add}>Ajouter</button>
      </div>
    </div>
  )
}

function Question({ q, value, error, onChange }: { q: ReportQuestion; value: ReportAnswer | undefined; error?: string; onChange: SetAnswer }) {
  const id = `lr-${q.key}`
  return (
    <div className={`lr-question ${error ? 'has-error' : ''}`} id={id}>
      <p className="lr-label">{q.label}{!q.required && <span className="helper-inline"> (facultatif)</span>}</p>
      {q.type === 'text' && (
        <textarea className="input" rows={3} maxLength={3000} aria-label={q.label} placeholder={q.placeholder}
          value={typeof value === 'string' ? value : ''} onChange={(e) => onChange(e.target.value)} />
      )}
      {q.type === 'number' && (
        <input className="input lr-number" type="number" inputMode="numeric" min={q.min} max={q.max} aria-label={q.label}
          value={typeof value === 'number' ? value : ''}
          onChange={(e) => onChange(e.target.value === '' ? null : Math.max(q.min ?? 0, Math.min(q.max ?? 999, Math.round(Number(e.target.value)))))} />
      )}
      {q.type === 'choice' && (
        <div className="pill-choices lr-choices" role="radiogroup" aria-label={q.label}>
          {q.options?.map((o) => (
            <button key={o.key} type="button" role="radio" aria-checked={value === o.key}
              className={`pill-choice ${value === o.key ? 'on' : ''}`} onClick={() => onChange(o.key)}>{o.label}</button>
          ))}
        </div>
      )}
      {q.type === 'checks' && (
        <ChecksField q={q} value={value && typeof value === 'object' && !Array.isArray(value) ? value : {}} onChange={onChange} />
      )}
      {q.type === 'names' && <NamesField q={q} value={Array.isArray(value) ? value : []} onChange={onChange} />}
      {error && <p className="lr-error" role="alert">{error}</p>}
    </div>
  )
}

/**
 * Questionnaire pas a pas d'un rapport mensuel : une etape par point du rapport, puis une
 * relecture avant l'envoi. La saisie est gardee sur l'appareil a chaque frappe et sur le
 * serveur a chaque changement d'etape : on peut commencer sur un appareil et finir sur un autre.
 */
export function ReportWizard({ kind, scopeId, period, onClose, onSent }: {
  kind: LeaderReportKind
  scopeId: number
  period: string
  onClose: () => void
  onSent: (reportId: number) => void
}) {
  const [form, setForm] = useState<LeaderReportForm | null>(null)
  const [failed, setFailed] = useState(false)
  const [answers, setAnswers] = useState<ReportAnswers>({})
  const [step, setStep] = useState(0)
  const [reached, setReached] = useState(0)
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  // Saisie pas encore enregistree sur le serveur : gardee sur l'appareil.
  const [dirty, setDirty] = useState(false)
  const latest = useRef(answers)
  latest.current = answers
  const top = useRef<HTMLDivElement>(null)
  const draftKey = `leader-report:${kind}:${scopeId}:${period}`

  const load = useCallback(() => {
    setFailed(false)
    api<LeaderReportForm>(`/admin/leader-reports/form?kind=${kind}&scope_id=${scopeId}&period=${period}`)
      .then((f) => {
        const local = readDraft<ReportAnswers>(draftKey)
        setForm(f)
        // La saisie restee sur cet appareil est plus recente que la version du serveur.
        setAnswers(local ?? f.answers ?? {})
        setDirty(local !== null)
      })
      .catch(() => setFailed(true))
  }, [kind, scopeId, period, draftKey])
  useEffect(() => { load() }, [load])

  useDraft(form?.open && dirty ? draftKey : null, answers, (v) => Object.keys(v).length === 0)

  if (failed) {
    return (
      <div className="empty-state">
        <Icon name="alert" size={28} />
        <h3>Rapport indisponible</h3>
        <p>Ce rapport n'est pas à votre charge, ou la connexion a échoué.</p>
        <button className="btn btn-ghost small mt" onClick={onClose}>Retour aux rapports</button>
      </div>
    )
  }
  if (!form) return <div className="panel-grid"><SkeletonCard /><SkeletonCard /></div>

  const steps = form.steps
  const review = step >= steps.length
  const current = steps[Math.min(step, steps.length - 1)]
  const sent = form.status === 'submitted'
  const title = `${KIND_LABEL[form.kind]} ${form.scope_name} · ${form.period_label}`

  if (!form.open) {
    return (
      <div className="lock-card">
        <span className="lock-icon"><Icon name="lock" size={20} /></span>
        <div className="lock-text">
          <strong>{title}</strong>
          <span>{sent ? 'Ce rapport a été envoyé et ne peut plus être modifié.' : `Le rapport de ${form.period_label} ne peut plus être rempli.`}</span>
        </div>
        <button className="btn btn-ghost small" onClick={() => (sent && form.report_id ? onSent(form.report_id) : onClose())}>{sent ? 'Voir le rapport' : 'Retour'}</button>
      </div>
    )
  }

  const set = (key: string, value: ReportAnswer) => {
    setAnswers((a) => ({ ...a, [key]: value }))
    setDirty(true)
    setErrors((e) => { if (!(key in e)) return e; const rest = { ...e }; delete rest[key]; return rest })
  }

  function goTo(index: number) {
    setErrors({})
    setStep(index)
    setReached((r) => Math.max(r, index))
    top.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  }

  /** Brouillon sur le serveur (silencieux). Un rapport deja envoye se corrige puis se renvoie. */
  function saveDraft() {
    if (sent || !dirty) return
    const saved = answers
    api('/admin/leader-reports', { method: 'PUT', toast: false, body: { kind, scope_id: scopeId, period, answers: visibleAnswers(steps, answers) } })
      .then(() => {
        // Rien n'a ete saisi entre-temps : la copie de l'appareil n'est plus utile.
        if (latest.current === saved) { clearDraft(draftKey); setDirty(false) }
      })
      .catch(() => { /* la saisie reste sur l'appareil */ })
  }

  function next() {
    const found = stepErrors(current, answers)
    if (Object.keys(found).length) {
      setErrors(found)
      document.getElementById(`lr-${Object.keys(found)[0]}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
      return
    }
    saveDraft()
    goTo(step + 1)
  }

  async function send() {
    const incomplete = steps.findIndex((s) => Object.keys(stepErrors(s, answers)).length > 0)
    if (incomplete >= 0) {
      setStep(incomplete)
      setErrors(stepErrors(steps[incomplete], answers))
      return
    }
    setBusy(true)
    try {
      const r = await api<{ report_id: number }>('/admin/leader-reports', {
        method: 'PUT', body: { kind, scope_id: scopeId, period, answers: visibleAnswers(steps, answers), submit: true },
      })
      clearDraft(draftKey)
      onSent(r.report_id)
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  const others = Array.isArray(answers.ames_autres) ? answers.ames_autres : []

  return (
    <div className="lr-wizard" ref={top}>
      <div className="lr-wizard-head">
        <div>
          <p className="section-label">{sent ? 'Correction du rapport envoyé' : 'Rapport mensuel'}</p>
          <h2 className="lr-wizard-title">{title}</h2>
        </div>
        <button className="btn-link" onClick={() => { saveDraft(); onClose() }}>{sent ? 'Fermer' : 'Enregistrer et fermer'}</button>
      </div>

      <div className="lr-progress" role="progressbar" aria-valuemin={1} aria-valuemax={steps.length + 1} aria-valuenow={step + 1}
        aria-label={review ? 'Relecture avant envoi' : `Étape ${step + 1} sur ${steps.length}`}>
        {[...steps.map((s) => s.title), 'Relecture'].map((label, i) => (
          <button key={label} type="button" className={`lr-progress-step ${i === step ? 'current' : i < step ? 'done' : ''}`}
            disabled={i > reached} onClick={() => goTo(i)} title={label}>
            <span className="lr-progress-dot">{i < step ? <Icon name="check" size={12} /> : i + 1}</span>
            <span className="lr-progress-label">{label}</span>
          </button>
        ))}
      </div>

      {review ? (
        <section className="panel">
          <div className="panel-head"><h3>Relisez avant d'envoyer</h3></div>
          <p className="helper lr-intro">
            Une fois envoyé, le rapport est transmis {form.kind === 'tribe' ? "à l'Assistant Pasteur de la tribu et aux pasteurs" : 'aux pasteurs'}.
            Vous pourrez encore le corriger jusqu'au {fmtDay(form.closes_on)}.
          </p>
          <ReportView steps={steps} answers={visibleAnswers(steps, answers)} souls={form.context.souls} onEdit={goTo} />
          <div className="lr-actions">
            <button className="btn btn-ghost small" disabled={busy} onClick={() => goTo(steps.length - 1)}>Précédent</button>
            <button className="btn btn-primary small" disabled={busy} onClick={send}>
              {busy ? <span className="spinner" /> : sent ? 'Renvoyer le rapport corrigé' : 'Envoyer le rapport'}
            </button>
          </div>
        </section>
      ) : (
        <section className="panel">
          <div className="panel-head"><h3>{current.title}</h3><span className="count-soft">Étape {step + 1} sur {steps.length}</span></div>
          {current.intro && <p className="helper lr-intro">{current.intro}</p>}
          {current.context === 'gems' && <GemsBlock gems={form.context.gems} />}
          {current.context === 'indicators' && <IndicatorsBlock indicators={form.context.indicators} />}
          {current.context === 'souls' && <SoulsBlock souls={form.context.souls} others={others} />}
          {current.questions.filter((q) => isVisible(q, answers)).map((q) => (
            <Question key={q.key} q={q} value={answers[q.key]} error={errors[q.key]} onChange={(v) => set(q.key, v)} />
          ))}
          <div className="lr-actions">
            {step > 0 ? <button className="btn btn-ghost small" onClick={() => goTo(step - 1)}>Précédent</button> : <span />}
            <button className="btn btn-primary small" onClick={next}>{step === steps.length - 1 ? 'Relire le rapport' : 'Suivant'}</button>
          </div>
        </section>
      )}
    </div>
  )
}
