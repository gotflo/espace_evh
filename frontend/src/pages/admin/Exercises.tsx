import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { AudiencePicker } from '../../components/AudiencePicker'
import type { AudienceScope, ExerciseListItem, ExerciseStatus, ExerciseTracking } from '../../types'
import { deadlineLabel } from '../../utils/exercises'

const WRITTEN_TYPES = [
  { key: 'reflexion', label: 'Réflexion' },
  { key: 'verset', label: 'Verset à méditer' },
  { key: 'lecture', label: 'Lecture' },
  { key: 'quiz', label: 'Quiz' },
]

interface Preview { video_id: string; thumbnail: string; title: string | null; author: string | null; embeddable: boolean }

const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const duration = (s: number) => (s >= 60 ? `${Math.floor(s / 60)} min${s % 60 ? ` ${s % 60} s` : ''}` : `${s} s`)
const STATUS_LABEL: Record<ExerciseStatus, string> = { done: 'Terminé', in_progress: 'En cours', todo: 'Pas commencé' }

// ---------------------------------------------------------------- Suivi d'un exercice

function Tracking({ id, onBack }: { id: number; onBack: () => void }) {
  const [data, setData] = useState<ExerciseTracking | null>(null)
  const [filter, setFilter] = useState<'all' | ExerciseStatus | 'skipped'>('all')
  const [q, setQ] = useState('')
  const [open, setOpen] = useState<number | null>(null)

  useEffect(() => {
    api<ExerciseTracking>(`/admin/exercises/${id}/tracking`).then(setData).catch(() => onBack())
  }, [id, onBack])

  const rows = useMemo(() => {
    const s = q.trim().toLowerCase()
    return (data?.members ?? []).filter((m) => (filter === 'all' || (filter === 'skipped' ? m.seek_count > 0 : m.status === filter))
      && (!s || m.name.toLowerCase().includes(s)))
  }, [data, filter, q])

  const back = <button className="btn btn-ghost small" onClick={onBack}>← Exercices</button>
  if (!data) return <AppLayout title="Suivi" actions={back}><div className="skeleton skeleton-card" /></AppLayout>

  const ex = data.exercise
  const sm = data.summary
  const pct = sm.total ? Math.round((sm.done / sm.total) * 100) : 0
  const chips: { key: typeof filter; label: string; n: number }[] = [
    { key: 'all', label: 'Tous', n: sm.total },
    { key: 'todo', label: 'Pas commencé', n: sm.todo },
    { key: 'in_progress', label: 'En cours', n: sm.in_progress },
    { key: 'done', label: 'Terminé', n: sm.done },
    ...(ex.video ? [{ key: 'skipped' as const, label: 'A avancé la vidéo', n: sm.skipped }] : []),
  ]

  return (
    <AppLayout title="Suivi de l'exercice" subtitle={ex.title} actions={back}>
      <section className="panel tracking-head">
        {ex.video && <a href={ex.video.url} target="_blank" rel="noreferrer noopener"><img className="tracking-thumb" src={ex.video.thumbnail} alt="Voir la vidéo sur YouTube" /></a>}
        <div className="tracking-info">
          <strong>{ex.title}</strong>
          <span className="helper">{ex.type_label} · {ex.target}{ex.requires_response ? ' · réponse écrite demandée' : ''}</span>
          <span className="helper">{deadlineLabel(ex.closes_at, ex.is_closed) ?? 'Sans date limite'}</span>
          <div className="completion-bar mt-sm" aria-label={`${pct} % ont terminé`}><span className="good" style={{ width: `${pct}%` }} /></div>
          <span className="helper"><strong>{sm.done}</strong> sur {sm.total} ont terminé ({pct} %)</span>
        </div>
      </section>

      <div className="members-filters mt">
        <div className="filter-chips" role="radiogroup" aria-label="Filtre">
          {chips.map((c) => (
            <button key={c.key} role="radio" aria-checked={filter === c.key} className={`chip-toggle ${filter === c.key ? 'on' : ''}`} onClick={() => setFilter(c.key)}>
              {c.label} <span className="count-soft">{c.n}</span>
            </button>
          ))}
        </div>
        <input className="input" type="search" placeholder="Rechercher un fidèle…" value={q} onChange={(e) => setQ(e.target.value)} />
      </div>

      {rows.length === 0 ? <p className="helper">Personne dans cette catégorie.</p> : (
        <ul className="tracking-list">
          {rows.map((m) => (
            <li key={m.user_id} className="tracking-row">
              <button className="tracking-line" onClick={() => setOpen(open === m.user_id ? null : m.user_id)} aria-expanded={open === m.user_id}>
                <span className="tracking-name">
                  <strong>{m.name}</strong>
                  <small>{[m.tribe, m.active ? null : 'inactif'].filter(Boolean).join(' · ')}</small>
                </span>
                {ex.video && (
                  <span className="tracking-watch">
                    <span className="mini-progress"><span style={{ width: `${m.percent}%` }} className={m.percent >= 100 ? 'done' : ''} /></span>
                    <small>{m.percent} % vu</small>
                  </span>
                )}
                <span className={`exercise-badge ${m.status}`}>{STATUS_LABEL[m.status]}</span>
              </button>
              {m.seek_count > 0 && (
                <p className="tracking-flag">⏩ A avancé la vidéo {m.seek_count} fois ({duration(m.skipped_seconds)} sautées){m.percent >= 100 ? ', puis a tout regardé' : ''}.</p>
              )}
              {open === m.user_id && (
                <div className="tracking-more">
                  {m.max_rate && m.max_rate > 1 && <p className="helper">Vitesse de lecture maximale : ×{m.max_rate}</p>}
                  {m.last_activity && <p className="helper">Dernière activité : {new Date(m.last_activity).toLocaleString('fr-CA', { dateStyle: 'medium', timeStyle: 'short' })}</p>}
                  {ex.requires_response && (m.response
                    ? <blockquote className="validation-reason prewrap">{m.response}</blockquote>
                    : <p className="helper">Pas encore de réponse écrite.</p>)}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
    </AppLayout>
  )
}

// ---------------------------------------------------------------- Formulaire de creation

function NewExercise({ onDone, onCancel }: { onDone: () => void; onCancel: () => void }) {
  const inWeek = new Date(Date.now() + 7 * 86400000)
  const [kind, setKind] = useState<'video' | 'written'>('video')
  const [url, setUrl] = useState('')
  const [preview, setPreview] = useState<Preview | null>(null)
  const [previewError, setPreviewError] = useState('')
  const [checking, setChecking] = useState(false)
  const [title, setTitle] = useState('')
  const [content, setContent] = useState('')
  const [writtenType, setWrittenType] = useState('reflexion')
  const [requiresResponse, setRequiresResponse] = useState(false)
  const [date, setDate] = useState(ymd(inWeek))
  const [time, setTime] = useState('21:00')
  const [scopes, setScopes] = useState<AudienceScope[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const titleTouched = useRef(false)

  // Apercu automatique du lien (titre, miniature, lecture possible dans l'application).
  useEffect(() => {
    if (kind !== 'video' || !url.trim()) { setPreview(null); setPreviewError(''); return }
    const t = window.setTimeout(() => {
      setChecking(true)
      api<Preview>('/admin/exercises/video-preview', { method: 'POST', body: { url }, toast: false })
        .then((p) => {
          setPreview(p); setPreviewError('')
          if (p.title && !titleTouched.current) setTitle(p.title.slice(0, 150))
        })
        .catch((err) => { setPreview(null); setPreviewError(err instanceof ApiError ? err.firstMessage : 'Lien impossible à vérifier.') })
        .finally(() => setChecking(false))
    }, 500)
    return () => window.clearTimeout(t)
  }, [url, kind])

  const closesAt = date ? `${date}T${time || '23:59'}` : null
  const pastDeadline = closesAt !== null && new Date(closesAt).getTime() <= Date.now()
  const valid = title.trim() && scopes.length > 0 && !pastDeadline
    && (kind === 'video' ? preview !== null : content.trim().length > 0)

  async function publish() {
    setError(''); setBusy(true)
    try {
      await api('/admin/exercises', {
        method: 'POST',
        body: {
          title: title.trim(), content: content.trim() || null, scopes,
          type: kind === 'video' ? 'video' : writtenType,
          video_url: kind === 'video' ? url.trim() : null,
          requires_response: kind === 'video' ? requiresResponse : true,
          closes_at: closesAt,
        },
      })
      onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  return (
    <section className="panel new-exercise">
      <div className="panel-head">
        <h3>Nouvel exercice</h3>
        <button className="btn-link" onClick={onCancel}>Annuler</button>
      </div>
      {error && <div className="alert alert-error">{error}</div>}

      <div className="seg" role="radiogroup" aria-label="Type d'exercice">
        <button role="radio" aria-checked={kind === 'video'} className={kind === 'video' ? 'on' : ''} onClick={() => setKind('video')}>🎬 Vidéo YouTube</button>
        <button role="radio" aria-checked={kind === 'written'} className={kind === 'written' ? 'on' : ''} onClick={() => setKind('written')}>📖 Exercice écrit</button>
      </div>

      {kind === 'video' && (
        <div className="field">
          <label htmlFor="yt-url">Lien de la vidéo YouTube</label>
          <input id="yt-url" className="input" inputMode="url" placeholder="https://www.youtube.com/watch?v=…" value={url} onChange={(e) => setUrl(e.target.value)} />
          {checking && <p className="helper">Vérification du lien…</p>}
          {previewError && <p className="helper editor-error">{previewError}</p>}
          {preview && (
            <div className="yt-preview">
              <img src={preview.thumbnail} alt="" />
              <div>
                <strong>{preview.title ?? 'Vidéo YouTube'}</strong>
                {preview.author && <small>{preview.author}</small>}
                {preview.embeddable
                  ? <small className="ok">✓ Lisible dans l'application</small>
                  : <small className="warn">⚠ L'auteur interdit peut-être la lecture hors de YouTube, ou la vidéo est privée : vérifiez qu'elle se lance.</small>}
              </div>
            </div>
          )}
        </div>
      )}

      <div className="field">
        <label htmlFor="ex-title">Titre</label>
        <input id="ex-title" className="input" maxLength={150} value={title}
          onChange={(e) => { titleTouched.current = true; setTitle(e.target.value) }}
          placeholder={kind === 'video' ? 'Ex. Enseignement : la foi' : 'Ex. Méditer Jean 3:16'} />
      </div>

      {kind === 'written' && (
        <div className="field">
          <label>Type</label>
          <select className="select" value={writtenType} onChange={(e) => setWrittenType(e.target.value)}>
            {WRITTEN_TYPES.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
          </select>
        </div>
      )}

      <div className="field">
        <label htmlFor="ex-content">{kind === 'video' ? 'Consignes (facultatif)' : 'Consigne'}</label>
        <textarea id="ex-content" className="input" rows={4} maxLength={5000} value={content} onChange={(e) => setContent(e.target.value)}
          placeholder={kind === 'video' ? 'Ex. Notez les trois points clés du message et un verset qui vous a touché.' : ''} />
      </div>

      {kind === 'video' && (
        <label className="switch-line mt">
          <input type="checkbox" checked={requiresResponse} onChange={(e) => setRequiresResponse(e.target.checked)} />
          <span>Demander une réponse écrite après la vidéo (sinon, il suffit de la regarder en entier)</span>
        </label>
      )}

      <div className="field mt">
        <label>Date limite</label>
        <div className="field-row">
          <input className="input" type="date" min={ymd(new Date())} value={date} onChange={(e) => setDate(e.target.value)} aria-label="Date limite" />
          <input className="input" type="time" step={300} value={time} onChange={(e) => setTime(e.target.value)} disabled={!date} aria-label="Heure limite" />
        </div>
        <p className="helper">
          {date ? "À cette heure, l'exercice se ferme automatiquement : plus de visionnage ni de réponse. Des rappels partent la veille et 3 h avant." : 'Sans date limite : l’exercice reste ouvert.'}
          {date && <> <button type="button" className="btn-link" onClick={() => setDate('')}>Sans date limite</button></>}
        </p>
        {pastDeadline && <p className="helper editor-error">La date limite doit être dans le futur.</p>}
      </div>

      <AudiencePicker value={scopes} onChange={setScopes} />

      <button className="btn btn-primary mt" disabled={busy || !valid} onClick={publish}>
        {busy ? <span className="spinner" /> : "Publier et prévenir les fidèles"}
      </button>
    </section>
  )
}

// ---------------------------------------------------------------- Liste

export default function Exercises() {
  const [params, setParams] = useSearchParams()
  const [exercises, setExercises] = useState<ExerciseListItem[] | null>(null)
  const [creating, setCreating] = useState(false)
  const tracking = Number(params.get('suivi')) || null

  const load = useCallback(() => {
    api<{ exercises: ExerciseListItem[] }>('/admin/exercises').then((r) => setExercises(r.exercises)).catch(() => setExercises([]))
  }, [])
  useEffect(() => { load() }, [load])

  const openTracking = (id: number | null) => {
    const next = new URLSearchParams(params)
    if (id) next.set('suivi', String(id)); else next.delete('suivi')
    setParams(next)
  }
  const closeTracking = useCallback(() => { setParams((p) => { const n = new URLSearchParams(p); n.delete('suivi'); return n }) }, [setParams])

  async function remove(ex: ExerciseListItem) {
    if (!confirm(`Supprimer « ${ex.title} » et tout son suivi ?`)) return
    try { await api(`/admin/exercises/${ex.id}`, { method: 'DELETE' }); load() } catch { /* toast */ }
  }

  if (tracking) return <Tracking id={tracking} onBack={closeTracking} />

  return (
    <AppLayout title="Exercices" subtitle="Vidéos et exercices pour les fidèles, avec leur suivi"
      actions={!creating ? <button className="btn btn-primary small" onClick={() => setCreating(true)}>+ Nouvel exercice</button> : undefined}>
      {creating ? (
        <NewExercise onCancel={() => setCreating(false)} onDone={() => { setCreating(false); load() }} />
      ) : exercises === null ? (
        <div className="exercise-rows">{[0, 1, 2].map((i) => <div key={i} className="skeleton skeleton-row" />)}</div>
      ) : exercises.length === 0 ? (
        <div className="empty-state"><span aria-hidden>🎬</span><h3>Aucun exercice</h3><p>Publiez une vidéo YouTube ou un exercice écrit pour vos fidèles.</p></div>
      ) : (
        <div className="admin-exercises">
          {exercises.map((e) => (
            <article key={e.id} className={`admin-exercise ${e.is_closed ? 'is-closed' : ''}`}>
              {e.video ? <img className="exercise-thumb" src={e.video.thumbnail} alt="" loading="lazy" /> : <span className="exercise-thumb exercise-thumb-icon" aria-hidden>📖</span>}
              <div className="admin-exercise-main">
                <strong>{e.title}</strong>
                <small>{e.type_label} · {e.target}</small>
                <small className={e.is_closed ? 'closed' : ''}>{deadlineLabel(e.closes_at, e.is_closed) ?? 'Sans date limite'}</small>
                <small>
                  {e.video ? `${e.views_completed_count} vue(s) complète(s) · ${e.views_count} ont commencé` : ''}
                  {e.video && e.requires_response ? ' · ' : ''}
                  {e.requires_response ? `${e.responses_count} réponse(s)` : ''}
                </small>
              </div>
              <div className="admin-exercise-actions">
                <button className="btn btn-ghost small" onClick={() => openTracking(e.id)}>Suivi</button>
                <button className="org-del" onClick={() => remove(e)} aria-label={`Supprimer ${e.title}`}>×</button>
              </div>
            </article>
          ))}
        </div>
      )}
    </AppLayout>
  )
}
