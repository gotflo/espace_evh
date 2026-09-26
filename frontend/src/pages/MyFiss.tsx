import { useCallback, useEffect, useMemo, useState } from 'react'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import { api } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import { FissEvolution } from '../components/FissEvolution'
import { FissDetail } from '../components/FissDetail'
import type { FissData, FissForm, FissIndex } from '../types'

const SANCT = [['bien', 'Bien'], ['moyen', 'Moyen'], ['mal', 'Mal']]

const EMPTY: FissForm = {
  meditation: null, priere: null, jeune: null,
  sanctification_corps: null, sanctification_ame: null, sanctification_esprit: null,
  situation_financiere: null, situation_familiale: null, situation_conjugale: null, comment: null,
}
const FIELDS = Object.keys(EMPTY) as (keyof FissForm)[]

function fmtDate(iso?: string | null): string {
  return iso ? new Date(iso).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }) : ''
}

function ScoreField({ label, hint, value, onChange }: { label: string; hint?: string; value: number | null; onChange: (v: number | null) => void }) {
  return (
    <div className="fiss-field">
      <label>{label}{hint && <span className="helper" style={{ display: 'inline' }}> {hint}</span>}</label>
      <div className="fiss-score">
        <input className="input" type="number" inputMode="numeric" min="0" max="20" value={value ?? ''}
          onChange={(e) => onChange(e.target.value === '' ? null : Math.max(0, Math.min(20, Number(e.target.value))))} />
        <span className="fiss-score-max">/20</span>
      </div>
    </div>
  )
}

function SanctField({ label, value, onChange }: { label: string; value: string | null; onChange: (v: string) => void }) {
  return (
    <div className="fiss-field">
      <label>{label}</label>
      <div className="pill-choices">
        {SANCT.map(([k, l]) => (
          <button key={k} type="button" className={`pill-choice sanct-${k} ${value === k ? 'on' : ''}`} onClick={() => onChange(k)}>{l}</button>
        ))}
      </div>
    </div>
  )
}

function IndexBox({ idx }: { idx: FissIndex }) {
  return (
    <div className="fiss-index">
      <h4>{idx.title}{idx.scale && <small> {idx.scale}</small>}</h4>
      <ul>
        {idx.levels.map((lv, i) => (
          <li key={i}><b>{lv.range}</b> {lv.label}</li>
        ))}
      </ul>
    </div>
  )
}

/**
 * Etat de verrouillage d'une fiche : verrouillee, demande en attente, modification autorisee,
 * nombre de demandes restantes (2 au maximum par fiche, regle serveur).
 */
function LockStatus({ f, max, onRequest, onCancel, onEdit }: {
  f: FissForm; max: number
  onRequest: (f: FissForm) => void; onCancel: (id: number) => void; onEdit: (f: FissForm) => void
}) {
  if (f.editable) {
    return (
      <div className="lock-card open">
        <span className="lock-icon" aria-hidden>🔓</span>
        <div className="lock-text">
          <strong>Modification autorisée</strong>
          <span>Votre patriarche a accepté : vous pouvez modifier cette fiche une fois, jusqu'au {fmtDate(f.can_edit_until)}.</span>
        </div>
        <button className="btn btn-primary small" onClick={() => onEdit(f)}>Modifier</button>
      </div>
    )
  }
  return (
    <div className="lock-card">
      <span className="lock-icon" aria-hidden>🔒</span>
      <div className="lock-text">
        <strong>Fiche verrouillée</strong>
        {f.pending_request ? (
          <span>Demande de modification envoyée le {fmtDate(f.pending_request.created_at)} · en attente de votre patriarche.</span>
        ) : (
          <span>
            Enregistrée le {fmtDate(f.submitted_at)}.{' '}
            {f.requests_left ? `Vous pouvez demander une modification (${f.requests_left} demande(s) restante(s) sur ${max}).` : 'Plus aucune demande de modification possible pour cette fiche.'}
          </span>
        )}
        {f.last_decision?.status === 'rejected' && !f.pending_request && (
          <span className="lock-note">Dernière demande refusée{f.last_decision.comment ? ` : « ${f.last_decision.comment} »` : '.'}</span>
        )}
      </div>
      {f.pending_request
        ? <button className="btn btn-ghost small" onClick={() => onCancel(f.pending_request!.id)}>Annuler la demande</button>
        : f.requests_left ? <button className="btn btn-ghost small" onClick={() => onRequest(f)}>Demander une modification</button> : null}
    </div>
  )
}

