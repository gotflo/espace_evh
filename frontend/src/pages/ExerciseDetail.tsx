import { useCallback, useEffect, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { YouTubePlayer, type WatchResult } from '../components/YouTubePlayer'
import { useAuth } from '../auth/AuthContext'
import type { MyExercise } from '../types'
import { deadlineLabel } from '../utils/exercises'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'

export default function ExerciseDetail() {
  const { id } = useParams()
  const { hasPermission } = useAuth()
  const [ex, setEx] = useState<MyExercise | null>(null)
  const [missing, setMissing] = useState(false)
  const [text, setText] = useState('')
  const [editing, setEditing] = useState(true)
  const [busy, setBusy] = useState(false)
  const [resumeAt, setResumeAt] = useState<number | null>(null)
  useDraft(ex && editing && !ex.is_closed ? `exercise:${id}` : null, text, (v) => !v.trim() || v === ex?.my_response)

  const load = useCallback(() => {
    api<{ exercise: MyExercise }>(`/me/exercises/${id}`).then((r) => {
      setEx(r.exercise)
      setText(r.exercise.my_response ?? readDraft<string>(`exercise:${id}`) ?? '')
      setEditing(!r.exercise.my_response)
      setResumeAt((prev) => prev ?? r.exercise.resume_at ?? 0)
    }).catch(() => setMissing(true))
  }, [id])
  useEffect(() => { load() }, [load])

  function onProgress(r: WatchResult) {
    setEx((prev) => prev ? { ...prev, percent: r.percent, video_completed: r.video_completed, status: r.status as MyExercise['status'], completed: r.status === 'done' } : prev)
  }

  async function submit() {
    setBusy(true)
    try {
      await api(`/me/exercises/${id}/respond`, { method: 'POST', body: { response: text } })
      clearDraft(`exercise:${id}`)
      load()
    } catch (err) {
      if (err instanceof ApiError && err.status === 422) load()
    } finally { setBusy(false) }
  }

  const back = <Link className="btn btn-ghost small" to="/exercices">← Mes exercices</Link>

  if (missing) {
    return (
      <AppLayout title="Exercice" actions={back}>
        <div className="empty-state"><span aria-hidden>🔒</span><h3>Exercice introuvable</h3><p>Il a peut-être été supprimé ou ne vous est pas adressé.</p></div>
      </AppLayout>
    )
  }
  if (!ex || resumeAt === null) return <AppLayout title="Exercice" actions={back}><div className="skeleton skeleton-card" /></AppLayout>

  const deadline = deadlineLabel(ex.closes_at, ex.is_closed)
  const canRespond = hasPermission('exercises.respond') && ex.requires_response && !ex.is_closed

  return (
    <AppLayout title={ex.title} subtitle={deadline ?? 'Sans date limite'} actions={back}>
      <div className="exercise-detail">
        <div className={`exercise-state state-${ex.is_closed && !ex.completed ? 'closed' : ex.status}`}>
          {ex.completed ? '✅ Exercice terminé' : ex.is_closed ? '🔒 Exercice fermé' : ex.status === 'in_progress' ? '⏳ En cours' : '📌 À faire'}
          {deadline && <span>{deadline}</span>}
        </div>

        {ex.video && (
          <section className="panel">
            <YouTubePlayer exerciseId={ex.id} videoId={ex.video.id} resumeAt={resumeAt} track={!ex.is_closed && !ex.video_completed} onProgress={onProgress} />
            <div className="watch-progress" aria-live="polite">
              <div className="watch-bar" role="progressbar" aria-valuenow={ex.percent} aria-valuemin={0} aria-valuemax={100} aria-label="Vidéo regardée">
                <span style={{ width: `${ex.percent}%` }} className={ex.video_completed ? 'done' : ''} />
              </div>
              <p className="helper">
                {ex.video_completed
                  ? 'Vidéo regardée en entier. Merci !'
                  : `Vous avez regardé ${ex.percent} % de la vidéo. Pour la valider, regardez-la en entier : les passages avancés rapidement ne comptent pas.`}
              </p>
              {!ex.video_completed && resumeAt > 5 && <p className="helper">▶ La lecture reprend là où vous vous étiez arrêté.</p>}
            </div>
          </section>
        )}

        {ex.content && (
          <section className="panel mt">
            <div className="panel-head"><h3>{ex.video ? 'Consignes' : 'Exercice'}</h3></div>
            <p className="exercise-content prewrap">{ex.content}</p>
          </section>
        )}

        {ex.requires_response && (
          <section className="panel mt">
            <div className="panel-head"><h3>Ma réponse</h3></div>
            {canRespond && editing ? (
              <>
                <textarea className="input" rows={5} maxLength={5000} placeholder="Votre réponse…" value={text} onChange={(e) => setText(e.target.value)} />
                <div className="row-actions mt-sm">
                  {ex.my_response && <button className="btn btn-ghost small" onClick={() => { setText(ex.my_response ?? ''); setEditing(false) }}>Annuler</button>}
                  <button className="btn btn-primary small" disabled={busy || !text.trim()} onClick={submit}>{busy ? <span className="spinner" /> : 'Envoyer ma réponse'}</button>
                </div>
              </>
            ) : ex.my_response ? (
              <div className="exercise-answer">
                <p className="exercise-mine prewrap">{ex.my_response}</p>
                {canRespond && <button className="btn-link" onClick={() => setEditing(true)}>Modifier</button>}
              </div>
            ) : (
              <p className="helper">{ex.is_closed ? "L'exercice est fermé : il n'est plus possible de répondre." : 'Vous ne pouvez pas répondre à cet exercice.'}</p>
            )}
          </section>
        )}
      </div>
    </AppLayout>
  )
}
