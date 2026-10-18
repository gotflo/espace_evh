import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { scrollPageTo } from '../utils/pageScroll'
import { SkeletonCard } from '../components/Skeleton'
import { Icon } from '../components/Icon'
import { VertumetreAnswers } from '../components/VertumetreAnswers'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import type { VertumetreAnswers as Answers, VertumetreData, VertumetreForm, VertumetreQuestion } from '../types'
import { de } from '../utils/french'import { ModalLayer } from '../components/ModalLayer'


const fmt = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }) : '')
const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)

/** Le texte « Precisez » d'une question est-il demande (choix « Autre » coche) ? */
function otherShown(q: VertumetreQuestion, a: Answers): boolean {
  if (!q.other) return false
  const chosen = Array.isArray(a[q.key]) ? (a[q.key] as string[]) : [a[q.key] as string]
  return ([] as string[]).concat(q.other.choice).some((c) => chosen.includes(c))
}

function Question({ q, a, set }: { q: VertumetreQuestion; a: Answers; set: (key: string, v: string | string[] | null) => void }) {
  const value = a[q.key]
  return (
    <fieldset className="vt-q">
      <legend>{q.label}</legend>
      {q.hint && <p className="helper vt-hint">{q.hint}</p>}
      {q.type === 'single' && (
        <div className="vt-options" role="radiogroup">
          {q.options.map((o) => (
            <button key={o.value} type="button" role="radio" aria-checked={value === o.value}
              className={`vt-option ${value === o.value ? 'on' : ''}`} onClick={() => set(q.key, o.value)}>
              <span className="vt-radio" aria-hidden />{o.label}
            </button>
          ))}
        </div>
      )}
      {q.type === 'multi' && (
        <div className="vt-chips">
          {q.options.map((o) => {
            const list = Array.isArray(value) ? value : []
            const on = list.includes(o.value)
            return (
              <button key={o.value} type="button" aria-pressed={on} className={`chip-toggle vt-chip ${on ? 'on' : ''}`}
                onClick={() => set(q.key, on ? list.filter((x) => x !== o.value) : [...list, o.value])}>
                {on && <Icon name="check" size={13} />}{o.label}
              </button>
            )
          })}
        </div>
      )}
      {q.type === 'text' && (
        <textarea className="input" rows={3} maxLength={3000} aria-label={q.label} value={(value as string) ?? ''}
          onChange={(e) => set(q.key, e.target.value || null)} />
      )}
      {q.other && otherShown(q, a) && (
        <input className="input vt-extra" aria-label={q.other.label} placeholder={q.other.label + (q.other.optional ? ' (facultatif)' : '')}
          value={(a[q.other.key] as string) ?? ''} onChange={(e) => set(q.other!.key, e.target.value || null)} />
      )}
      {q.details && (
        <textarea className="input vt-extra" rows={3} maxLength={3000} aria-label={q.details.label} placeholder={q.details.label}
          value={(a[q.details.key] as string) ?? ''} onChange={(e) => set(q.details!.key, e.target.value || null)} />
      )}
    </fieldset>
  )
}