export default function MyFiss() {
  const [data, setData] = useState<FissData | null>(null)
  const [form, setForm] = useState<FissForm>(EMPTY)
  // null = nouvelle fiche du mois ; sinon fiche existante deverrouillee en cours de modification.
  const [editing, setEditing] = useState<FissForm | null>(null)
  const [busy, setBusy] = useState(false)
  const [requestFor, setRequestFor] = useState<FissForm | null>(null)
  const [reason, setReason] = useState('')

  const load = useCallback(() => {
    api<FissData>('/me/fiss').then((d) => {
      setData(d)
      setEditing(null)
      setForm(d.current ? { ...EMPTY } : (readDraft<FissForm>('fiss:new') ?? EMPTY))
    }).catch(() => setData(null))
  }, [])
  useEffect(() => { load() }, [load])

  const set = <K extends keyof FissForm>(k: K, v: FissForm[K]) => setForm((f) => ({ ...f, [k]: v }))
  // Brouillon de la fiche en cours de saisie (jamais perdue si l'envoi echoue).
  const draftKey = editing?.id ? `fiss:${editing.id}` : data && !data.current ? 'fiss:new' : null
  useDraft(draftKey, form, (v) => FIELDS.every((k) => v[k] === null || v[k] === undefined || v[k] === ''))

  const vieSpi = useMemo(() => (form.meditation ?? 0) + (form.priere ?? 0) + (form.jeune ?? 0), [form])
  const vieSoc = useMemo(() => (form.situation_financiere ?? 0) + (form.situation_familiale ?? 0) + (form.situation_conjugale ?? 0), [form])

  function startEdit(f: FissForm) {
    setEditing(f)
    setForm(Object.fromEntries(FIELDS.map((k) => [k, f[k] ?? null])) as unknown as FissForm)
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }

  async function save() {
    setBusy(true)
    const body = Object.fromEntries(FIELDS.map((k) => [k, form[k]]))
    try {
      if (editing?.id) await api(`/me/fiss/${editing.id}`, { method: 'PUT', body })
      else await api('/me/fiss', { method: 'POST', body })
      if (draftKey) clearDraft(draftKey)
      load()
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  async function sendRequest() {
    if (!requestFor?.id) return
    setBusy(true)
    try {
      await api(`/me/fiss/${requestFor.id}/edit-requests`, { method: 'POST', body: { reason: reason.trim() } })
      setRequestFor(null); setReason(''); load()
    } catch { /* toast */ } finally { setBusy(false) }
  }

  async function cancelRequest(id: number) {
    if (!confirm('Annuler cette demande de modification ?')) return
    try { await api(`/me/fiss/edit-requests/${id}`, { method: 'DELETE' }); load() } catch { /* toast */ }
  }

  if (!data) return <AppLayout title="Fiche de santé spirituelle"><div className="panel-grid"><SkeletonCard /><SkeletonCard /></div></AppLayout>

  const rl = data.reminder.level
  const showForm = !data.filled || editing !== null

  return (
    <AppLayout title="Fiche de santé spirituelle" subtitle={`Auto-évaluation mensuelle · ${data.period_label}`}>
      {!data.filled && rl !== 'none' && (
        <div className={`fiss-reminder fiss-reminder-${rl}`}>
          <span className="fiss-reminder-icon">{rl === 'urgent' ? '⏰' : '📝'}</span>
          <div>
            <strong>{rl === 'urgent' ? 'Derniers jours !' : 'Fiche du mois à remplir'}</strong>
            <small>Votre fiche de {data.period_label} n'est pas encore remplie{data.reminder.days_left != null ? ` · ${data.reminder.days_left} jour(s) avant la fin du mois` : ''}.</small>
          </div>
        </div>
      )}
      {!data.filled && (
        <p className="helper fiss-lock-hint">🔒 Une fois enregistrée, la fiche est verrouillée : vérifiez bien vos réponses. Une modification restera possible sur demande à votre patriarche.</p>
      )}

      {data.current && !editing && (
        <>
          <LockStatus f={data.current} max={data.max_requests} onRequest={setRequestFor} onCancel={cancelRequest} onEdit={startEdit} />
          {data.history[0] && <FissEvolution current={data.current} previous={data.history[0]} previousLabel={data.history[0].period_label} />}
          <section className="panel mt">
            <div className="panel-head">
              <h3>Ma fiche de {data.period_label}</h3>
              {data.current.spiritual_score != null && <span className="score-pill">{Math.round(data.current.spiritual_score)} %</span>}
            </div>
            <FissDetail form={data.current} />
          </section>
        </>
      )}

      {editing && (
        <div className="lock-card open">
          <span className="lock-icon" aria-hidden>✏️</span>
          <div className="lock-text">
            <strong>Modification de la fiche de {editing.period_label}</strong>
            <span>Après enregistrement, la fiche sera de nouveau verrouillée.</span>
          </div>
          <button className="btn btn-ghost small" onClick={() => { setEditing(null); setForm(EMPTY) }}>Annuler</button>
        </div>
      )}

      {showForm && (
        <div className="fiss-layout">
          <div className="fiss-main">
            <section className="panel">
              <div className="panel-head"><h3>Vie spirituelle <span className="fiss-total">{vieSpi}/60</span></h3></div>
              <ScoreField label="Méditation" value={form.meditation} onChange={(v) => set('meditation', v)} />
              <ScoreField label="Prière" value={form.priere} onChange={(v) => set('priere', v)} />
              <ScoreField label="Jeûne" value={form.jeune} onChange={(v) => set('jeune', v)} />
              <div className="fiss-subtitle">Sanctification</div>
              <SanctField label="Du corps (impudicité, mensonge, excès...)" value={form.sanctification_corps} onChange={(v) => set('sanctification_corps', v)} />
              <SanctField label="De l'âme (colère, jalousie, rancunes...)" value={form.sanctification_ame} onChange={(v) => set('sanctification_ame', v)} />
              <SanctField label="De l'esprit (idolâtrie, fausses doctrines...)" value={form.sanctification_esprit} onChange={(v) => set('sanctification_esprit', v)} />
            </section>

            <section className="panel mt">
              <div className="panel-head"><h3>Vie sociale <span className="fiss-total">{vieSoc}/{form.situation_conjugale != null ? 60 : 40}</span></h3></div>
              <ScoreField label="Situation financière" value={form.situation_financiere} onChange={(v) => set('situation_financiere', v)} />
              <ScoreField label="Situation familiale" hint="(famille biologique)" value={form.situation_familiale} onChange={(v) => set('situation_familiale', v)} />
              <ScoreField label="Situation conjugale" hint="(mariés uniquement)" value={form.situation_conjugale} onChange={(v) => set('situation_conjugale', v)} />
              <div className="fiss-field">
                <label>Commentaire (optionnel)</label>
                <textarea className="input" rows={3} value={form.comment ?? ''} onChange={(e) => set('comment', e.target.value)} />
              </div>
              <button className="btn btn-primary mt" disabled={busy} onClick={save}>
                {busy ? <span className="spinner" /> : editing ? 'Enregistrer la modification' : 'Enregistrer ma fiche du mois'}
              </button>
            </section>
          </div>

          <aside className="fiss-aside">
            <div className="panel">
              <div className="panel-head"><h3>Indices de notation</h3></div>
              <p className="helper" style={{ marginTop: 0 }}>Aidez-vous de ce barème pour vous noter.</p>
              {Object.values(data.indices).map((idx, i) => <IndexBox key={i} idx={idx} />)}
            </div>
          </aside>
        </div>
      )}

      {data.history.length > 0 && (
        <section className="panel mt">
          <div className="panel-head"><h3>Historique des mois précédents</h3></div>
          <div className="fiss-admin-list">
            {data.history.map((h, i) => (
              <details key={h.id} className="fiss-admin-item">
                <summary>
                  <span className="fiss-hist-period">{h.period_label} {h.editable ? '🔓' : h.pending_request ? '⏳' : '🔒'}</span>
                  <span className="fiss-hist-scores">
                    {h.spiritual_score != null ? `Vie spirituelle ${Math.round(h.spiritual_score)} %` : `Spirituelle ${h.vie_spirituelle_total}/60`}
                  </span>
                </summary>
                <div className="fiss-admin-detail">
                  <LockStatus f={h} max={data.max_requests} onRequest={setRequestFor} onCancel={cancelRequest} onEdit={startEdit} />
                  <FissDetail form={h} />
                  {data.history[i + 1] && (
                    <FissEvolution current={h} previous={data.history[i + 1]} previousLabel={data.history[i + 1].period_label} />
                  )}
                </div>
              </details>
            ))}
          </div>
        </section>
      )}

      {requestFor && (
        <div className="modal-overlay" onClick={() => setRequestFor(null)}>
          <div className="modal-box" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head">
              <h3>Demander une modification</h3>
              <button className="btn-link" onClick={() => setRequestFor(null)}>Fermer</button>
            </div>
            <p className="event-desc">
              Fiche de {requestFor.period_label}. Votre patriarche recevra votre demande. S'il l'accepte, vous pourrez modifier la fiche une fois
              ({requestFor.requests_left} demande(s) restante(s)).
            </p>
            <div className="field mt">
              <label>Pourquoi souhaitez-vous la modifier ?</label>
              <textarea className="input" rows={3} value={reason} autoFocus onChange={(e) => setReason(e.target.value)}
                placeholder="Ex. je me suis trompé dans la note de jeûne" />
            </div>
            <button className="btn btn-primary" disabled={busy || reason.trim().length < 5} onClick={sendRequest}>
              {busy ? <span className="spinner" /> : 'Envoyer la demande'}
            </button>
          </div>
        </div>
      )}
    </AppLayout>
  )
}
