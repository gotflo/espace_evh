import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api/client'
import { usePulse } from '../pulse'
import type { MyExercise } from '../types'
import { ExerciseRow } from './ExerciseRow'

/** Tableau de bord : exercices ouverts non termines (les plus urgents d'abord). */
export function MyExercises() {
  const [exercises, setExercises] = useState<MyExercise[]>([])

  const load = useCallback(() => {
    api<{ exercises: MyExercise[] }>('/me/exercises').then((r) => setExercises(r.exercises)).catch(() => {})
  }, [])
  useEffect(() => { load() }, [load])
  usePulse('exercises', load)

  const open = exercises.filter((e) => !e.is_closed && !e.completed)
  if (open.length === 0) return null

  return (
    <section className="panel mt" id="exercices">
      <div className="panel-head">
        <h3>Mes exercices <span className="count-soft">{open.length}</span></h3>
        <Link className="btn-link" to="/exercices">Tout voir</Link>
      </div>
      <div className="exercise-rows">
        {open.slice(0, 4).map((ex) => <ExerciseRow key={ex.id} ex={ex} />)}
      </div>
    </section>
  )
}
