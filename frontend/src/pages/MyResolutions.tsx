import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import { Icon } from '../components/Icon'
import { YouTubePlayer } from '../components/YouTubePlayer'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import { usePulse } from '../pulse'
import type { MonthlyResolution, ResolutionKey, ResolutionOutcome, ResolutionResource, ResolutionsData } from '../types'
import { de } from '../utils/french'import { ModalLayer } from '../components/ModalLayer'


type Texts = Record<ResolutionKey, string>

const LEVEL_LABEL = { bon: 'bon niveau', moyen: 'à affermir', faible: 'à fortifier' } as const

const emptyTexts = (keys: ResolutionKey[]): Texts => Object.fromEntries(keys.map((k) => [k, ''])) as Texts
const textsOf = (keys: ResolutionKey[], r: MonthlyResolution | null): Texts =>
  Object.fromEntries(keys.map((k) => [k, r?.[k] ?? ''])) as Texts

// ---------------------------------------------------------------- Bilan du mois passe

function Review({ data, onDone }: { data: ResolutionsData; onDone: () => void }) {
  const prev = data.previous!
  const keys = (Object.keys(data.fields) as ResolutionKey[]).filter((k) => prev[k])
  const [answers, setAnswers] = useState<Partial<Record<ResolutionKey, ResolutionOutcome>>>({})
  const [busy, setBusy] = useState(false)
  const answered = keys.filter((k) => answers[k]).length

  async function save() {
    setBusy(true)
    try {
      await api('/me/resolutions/review', { method: 'POST', body: { outcome: answers } })
      onDone()
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  return (
    <section className="panel rm-review" aria-labelledby="rm-review-title">
      <div className="rm-step-head">
        <span className="rm-step-num">1</span>
        <div>
          <h3 id="rm-review-title">Bilan {de(prev.period_label)}</h3>
          <p className="helper">Vos résolutions ont-elles été atteintes ? Votre bilan aide vos responsables à vous encourager.</p>
        </div>
      </div>
      <ul className="rm-review-list">
        {keys.map((k) => (
          <li key={k} className="rm-review-item">
            <span className="rm-review-label">{data.fields[k].label}</span>
            <p className="rm-review-text">{prev[k]}</p>
            <div className="rm-outcomes" role="radiogroup" aria-label={`Bilan : ${data.fields[k].label}`}>
              {(Object.entries(data.outcomes) as [ResolutionOutcome, string][]).map(([o, label]) => (
                <button key={o} type="button" role="radio" aria-checked={answers[k] === o}
                  className={`rm-outcome rm-outcome-${o} ${answers[k] === o ? 'on' : ''}`}
                  onClick={() => setAnswers((a) => ({ ...a, [k]: o }))}>
                  {o === 'atteinte' && <Icon name="check" size={14} />}{label}
                </button>
              ))}
            </div>
          </li>
        ))}
      </ul>
      <button className="btn btn-primary" disabled={busy || answered === 0} onClick={save}>
        {busy ? <span className="spinner" /> : answered < keys.length ? `Enregistrer mon bilan (${answered}/${keys.length})` : 'Enregistrer mon bilan'}
      </button>
    </section>
  )
}

/** Bilan deja fait : resume encourageant. */
function ReviewSummary({ prev }: { prev: MonthlyResolution }) {
  const total = Object.keys(prev.outcome ?? {}).length
  return (
    <div className="rm-review-done">
      <Icon name="star" size={18} />
      <span>
        Bilan {de(prev.period_label)} : <strong>{prev.achieved}/{total}</strong> résolution{total > 1 ? 's' : ''} atteinte{prev.achieved > 1 ? 's' : ''}.
      </span>
    </div>
  )
}

// ---------------------------------------------------------------- Ressources

/** Ressources publiees par theme ; l'onglet ouvert est choisi ici ou depuis le lien « Ressources » d'une rubrique. */
function Resources({ groups, theme, onTheme }: { groups: ResolutionsData['resources']; theme: string | null; onTheme: (t: string) => void }) {
  const filled = groups.filter((g) => g.items.length > 0)
  const [video, setVideo] = useState<ResolutionResource | null>(null)
  // Avancement mis a jour pendant la lecture (sans recharger la page).
  const [watched, setWatched] = useState<Record<number, { percent: number; completed: boolean }>>({})
  const watchOf = (r: ResolutionResource) => watched[r.id] ?? { percent: r.watch?.percent ?? 0, completed: r.watch?.completed ?? false }
  const onWatched = (id: number, percent: number, completed: boolean) => setWatched((w) => ({ ...w, [id]: { percent, completed } }))
  if (filled.length === 0) return null
  const current = filled.find((g) => g.theme === theme) ?? filled[0]

  return (
    <section className="panel mt rm-resources" id="ressources" aria-labelledby="rm-res-title">
      <div className="panel-head"><h3 id="rm-res-title">Ressources pour vous aider</h3></div>
      <div className="seg rm-themes" role="tablist">
        {filled.map((g) => (
          <button key={g.theme} role="tab" aria-selected={g.theme === current.theme} className={`seg-btn ${g.theme === current.theme ? 'on' : ''}`} onClick={() => onTheme(g.theme)}>
            {g.label} <small>{g.items.length}</small>
          </button>
        ))}
      </div>
      <ul className="resource-list">
        {current.items.map((r) => (
          <li key={r.id}>
            {r.youtube_id ? (
              <button type="button" className="resource-card" onClick={() => setVideo(r)}>
                <span className="resource-thumb"><img src={`https://i.ytimg.com/vi/${r.youtube_id}/mqdefault.jpg`} alt="" loading="lazy" /><Icon name="play" size={22} /></span>
                <span className="resource-text"><strong>{r.title}</strong><small>par {r.author}</small>{r.note && <em>{r.note}</em>}
                  {watchOf(r).percent > 0 && (
                    <span className="res-watch"><span className="watch-bar" aria-hidden><span style={{ width: `${watchOf(r).percent}%` }} className={watchOf(r).completed ? 'done' : ''} /></span>{watchOf(r).completed ? 'Regardée' : `${watchOf(r).percent} %`}</span>
                  )}
                </span>
              </button>
            ) : (
              <a className="resource-card" href={r.url} target="_blank" rel="noopener noreferrer">
                <span className="resource-thumb link"><Icon name="link" size={22} /></span>
                <span className="resource-text"><strong>{r.title}</strong><small>par {r.author}</small>{r.note && <em>{r.note}</em>}</span>
                <Icon name="external" size={16} className="resource-ext" />
              </a>
            )}
          </li>
        ))}
      </ul>
      {video?.youtube_id && (
        <ModalLayer className="modal-overlay" onClick={() => setVideo(null)}>
          <div className="modal-box media-modal" role="dialog" aria-modal="true" aria-label={video.title} onClick={(e) => e.stopPropagation()}>
            <div className="panel-head">
              <h3>{video.title}</h3>
              <button className="btn-link" onClick={() => setVideo(null)}>Fermer</button>
            </div>
            {/* Lue dans l'application, avec le meme suivi que les exercices et les devoirs : reprise la ou l'on s'est arrete. */}
            <YouTubePlayer key={video.id} target={{ kind: 'resource', id: video.id }} videoId={video.youtube_id}
              resumeAt={video.watch?.resume_at ?? 0} track={!video.watch?.completed} onProgress={(r) => onWatched(video.id, r.percent, r.video_completed)} />
            <div className="watch-progress" aria-live="polite">
              <div className="watch-bar" role="progressbar" aria-valuenow={watchOf(video).percent} aria-valuemin={0} aria-valuemax={100} aria-label="Vidéo regardée">
                <span style={{ width: `${watchOf(video).percent}%` }} className={watchOf(video).completed ? 'done' : ''} />
              </div>
              <p className="helper">
                {watchOf(video).completed ? 'Vidéo regardée en entier.' : `Regardée à ${watchOf(video).percent} %.`} Par {video.author}.
                {!watchOf(video).completed && (video.watch?.resume_at ?? 0) > 5 && ' La lecture reprend là où vous vous étiez arrêté.'}
              </p>
            </div>
          </div>
        </ModalLayer>
      )}
    </section>
  )
}

// ---------------------------------------------------------------- Page

/** Textes de depart : brouillon du mois s'il existe, sinon les resolutions enregistrees (ou vides). */
function initialTexts(d: ResolutionsData): Texts {
  const ks = Object.keys(d.fields) as ResolutionKey[]
  return readDraft<Texts>(`rm:${d.period}`) ?? (d.current ? textsOf(ks, d.current) : emptyTexts(ks))
}

export default function MyResolutions() {
  // Page deja ouverte : affichee tout de suite (derniere lecture), puis mise a jour en silence.
  const [data, setData] = useState<ResolutionsData | null>(() => peek<ResolutionsData>('/me/resolutions') ?? null)
  const [editing, setEditing] = useState(() => { const d = peek<ResolutionsData>('/me/resolutions'); return !!d && !d.current })
  const [texts, setTexts] = useState<Texts | null>(() => { const d = peek<ResolutionsData>('/me/resolutions'); return d ? initialTexts(d) : null })
  // Onglet des ressources ouvert (lien « Ressources » d'une rubrique : celui de son theme).
  const [resTheme, setResTheme] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const keys = useMemo(() => (data ? Object.keys(data.fields) as ResolutionKey[] : []), [data])
  const draftKey = data ? `rm:${data.period}` : null

  // reset : textes et mode d'edition repris du serveur. Mise a jour silencieuse a l'ouverture :
  // la saisie deja commencee n'est jamais remplacee.
  const load = useCallback((reset = true) => {
    api<ResolutionsData>('/me/resolutions').then((d) => {
      setData(d)
      if (!reset) return
      setTexts(initialTexts(d))
      setEditing(!d.current)
    }).catch(() => {})
  }, [])
  useEffect(() => { load(!peek('/me/resolutions')) }, [load])
  usePulse('resolutions', () => { if (!editing) load() })

  useDraft(editing ? draftKey : null, texts, (v) => !v || Object.values(v).every((t) => !t.trim()))

  if (!data || !texts) {
    return <AppLayout title="Mes résolutions du mois"><div className="panel-grid"><SkeletonCard /><SkeletonCard /></div></AppLayout>
  }

  const prev = data.previous
  const prevMonth = prev?.period === monthBefore(data.period) ? prev : null
  const set = (k: ResolutionKey, v: string) => setTexts((t) => ({ ...(t as Texts), [k]: v }))
  const filledCount = keys.filter((k) => texts[k].trim()).length
  const unchanged = !!data.current && keys.every((k) => texts[k].trim() === (data.current?.[k] ?? '').trim())
  const resourcesByTheme = Object.fromEntries(data.resources.map((g) => [g.theme, g.items.length]))

  function renewAll() {
    if (!prevMonth) return
    setTexts((t) => Object.fromEntries(keys.map((k) => [k, prevMonth[k] ?? (t as Texts)[k]])) as Texts)
  }

  async function save() {
    setBusy(true)
    try {
      await api('/me/resolutions', { method: 'PUT', body: texts })
      if (draftKey) clearDraft(draftKey)
      load()
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  return (
    <AppLayout title="Mes résolutions du mois" subtitle={`Mes RM · ${data.period_label}`}>
      <figure className="rm-verse">
        <Icon name="target" size={22} />
        <blockquote>« {data.verse.text} »</blockquote>
        <figcaption>{data.verse.reference}</figcaption>
      </figure>

      {/* Ressources en haut de la page : elles aident a prendre et a tenir ses resolutions. */}
      <Resources groups={data.resources} theme={resTheme} onTheme={setResTheme} />

      {data.review_needed && prev && <Review data={data} onDone={load} />}
      {!data.review_needed && prevMonth?.outcome && <ReviewSummary prev={prevMonth} />}

      {!editing && data.current ? (
        <section className="panel mt rm-current">
          <div className="panel-head">
            <h3>Mes résolutions {de(data.period_label)}</h3>
            <button className="btn btn-ghost small" onClick={() => setEditing(true)}><Icon name="edit" size={15} /> Modifier</button>
          </div>
          <ul className="rm-list">
            {keys.filter((k) => data.current?.[k]).map((k) => (
              <li key={k} className="rm-item">
                <span className="rm-item-label">{data.fields[k].label}{data.current?.renewed.includes(k) && <span className="rm-badge"><Icon name="renew" size={12} /> renouvelée</span>}</span>
                <p>{data.current?.[k]}</p>
              </li>
            ))}
          </ul>
          <p className="helper">Vous pouvez les ajuster jusqu'à la fin du mois. Le mois prochain, vous ferez le bilan.</p>
        </section>
      ) : (
        <section className="panel mt rm-form" aria-labelledby="rm-form-title">
          <div className="rm-step-head">
            <span className="rm-step-num">{data.review_needed ? 2 : 1}</span>
            <div>
              <h3 id="rm-form-title">Mes résolutions {de(data.period_label)}</h3>
              <p className="helper">
                {data.hints.fiss_period_label
                  ? `Laissez-vous guider par votre FISS ${de(data.hints.fiss_period_label)} : les points à fortifier sont signalés.`
                  : 'Écrivez une résolution simple et concrète pour chaque domaine qui vous parle.'}
              </p>
            </div>
          </div>

          {prevMonth && prevMonth.count > 0 && (
            <button type="button" className="rm-renew-all" onClick={renewAll}>
              <Icon name="renew" size={18} />
              <span><strong>Je renouvelle mes résolutions {de(prevMonth.period_label)}</strong><small>Reprendre les {prevMonth.count} résolution{prevMonth.count > 1 ? 's' : ''} du mois dernier, puis ajuster si besoin.</small></span>
            </button>
          )}

          {keys.map((k) => {
            const f = data.fields[k]
            const hint = data.hints.items[k]
            const before = prevMonth?.[k]
            const renewed = !!before && texts[k].trim() === before.trim()
            return (
              <div key={k} className={`rm-field ${texts[k].trim() ? 'filled' : ''}`}>
                <div className="rm-field-head">
                  <label htmlFor={`rm-${k}`}>{f.label}</label>
                  {hint && <span className={`rm-hint rm-hint-${hint.level}`} title={`Selon votre FISS : ${LEVEL_LABEL[hint.level]}`}>{k === 'assiduite' ? 'Présences' : 'FISS'} {hint.value} · {LEVEL_LABEL[hint.level]}</span>}
                </div>
                <p className="rm-question">{f.question}</p>
                <textarea id={`rm-${k}`} className="input" rows={2} maxLength={2000} placeholder={f.placeholder}
                  value={texts[k]} onChange={(e) => set(k, e.target.value)} />
                <div className="rm-field-foot">
                  {before && (renewed
                    ? <span className="rm-badge on"><Icon name="renew" size={12} /> Renouvelée</span>
                    : <button type="button" className="chip-toggle rm-renew" onClick={() => set(k, before)}><Icon name="renew" size={13} /> {data.renew_phrase}</button>)}
                  {f.theme && resourcesByTheme[f.theme] ? <a className="rm-res-link" href="#ressources" onClick={() => setResTheme(f.theme)}>Ressources ({resourcesByTheme[f.theme]})</a> : null}
                </div>
              </div>
            )
          })}

          <div className="rm-save">
            <span className="helper">{filledCount === 0 ? 'Écrivez au moins une résolution.' : `${filledCount} résolution${filledCount > 1 ? 's' : ''} · brouillon gardé sur ce téléphone`}</span>
            <div className="rm-save-actions">
              {data.current && <button className="btn btn-ghost" onClick={() => { setTexts(textsOf(keys, data.current)); if (draftKey) clearDraft(draftKey); setEditing(false) }}>Annuler</button>}
              <button className="btn btn-primary" disabled={busy || filledCount === 0 || unchanged} onClick={save}>
                {busy ? <span className="spinner" /> : data.current ? 'Enregistrer' : 'Enregistrer mes RM'}
              </button>
            </div>
          </div>
        </section>
      )}


      {data.history.length > 0 && (
        <section className="panel mt">
          <div className="panel-head"><h3>Mes mois précédents</h3></div>
          <div className="fiss-admin-list">
            {data.history.map((h) => <HistoryItem key={h.id} r={h} data={data} />)}
          </div>
        </section>
      )}
    </AppLayout>
  )
}

function HistoryItem({ r, data }: { r: MonthlyResolution; data: ResolutionsData }) {
  const keys = (Object.keys(data.fields) as ResolutionKey[]).filter((k) => r[k])
  return (
    <details className="fiss-admin-item">
      <summary>
        <span className="fiss-hist-period">{r.period_label}</span>
        <span className="fiss-hist-scores">{r.count} résolution{r.count > 1 ? 's' : ''}{r.outcome ? ` · ${r.achieved} atteinte${r.achieved > 1 ? 's' : ''}` : ''}</span>
      </summary>
      <ul className="rm-list compact">
        {keys.map((k) => (
          <li key={k} className="rm-item">
            <span className="rm-item-label">
              {data.fields[k].label}
              {r.outcome?.[k] && <span className={`rm-outcome-tag rm-outcome-${r.outcome[k]}`}>{data.outcomes[r.outcome[k]!]}</span>}
            </span>
            <p>{r[k]}</p>
          </li>
        ))}
      </ul>
    </details>
  )
}

/** « 2026-10 » -> « 2026-09 » */
function monthBefore(period: string): string {
  const [y, m] = period.split('-').map(Number)
  const d = new Date(y, m - 2, 1)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
}