/** Etat du verrou (meme logique que la FISS) : verrouille, demande en attente, modification autorisee. */
function LockCard({ f, max, onRequest, onCancel, onEdit }: {
  f: VertumetreForm; max: number; onRequest: () => void; onCancel: (id: number) => void; onEdit: () => void
}) {
  if (f.editable) {
    return (
      <div className="lock-card open">
        <span className="lock-icon"><Icon name="unlock" size={20} /></span>
        <div className="lock-text">
          <strong>Modification autorisée</strong>
          <span>Vous pouvez modifier ce Vertumètre une fois, jusqu'au {fmt(f.can_edit_until)}.</span>
        </div>
        <button className="btn btn-primary small" onClick={onEdit}>Modifier</button>
      </div>
    )
  }
  return (
    <div className="lock-card">
      <span className="lock-icon"><Icon name="lock" size={20} /></span>
      <div className="lock-text">
        <strong>Vertumètre verrouillé</strong>
        {f.pending_request
          ? <span>Demande de modification envoyée le {fmt(f.pending_request.created_at)} · en attente de votre patriarche.</span>
          : <span>Enregistré le {fmt(f.submitted_at)}. {f.requests_left ? `Vous pouvez demander une modification (${f.requests_left} demande(s) restante(s) sur ${max}).` : 'Plus aucune demande de modification possible pour ce mois.'}</span>}
        {f.last_decision?.status === 'rejected' && !f.pending_request && (
          <span className="lock-note">Dernière demande refusée{f.last_decision.comment ? ` : « ${f.last_decision.comment} »` : '.'}</span>
        )}
      </div>
      {f.pending_request
        ? <button className="btn btn-ghost small" onClick={() => onCancel(f.pending_request!.id)}>Annuler la demande</button>
        : f.requests_left ? <button className="btn btn-ghost small" onClick={onRequest}>Demander une modification</button> : null}
    </div>
  )
}

