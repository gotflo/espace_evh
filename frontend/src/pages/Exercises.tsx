import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import type { MyExercise } from '../types'
import { ExerciseRow } from '../components/ExerciseRow'

export default function Exercises() {
  const [items, setItems] = useState<MyExercise[] | null>(null)
  useEffect(() => { api<{ exercises: MyExercise[] }>('/me/exercises').then((r) => setItems(r.exercises)).catch(() => setItems([])) }, [])

  const open = (items ?? []).filter((e) => !e.is_closed && !e.completed)
  const done = (items ?? []).filter((e) => e.completed)
  const closed = (items ?? []).filter((e) => e.is_closed && !e.completed)

  return (
    <AppLayout title="Mes exercices" subtitle="Vidéos, lectures et méditations proposées par vos responsables">
      {items === null ? (
        <div className="exercise-rows">{[0, 1, 2].map((i) => <div key={i} className="skeleton skeleton-row" />)}</div>
      ) : items.length === 0 ? (
        <div className="empty-state"><span aria-hidden>📚</span><h3>Aucun exercice pour le moment</h3><p>Vos responsables vous en proposeront au fil de votre parcours.</p></div>
      ) : (
        <>
          <h3 className="section-label">À faire ({open.length})</h3>
          {open.length === 0 ? <p className="helper">Tout est fait. Bravo ! 🎉</p> : <div className="exercise-rows">{open.map((e) => <ExerciseRow key={e.id} ex={e} />)}</div>}
          {done.length > 0 && (<><h3 className="section-label mt">Terminés ({done.length})</h3><div className="exercise-rows">{done.map((e) => <ExerciseRow key={e.id} ex={e} />)}</div></>)}
          {closed.length > 0 && (<><h3 className="section-label mt">Fermés sans être terminés ({closed.length})</h3><div className="exercise-rows">{closed.map((e) => <ExerciseRow key={e.id} ex={e} />)}</div></>)}
        </>
      )}
    </AppLayout>
  )
}
