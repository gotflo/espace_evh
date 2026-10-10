import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { Icon } from '../components/Icon'
import { SkeletonCard } from '../components/Skeleton'
import { YouTubePlayer } from '../components/YouTubePlayer'
import type { ResolutionsData } from '../types'

/**
 * Video d'une ressource des RM, sur sa propre page (plus confortable qu'une fenetre sur telephone) :
 * lecture dans l'application, pourcentage regarde et reprise la ou l'on s'etait arrete.
 */
export default function ResourceVideo() {
  const { id } = useParams()
  const navigate = useNavigate()
  const resourceId = Number(id)
  const [data, setData] = useState<ResolutionsData | null>(() => peek<ResolutionsData>('/me/resolutions') ?? null)
  const [failed, setFailed] = useState(false)
  const [progress, setProgress] = useState<{ percent: number; completed: boolean } | null>(null)

  useEffect(() => {
    api<ResolutionsData>('/me/resolutions').then(setData).catch(() => setFailed(true))
  }, [])

  const group = data?.resources.find((g) => g.items.some((r) => r.id === resourceId))
  const resource = group?.items.find((r) => r.id === resourceId) ?? null
  const watch = progress ?? { percent: resource?.watch?.percent ?? 0, completed: resource?.watch?.completed ?? false }
  const back = () => (window.history.length > 1 ? navigate(-1) : navigate('/mes-resolutions'))
  const backButton = <button type="button" className="btn btn-ghost small" onClick={back}><Icon name="chevron" size={15} className="flip" /> Mes RM</button>

  if (!data) {
    return (
      <AppLayout title="Ressource" actions={backButton}>
        {failed ? <section className="panel empty-state"><h3>Chargement impossible</h3><p>Vérifiez votre connexion puis réessayez.</p></section> : <SkeletonCard />}
      </AppLayout>
    )
  }

  if (!resource || !resource.youtube_id) {
    return (
      <AppLayout title="Ressource" actions={backButton}>
        <section className="panel empty-state">
          <h3>Cette ressource n'est plus disponible</h3>
          <p>Elle a peut-être été retirée. Retrouvez les autres dans <Link to="/mes-resolutions">Mes RM</Link>.</p>
        </section>
      </AppLayout>
    )
  }

  return (
    <AppLayout title="Ressource" subtitle={group?.label} actions={backButton}>
      <section className="panel res-video-page">
        <h2 className="res-video-title">{resource.title}</h2>
        <p className="helper res-video-author">par {resource.author}</p>
        <YouTubePlayer key={resource.id} target={{ kind: 'resource', id: resource.id }} videoId={resource.youtube_id}
          resumeAt={resource.watch?.resume_at ?? 0} track={!resource.watch?.completed}
          onProgress={(r) => setProgress({ percent: r.percent, completed: r.video_completed })} />
        <div className="watch-progress" aria-live="polite">
          <div className="watch-bar" role="progressbar" aria-valuenow={watch.percent} aria-valuemin={0} aria-valuemax={100} aria-label="Vidéo regardée">
            <span style={{ width: `${watch.percent}%` }} className={watch.completed ? 'done' : ''} />
          </div>
          <p className="helper">
            {watch.completed ? 'Vidéo regardée en entier.' : `Regardée à ${watch.percent} %.`}
            {!watch.completed && (resource.watch?.resume_at ?? 0) > 5 && ' La lecture reprend là où vous vous étiez arrêté.'}
          </p>
        </div>
        {resource.note && <p className="res-video-note">{resource.note}</p>}
      </section>
    </AppLayout>
  )
}
