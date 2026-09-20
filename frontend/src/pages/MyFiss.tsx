import { useEffect, useMemo, useState } from 'react'
import { api, ApiError } from '../api/client'
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

function ScoreField({ label, hint, value, onChange }: { label: string; hint?: string; value: number | null; onChange: (v: number | null) => void }) {
  return (
    <div className="fiss-field">
      <label>{label}{hint && <span className="helper" style={{ display: 'inline' }}> {hint}</span>}</label>
      <div className="fiss-score">
        <input className="input" type="number" min="0" max="20" value={value ?? ''}
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

export default function MyFiss() {
  const [data, setData] = useState<FissData | null>(null)
  const [form, setForm] = useState<FissForm>(EMPTY)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [ok, setOk] = useState('')

  useEffect(() => {
    api<FissData>('/me/fiss').then((d) => { setData(d); setForm(d.current ?? EMPTY) }).catch(() => setData(null))
  }, [])

  const set = <K extends keyof FissForm>(k: K, v: FissForm[K]) => setForm((f) => ({ ...f, [k]: v }))

  const vieSpi = useMemo(() => (form.meditation ?? 0) + (form.priere ?? 0) + (form.jeune ?? 0), [form])
  const vieSoc = useMemo(() => (form.situation_financiere ?? 0) + (form.situation_familiale ?? 0) + (form.situation_conjugale ?? 0), [form])

  async function save() {
    setError(''); setOk(''); setBusy(true)
    try {
      const r = await api<{ current: FissForm }>('/me/fiss', { method: 'POST', body: form })
      setOk('Fiche enregistrée. Merci !')
      setData((d) => d ? { ...d, filled: true, current: r.current, reminder: { level: 'none', days_left: null } } : d)
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  if (!data) return <AppLayout title="Fiche de santé spirituelle"><div className="panel-grid"><SkeletonCard /><SkeletonCard /></div></AppLayout>

  const rl = data.reminder.level
  return (
    <AppLayout title="Fiche de santé spirituelle" subtitle={`Auto-évaluation mensuelle · ${data.period_label}`}>
      {error && <div className="alert alert-error">{error}</div>}
      {ok && <div className="alert alert-ok">{ok}</div>}

      {!data.filled && rl !== 'none' && (
        <div className={`fiss-reminder fiss-reminder-${rl}`}>
          <span className="fiss-reminder-icon">{rl === 'urgent' ? '⏰' : '📝'}</span>
          <div>
            <strong>{rl === 'urgent' ? 'Derniers jours !' : 'Fiche du mois à remplir'}</strong>
            <small>Votre fiche de {data.period_label} n'est pas encore remplie{data.reminder.days_left != null ? ` · ${data.reminder.days_left} jour(s) avant la fin du mois` : ''}.</small>
          </div>
        </div>
      )}
      {data.filled && <div className="alert alert-ok">Votre fiche de {data.period_label} est remplie. Vous pouvez la modifier ci-dessous.</div>}

      {data.filled && data.current && data.history[0] && (
        <FissEvolution current={data.current} previous={data.history[0]} previousLabel={data.history[0].period_label} />
      )}

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
              {busy ? <span className="spinner" /> : 'Enregistrer ma fiche du mois'}
            </button>
          </section>
        </div>

        <aside className="fiss-aside">
          <div className="panel">
            <div className="panel-head"><h3>Indices de notation</h3></div>
            <p className="helper" style={{ marginTop: 0 }}>Aide-vous de ce barème pour vous noter.</p>
            {Object.values(data.indices).map((idx, i) => <IndexBox key={i} idx={idx} />)}
          </div>
        </aside>
      </div>

      {data.history.length > 0 && (
        <section className="panel mt">
          <div className="panel-head"><h3>Historique des mois precedents</h3></div>
          <div className="fiss-admin-list">
            {data.history.map((h, i) => (
              <details key={h.id} className="fiss-admin-item">
                <summary>
                  <span className="fiss-hist-period">{h.period_label}</span>
                  <span className="fiss-hist-scores">Spirituelle {h.vie_spirituelle_total}/60 · Sociale {h.vie_sociale_total}</span>
                </summary>
                <div className="fiss-admin-detail">
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
    </AppLayout>
  )
}
