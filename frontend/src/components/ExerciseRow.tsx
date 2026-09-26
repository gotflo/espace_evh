import { Link } from 'react-router-dom'
import type { MyExercise } from '../types'
import { deadlineLabel } from '../utils/exercises'

export function ExerciseRow({ ex }: { ex: MyExercise }) {
  const deadline = deadlineLabel(ex.closes_at, ex.is_closed)
  return (
    <Link to={`/exercices/${ex.id}`} className={`exercise-row ${ex.completed ? 'is-done' : ''} ${ex.is_closed ? 'is-closed' : ''}`}>
      {ex.video
        ? <img className="exercise-thumb" src={ex.video.thumbnail} alt="" loading="lazy" />
        : <span className="exercise-thumb exercise-thumb-icon" aria-hidden>📖</span>}
      <span className="exercise-row-main">
        <strong>{ex.title}</strong>
        <small>{ex.video ? (ex.requires_response ? 'Vidéo + réponse' : 'Vidéo à regarder') : 'Exercice à rendre'}{deadline ? ` · ${deadline}` : ''}</small>
        {ex.video && !ex.completed && !ex.is_closed && ex.percent > 0 && (
          <span className="mini-progress"><span style={{ width: `${ex.percent}%` }} /></span>
        )}
      </span>
      <span className={`exercise-badge ${ex.completed ? 'done' : ex.is_closed ? 'closed' : ex.status}`}>
        {ex.completed ? 'Terminé' : ex.is_closed ? 'Fermé' : ex.status === 'in_progress' ? `${ex.video ? ex.percent + ' %' : 'En cours'}` : 'À faire'}
      </span>
    </Link>
  )
}