export default function MyVertumetre() {
  const [data, setData] = useState<VertumetreData | null>(() => peek<VertumetreData>('/me/vertumetre') ?? null)
  const [params, setParams] = useSearchParams()
  const [step, setStep] = useState(0)
  const [answers, setAnswers] = useState<Answers>({})
  const [editing, setEditing] = useState(false)
  const [confirming, setConfirming] = useState(false)
  const [saved, setSaved] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [asking, setAsking] = useState(false)
  const [reason, setReason] = useState('')

  const load = useCallback(() => { api<VertumetreData>('/me/vertumetre').then(setData).catch(() => {}) }, [])
  useEffect(() => { load() }, [load])

  // Mois choisi : celui de l'adresse, sinon le premier mois a remplir, sinon le mois en cours.
  const period = useMemo(() => {
    if (!data) return null
    const wanted = params.get('mois')
    if (wanted && (data.periods.some((p) => p.period === wanted) || data.forms.some((f) => f.period === wanted))) return wanted
    return data.periods.find((p) => !p.filled)?.period ?? data.periods[0]?.period ?? null
  }, [data, params])
  const form = data?.forms.find((f) => f.period === period) ?? null
  const label = form?.period_label ?? data?.periods.find((p) => p.period === period)?.label ?? ''
  const filling = !!period && (!form || editing)
  const draftKey = period && filling ? `vertumetre:${period}${form ? ':edit' : ''}` : null

  useEffect(() => {
    if (!period) return
    setStep(0); setSaved(null); setEditing(false)
    setAnswers(readDraft<Answers>(`vertumetre:${period}`) ?? {})
  }, [period])
  useDraft(draftKey, answers, (v) => Object.values(v).every((x) => x === null || x === '' || (Array.isArray(x) && !x.length)))

  if (!data || !period) return <AppLayout title="Mon Vertumètre"><div className="panel-grid"><SkeletonCard /></div></AppLayout>

  const sections = data.sections
  const section = sections[step]
  const set = (key: string, v: string | string[] | null) => setAnswers((a) => ({ ...a, [key]: v }))
  // Questions sans reponse dans la section (choix uniques, et « Precisez » quand « Autre » est coche).
  const missingIn = (i: number) => sections[i].questions.filter((q) =>
    (q.type === 'single' && !answers[q.key]) || (q.other && !q.other.optional && otherShown(q, answers) && !answers[q.other.key])).length
  const missing = missingIn(step)
  const totalQuestions = sections.reduce((n, s) => n + s.questions.length, 0)
  const answered = sections.reduce((n, s) => n + s.questions.filter((q) => {
    const v = answers[q.key]
    return Array.isArray(v) ? v.length > 0 : !!v
  }).length, 0)

  function pick(p: string) { setParams({ mois: p }, { replace: true }) }

  async function submit() {
    setBusy(true)
    try {
      if (form) await api(`/me/vertumetre/${form.id}`, { method: 'PUT', body: { answers } })
      else await api('/me/vertumetre', { method: 'POST', body: { period, answers } })
      if (draftKey) clearDraft(draftKey)
      clearDraft(`vertumetre:${period}`)
      setConfirming(false); setEditing(false); setSaved(label)
      // On reste sur le mois enregistre (confirmation et reponses verrouillees), sans sauter au mois suivant a remplir.
      setParams({ mois: period! }, { replace: true })
      load()
      scrollPageTo(0, true)
    } catch { setConfirming(false) } finally { setBusy(false) }
  }

  async function sendRequest() {
    if (!form) return
    setBusy(true)
    try {
      await api(`/me/vertumetre/${form.id}/edit-requests`, { method: 'POST', body: { reason: reason.trim() } })
      setAsking(false); setReason(''); load()
    } catch { /* toast */ } finally { setBusy(false) }
  }

  async function cancelRequest(id: number) {
    if (!confirm('Annuler cette demande de modification ?')) return
    try { await api(`/me/vertumetre/edit-requests/${id}`, { method: 'DELETE' }); load() } catch { /* toast */ }
  }

  const months = data.periods

  return (
    <AppLayout title="Mon Vertumètre" subtitle={`Questionnaire mensuel · ${label}`}>
      {!form && <p className="helper vt-only-month">Le Vertumètre se remplit pendant le mois en cours : un mois passé ne peut plus être rempli.</p>}
      {/* Mois evalue : le mois en cours seulement (un mois passe ne se rattrape pas). */}
      {months.length > 1 && <div className="vt-months" role="tablist" aria-label="Mois évalué">
        {months.map((m) => (
          <button key={m.period} role="tab" aria-selected={m.period === period} className={`vt-month ${m.period === period ? 'on' : ''}`} onClick={() => pick(m.period)}>
            <strong>{cap(m.label)}</strong>
            <small>{m.filled ? <><Icon name="lock" size={12} /> Rempli</> : 'À remplir'}</small>
          </button>
        ))}
      </div>}

      {saved && (
        <section className="vt-saved" role="status">
          <span className="vt-saved-icon"><Icon name="check" size={26} /></span>
          <div>
            <strong>Vertumètre {de(saved)} enregistré</strong>
            <p>Merci ! Votre réponse est maintenant verrouillée.</p>
          </div>
        </section>
      )}

      {!filling && form && (
        <>
          <LockCard f={form} max={data.max_requests} onRequest={() => setAsking(true)} onCancel={cancelRequest}
            onEdit={() => { setAnswers(form.answers); setEditing(true); setStep(0) }} />
          <section className="panel mt">
            <div className="panel-head"><h3>Mes réponses {de(form.period_label)}</h3></div>
            <VertumetreAnswers sections={sections} answers={form.answers} />
          </section>
        </>
      )}

      {filling && section && (
        <section className="panel vt-form" aria-labelledby="vt-step-title">
          <div className="vt-progress" aria-hidden><span style={{ width: `${Math.round((answered / totalQuestions) * 100)}%` }} /></div>
          <div className="vt-steps">
            {sections.map((s, i) => (
              <button key={s.key} type="button" className={`vt-step ${i === step ? 'on' : ''} ${missingIn(i) === 0 ? 'ok' : ''}`} onClick={() => setStep(i)}
                aria-label={`Étape ${i + 1} : ${s.title}`}>
                <span>{missingIn(i) === 0 ? <Icon name="check" size={13} /> : i + 1}</span>
              </button>
            ))}
            <span className="vt-step-label">Étape {step + 1} sur {sections.length}</span>
          </div>
          <h3 id="vt-step-title" className="vt-section-title">{section.title}</h3>
          {section.intro && <p className="vt-intro">{section.intro}</p>}
          {editing && <p className="helper">Modification du Vertumètre {de(label)} : après l'enregistrement, il sera de nouveau verrouillé.</p>}

          {section.questions.map((q) => <Question key={q.key} q={q} a={answers} set={set} />)}

          <div className="vt-nav">
            {step > 0 && <button className="btn btn-ghost" onClick={() => { setStep(step - 1); scrollPageTo(0, true) }}>Précédent</button>}
            {step < sections.length - 1 ? (
              <button className="btn btn-primary" disabled={missing > 0} onClick={() => { setStep(step + 1); scrollPageTo(0, true) }}>
                {missing > 0 ? `Encore ${missing} réponse${missing > 1 ? 's' : ''}` : 'Suivant'}
              </button>
            ) : (
              <button className="btn btn-primary" disabled={busy || sections.some((_, i) => missingIn(i) > 0)} onClick={() => setConfirming(true)}>
                {sections.some((_, i) => missingIn(i) > 0) ? 'Répondez à toutes les questions' : form ? 'Enregistrer la modification' : `Enregistrer mon Vertumètre`}
              </button>
            )}
          </div>
          {editing && <button className="btn-link vt-cancel" onClick={() => { setEditing(false); clearDraft(`vertumetre:${period}:edit`) }}>Annuler la modification</button>}
          <p className="helper center">Brouillon gardé sur ce téléphone. Les questions à texte libre sont facultatives.</p>
        </section>
      )}

      {data.forms.filter((f) => f.period !== period).length > 0 && (
        <section className="panel mt">
          <div className="panel-head"><h3>Mois précédents</h3></div>
          <div className="fiss-admin-list">
            {data.forms.filter((f) => f.period !== period).map((f) => (
              <details key={f.id} className="fiss-admin-item">
                <summary><span className="fiss-hist-period">{cap(f.period_label)}</span><span className="fiss-hist-scores">Enregistré le {fmt(f.submitted_at)}</span></summary>
                <VertumetreAnswers sections={sections} answers={f.answers} />
              </details>
            ))}
          </div>
        </section>
      )}

      {confirming && (
        <ModalLayer className="modal-overlay" onClick={() => setConfirming(false)}>
          <div className="modal-box" role="dialog" aria-modal="true" aria-label="Confirmer l'enregistrement" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head"><h3>Enregistrer le Vertumètre {de(label)} ?</h3></div>
            <p className="event-desc">Une fois enregistré, il sera <strong>verrouillé</strong>. Une modification restera possible sur demande à votre patriarche.</p>
            <div className="row-actions mt">
              <button className="btn btn-ghost" onClick={() => setConfirming(false)}>Relire</button>
              <button className="btn btn-primary" disabled={busy} onClick={submit}>{busy ? <span className="spinner" /> : 'Confirmer'}</button>
            </div>
          </div>
        </ModalLayer>
      )}

      {asking && form && (
        <ModalLayer className="modal-overlay" onClick={() => setAsking(false)}>
          <div className="modal-box" role="dialog" aria-modal="true" aria-label="Demander une modification" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head"><h3>Demander une modification</h3><button className="btn-link" onClick={() => setAsking(false)}>Fermer</button></div>
            <p className="event-desc">Vertumètre {de(form.period_label)}. Votre patriarche recevra votre demande ({form.requests_left} demande(s) restante(s)).</p>
            <div className="field mt">
              <label htmlFor="vt-reason">Pourquoi souhaitez-vous le modifier ?</label>
              <textarea id="vt-reason" className="input" rows={3} autoFocus value={reason} onChange={(e) => setReason(e.target.value)} />
            </div>
            <button className="btn btn-primary" disabled={busy || reason.trim().length < 5} onClick={sendRequest}>{busy ? <span className="spinner" /> : 'Envoyer la demande'}</button>
          </div>
        </ModalLayer>
      )}
    </AppLayout>
  )
}
