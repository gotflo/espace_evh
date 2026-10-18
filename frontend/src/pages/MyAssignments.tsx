import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import { Icon } from '../components/Icon'
import { MediaPlayer } from '../components/MediaPlayer'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import type { WatchResult } from '../utils/playback'
import { usePulse } from '../pulse'
import type { Assignment, MyAssignmentsData } from '../types'
import { scrollPageTo } from '../utils/pageScroll'

const fmt = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }) : '')

interface Draft { summary: string; actions: string }

const STATUS_LABEL = { done: 'Fait', in_progress: 'En cours', todo: 'À faire' } as const

/** Lundi 0 h de la semaine en cours (les devoirs sont donnes chaque semaine). */
function weekStart(): Date {
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  d.setDate(d.getDate() - ((d.getDay() + 6) % 7))
  return d
}

/**
 * Le devoir choisi : media (lecture mesuree comme les exercices video), resume, actions et
 * resolutions, rappel de prier. « Fait » = resume envoye ET video ou audio lu en entier.
 */
function AssignmentWork({ a, reminder, onSaved }: { a: Assignment; reminder: string; onSaved: () => void }) {
  const key = `ds:${a.id}`
  const [editing, setEditing] = useState(!a.response)
  const [form, setForm] = useState<Draft>(() => readDraft<Draft>(key) ?? { summary: a.response?.summary ?? '', actions: a.response?.actions ?? '' })
  const [busy, setBusy] = useState(false)
  const [justSaved, setJustSaved] = useState(false)
  // Avancement de la lecture, mis a jour en direct par le lecteur.
  const [watch, setWatch] = useState({ percent: a.percent ?? 0, completed: !!a.media_completed })
  // Position de reprise figee au premier affichage (sinon le lecteur se rechargerait a chaque signal).
  const [resumeAt] = useState(a.resume_at ?? 0)
  useDraft(editing ? key : null, form, (v) => !v.summary.trim() && !v.actions.trim())

  const ready = form.summary.trim().length >= 10 && form.actions.trim().length >= 5
  const unchanged = !!a.response && form.summary.trim() === a.response.summary && form.actions.trim() === a.response.actions
  const listen = a.media_type === 'audio' ? 'écoute' : 'vidéo'
  const mediaDone = !a.trackable || watch.completed
  const done = !!a.response && mediaDone

  function onProgress(r: WatchResult) {
    setWatch({ percent: r.percent, completed: r.video_completed })
    if (r.video_completed && !watch.completed && a.response) onSaved()
  }

  async function save() {
    setBusy(true)
    try {
      await api(`/me/assignments/${a.id}/response`, { method: 'PUT', body: { summary: form.summary.trim(), actions: form.actions.trim() } })
      clearDraft(key)
      setEditing(false)
      setJustSaved(true)
      onSaved()
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  return (
    <article className="panel ds-card">
      <header className="ds-head">
        <span className={`ds-type ds-type-${a.media_type}`}><Icon name={a.media_type === 'audio' ? 'headphones' : 'play'} size={14} /> {a.media_type === 'audio' ? 'Audio' : 'Vidéo'}</span>
        <h2>{a.title}</h2>
        <p className="ds-meta">{a.speaker ? `${a.speaker} · ` : ''}publié le {fmt(a.published_at)}</p>
      </header>

      <div>
        <MediaPlayer media={a.media} title={a.title} target={a.trackable ? { kind: 'assignment', id: a.id } : undefined}
          resumeAt={resumeAt} track={!!a.trackable && !watch.completed} onProgress={onProgress} />
        {a.trackable && (
          <div className="watch-progress" aria-live="polite">
            <div className="watch-bar" role="progressbar" aria-valuenow={watch.percent} aria-valuemin={0} aria-valuemax={100}
              aria-label={a.media_type === 'audio' ? 'Audio écouté' : 'Vidéo regardée'}>
              <span style={{ width: `${watch.percent}%` }} className={watch.completed ? 'done' : ''} />
            </div>
            <p className="helper">
              {watch.completed
                ? (a.media_type === 'audio' ? 'Audio écouté en entier. Merci !' : 'Vidéo regardée en entier. Merci !')
                : `${a.media_type === 'audio' ? 'Écouté' : 'Regardé'} à ${watch.percent} %. Pour valider le devoir, allez jusqu'au bout : les passages avancés rapidement ne comptent pas.`}
            </p>
            {!watch.completed && resumeAt > 5 && <p className="helper">▶ La lecture reprend là où vous vous étiez arrêté.</p>}
          </div>
        )}
      </div>
      {a.instructions && <p className="ds-instructions">{a.instructions}</p>}

      {!editing && a.response ? (
        <div className="ds-done">
          <div className="ds-done-head">
            {done
              ? <span className="ds-done-badge"><Icon name="check" size={15} /> Fait le {fmt(a.response.submitted_at)}</span>
              : <span className="ds-done-badge pending"><Icon name="clock" size={15} /> Résumé envoyé · {listen} à terminer</span>}
            <button className="btn btn-ghost small" onClick={() => setEditing(true)}><Icon name="edit" size={15} /> Modifier</button>
          </div>
          <div className="ds-answer"><span>Résumé</span><p>{a.response.summary}</p></div>
          <div className="ds-answer"><span>Actions et résolutions</span><p>{a.response.actions}</p></div>
          {justSaved && done && (
            <div className="ds-prayer now" role="status">
              <Icon name="spiritual" size={20} />
              <div>
                <strong>Prenons un moment pour prier</strong>
                <p>{reminder}</p>
              </div>
            </div>
          )}
        </div>
      ) : (
        <div className="ds-form">
          <ol className="ds-steps" aria-label="Étapes du devoir">
            <li className={mediaDone ? 'ok' : ''}><span>{mediaDone ? '✓' : 1}</span>{a.media_type === 'audio' ? 'Écoutez' : 'Regardez'} le message</li>
            <li><span>2</span>Résumez-le</li>
            <li><span>3</span>Notez vos actions</li>
            <li><span>4</span>Priez</li>
          </ol>
          <div className="field">
            <label htmlFor={`ds-sum-${a.id}`}>Résumé</label>
            <textarea id={`ds-sum-${a.id}`} className="input" rows={5} maxLength={5000} placeholder="Ce que j'ai retenu du message, en quelques phrases…"
              value={form.summary} onChange={(e) => setForm((f) => ({ ...f, summary: e.target.value }))} />
          </div>
          <div className="field">
            <label htmlFor={`ds-act-${a.id}`}>Actions et résolutions</label>
            <textarea id={`ds-act-${a.id}`} className="input" rows={3} maxLength={3000} placeholder="Ce que je vais mettre en pratique cette semaine…"
              value={form.actions} onChange={(e) => setForm((f) => ({ ...f, actions: e.target.value }))} />
          </div>
          <div className="ds-prayer">
            <Icon name="spiritual" size={20} />
            <p>{reminder}</p>
          </div>
          <div className="ds-actions">
            {a.response && <button className="btn btn-ghost" onClick={() => { setForm({ summary: a.response!.summary, actions: a.response!.actions }); clearDraft(key); setEditing(false) }}>Annuler</button>}
            <button className="btn btn-primary" disabled={busy || !ready || unchanged} onClick={save}>
              {busy ? <span className="spinner" /> : a.response ? 'Enregistrer les modifications' : 'Enregistrer mon devoir'}
            </button>
          </div>
          {!ready && <p className="helper center">Un résumé de quelques phrases et au moins une action suffisent.</p>}
          {ready && !mediaDone && <p className="helper center">Vous pouvez déjà enregistrer ; le devoir sera fait quand la {listen} sera terminée.</p>}
        </div>
      )}
    </article>
  )
}

export default function MyAssignments() {
  const [data, setData] = useState<MyAssignmentsData | null>(() => peek<MyAssignmentsData>('/me/assignments') ?? null)
  const [params, setParams] = useSearchParams()
  const load = useCallback(() => { api<MyAssignmentsData>('/me/assignments').then(setData).catch(() => {}) }, [])
  useEffect(() => { load() }, [load])
  usePulse('assignments', load)

  const wanted = Number(params.get('d'))
  const current = data?.assignments.find((a) => a.id === wanted) ?? data?.assignments.find((a) => !a.done) ?? data?.assignments[0]

  // Le devoir affiche est inscrit dans l'adresse : la notification qui l'annonce est marquee lue
  // (lecture automatique des notifications, comme pour les autres ecrans).
  useEffect(() => {
    if (current && wanted !== current.id) setParams({ d: String(current.id) }, { replace: true })
  }, [current, wanted, setParams])

  if (!data) return <AppLayout title="Mon devoir"><div className="panel-grid"><SkeletonCard /></div></AppLayout>

  const others = data.assignments.filter((a) => a.id !== current?.id)
  const monday = weekStart()
  const thisWeek = others.filter((a) => a.published_at && new Date(a.published_at) >= monday)
  const earlier = others.filter((a) => !thisWeek.includes(a))
  const weekCount = data.assignments.filter((a) => a.published_at && new Date(a.published_at) >= monday).length

  function pick(a: Assignment) {
    setParams({ d: String(a.id) }, { replace: true })
    scrollPageTo(0, true)
  }

  return (
    <AppLayout title="Mon devoir" subtitle={weekCount > 0 ? `Cette semaine : ${weekCount} devoir${weekCount > 1 ? 's' : ''}${data.pending > 0 ? ` · ${data.pending} à faire` : ''}` : data.pending > 0 ? `${data.pending} devoir${data.pending > 1 ? 's' : ''} à faire` : 'Devoir spirituel (DS) de la semaine'}>
      {!current ? (
        <section className="panel empty-state">
          <span><Icon name="devoir" size={34} /></span>
          <h3>Aucun devoir pour le moment</h3>
          <p>Vous serez prévenu dès que le pasteur en publiera un.</p>
        </section>
      ) : (
        <AssignmentWork key={current.id} a={current} reminder={data.prayer_reminder} onSaved={load} />
      )}

      {[['Aussi cette semaine', thisWeek], ['Semaines précédentes', earlier]].map(([label, list]) => (list as Assignment[]).length > 0 && (
        <section key={label as string} className="panel mt">
          <div className="panel-head"><h3>{label as string}</h3></div>
          <ul className="ds-list">
            {(list as Assignment[]).map((a) => {
              const st = a.status ?? (a.response ? 'done' : 'todo')
              return (
                <li key={a.id}>
                  <button className="ds-row" onClick={() => pick(a)}>
                    <span className={`ds-row-icon ${st === 'done' ? 'done' : ''}`}>{st === 'done' ? <Icon name="check" size={16} /> : <Icon name={a.media_type === 'audio' ? 'headphones' : 'play'} size={16} />}</span>
                    <span className="ds-row-text">
                      <strong>{a.title}</strong>
                      <small>{a.speaker ? `${a.speaker} · ` : ''}{fmt(a.published_at)}{st === 'in_progress' && a.percent != null ? ` · ${a.percent} %` : ''}</small>
                    </span>
                    <span className={`ds-status ${st}`}>{STATUS_LABEL[st]}</span>
                  </button>
                </li>
              )
            })}
          </ul>
        </section>
      ))}
    </AppLayout>
  )
}
