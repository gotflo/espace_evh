import { useCallback, useEffect, useState } from 'react'
import { api, peek } from '../api/client'
import { usePulse } from '../pulse'
import { AppLayout } from '../components/AppLayout'
import type { MyExercise } from '../types'
import { ExerciseRow } from '../components/ExerciseRow'
import { Icon } from '../components/Icon'

export default function Exercises() {
  const [items, setItems] = useState<MyExercise[] | null>(() => peek<{ exercises: MyExercise[] }>('/me/exercises')?.exercises ?? null)
  const load = useCallback(() => { api<{ exercises: MyExercise[] }>('/me/exercises').then((r) => setItems(r.exercises)).catch(() => setItems((p) => p ?? [])) }, [])
  useEffect(() => { load() }, [load])
  usePulse('exercises', load)

  const open = (items ?? []).filter((e) => !e.is_closed && !e.completed)
  const done = (items ?? []).filter((e) => e.completed)
  const closed = (items ?? []).filter((e) => e.is_closed && !e.completed)

  return (
    <AppLayout title="Mes exercices" subtitle="Vidéos, lectures et méditations proposées par vos responsables">
      {items === null ? (
        <div className="exercise-rows">{[0, 1, 2].map((i) => <div key={i} className="skeleton skeleton-row" />)}</div>
      ) : items.length === 0 ? (
        <div className="empty-state"><Icon name="book" size={28} /><h3>Aucun exercice pour le moment</h3><p>Vos responsables vous en proposeront au fil de votre parcours.</p></div>
      ) : (
        <>
          <h3 className="ex-group">À faire <span className="count-soft">{open.length}</span></h3>
          {open.length === 0 ? <p className="helper">Tout est fait. Merci !</p> : <div className="exercise-rows">{open.map((e) => <ExerciseRow key={e.id} ex={e} />)}</div>}
          {done.length > 0 && (<><h3 className="ex-group mt">Terminés <span className="count-soft">{done.length}</span></h3><div className="exercise-rows">{done.map((e) => <ExerciseRow key={e.id} ex={e} />)}</div></>)}
          {closed.length > 0 && (<><h3 className="ex-group mt">Fermés sans être terminés <span className="count-soft">{closed.length}</span></h3><div className="exercise-rows">{closed.map((e) => <ExerciseRow key={e.id} ex={e} />)}</div></>)}
        </>
      )}
    </AppLayout>
  )
}
