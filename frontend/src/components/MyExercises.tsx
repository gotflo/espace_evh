import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import type { MyExercise } from '../types'

function ExerciseCard({ ex, canRespond, onDone }: { ex: MyExercise; canRespond: boolean; onDone: () => void }) {
  const [text, setText] = useState(ex.my_response ?? '')
  const [editing, setEditing] = useState(!ex.completed)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  async function submit() {
    setError(''); setBusy(true)
    try {
      await api(`/me/exercises/${ex.id}/respond`, { method: 'POST', body: { response: text } })
      setEditing(false); onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  return (
    <div className="exercise-card">
      <div className="exercise-head">
        <span className="exercise-title">{ex.title}</span>
        {ex.completed && !editing && <span className="badge badge-gold">✓ Repondu</span>}
      </div>
      <p className="exercise-content">{ex.content}</p>
      {ex.due_date && <p className="helper">Echeance : {ex.due_date}</p>}

      {error && <div className="alert alert-error">{error}</div>}

      {canRespond && (editing ? (
        <>
          <textarea className="input mt" rows={3} placeholder="Votre réponse..." value={text} onChange={(e) => setText(e.target.value)} />
          <button className="btn btn-primary small mt" disabled={busy || !text.trim()} onClick={submit}>
            {busy ? <span className="spinner" /> : 'Envoyer ma réponse'}
          </button>
        </>
      ) : (
        <div className="exercise-answer">
          <p className="exercise-mine">{ex.my_response}</p>
          <button className="btn-link" onClick={() => setEditing(true)}>Modifier</button>
        </div>
      ))}
    </div>
  )
}

export function MyExercises() {
  const { hasPermission } = useAuth()
  const canRespond = hasPermission('exercises.respond')
  const [exercises, setExercises] = useState<MyExercise[]>([])

  const load = useCallback(() => {
    api<{ exercises: MyExercise[] }>('/me/exercises').then((r) => setExercises(r.exercises)).catch(() => setExercises([]))
  }, [])
  useEffect(() => { load() }, [load])

  if (exercises.length === 0) return null

  return (
    <section className="panel mt" id="exercices">
      <div className="panel-head"><h3>Mes exercices</h3></div>
      <div className="exercise-list">
        {exercises.map((ex) => <ExerciseCard key={ex.id} ex={ex} canRespond={canRespond} onDone={load} />)}
      </div>
    </section>
  )
}
