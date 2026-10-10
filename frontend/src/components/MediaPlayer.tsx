import { useEffect, useRef, useState } from 'react'
import type { AssignmentMedia } from '../types'
import { createPlaybackTracker, type PlaybackTarget, type WatchResult } from '../utils/playback'
import { Icon } from './Icon'
import { YouTubePlayer } from './YouTubePlayer'

/**
 * Video YouTube allegee (ressources des RM) : la miniature s'affiche d'abord (rien n'est charge
 * depuis YouTube), le lecteur (youtube-nocookie) ne s'ouvre qu'au toucher, dans l'application.
 */
export function YouTubeLite({ id, title }: { id: string; title: string }) {
  const [playing, setPlaying] = useState(false)
  return (
    <div className="yt-lite">
      {playing ? (
        <iframe src={`https://www.youtube-nocookie.com/embed/${id}?autoplay=1&rel=0&modestbranding=1&playsinline=1`}
          title={title} allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowFullScreen />
      ) : (
        <button type="button" className="yt-lite-poster" onClick={() => setPlaying(true)} aria-label={`Lire la vidéo : ${title}`}>
          <img src={`https://i.ytimg.com/vi/${id}/hqdefault.jpg`} alt="" loading="lazy" />
          <span className="yt-lite-play" aria-hidden><Icon name="play" size={34} /></span>
        </button>
      )}
    </div>
  )
}

/** Lecteur audio qui mesure l'ecoute reelle, avec la meme regle que les videos (utils/playback). */
function TrackedAudio({ src, title, target, resumeAt, track, onProgress }: {
  src: string; title: string; target: PlaybackTarget; resumeAt: number; track: boolean; onProgress?: (r: WatchResult) => void
}) {
  const ref = useRef<HTMLAudioElement>(null)
  const onProgressRef = useRef(onProgress)
  onProgressRef.current = onProgress

  useEffect(() => {
    const el = ref.current
    if (!el) return
    const tracker = createPlaybackTracker({
      target, resumeAt, track,
      source: () => ({ time: () => el.currentTime, duration: () => (Number.isFinite(el.duration) ? el.duration : 0), rate: () => el.playbackRate }),
      onProgress: (r) => onProgressRef.current?.(r),
    })
    // Reprise la ou le membre s'etait arrete.
    const onMeta = () => { if (resumeAt > 2 && (!Number.isFinite(el.duration) || resumeAt < el.duration - 2)) el.currentTime = resumeAt }
    const onPlaying = () => tracker.playing()
    const onPause = () => tracker.stopped(false)
    const onEnded = () => tracker.stopped(true)
    const onHide = () => { if (document.visibilityState === 'hidden') tracker.exit() }
    const onExit = () => tracker.exit()
    if (el.readyState >= 1) onMeta(); else el.addEventListener('loadedmetadata', onMeta, { once: true })
    el.addEventListener('playing', onPlaying)
    el.addEventListener('pause', onPause)
    el.addEventListener('waiting', onPause)
    el.addEventListener('ended', onEnded)
    document.addEventListener('visibilitychange', onHide)
    window.addEventListener('pagehide', onExit)
    const flushTimer = window.setInterval(() => { if (!el.paused) tracker.tick() }, 15000)
    return () => {
      window.clearInterval(flushTimer)
      el.removeEventListener('loadedmetadata', onMeta)
      el.removeEventListener('playing', onPlaying)
      el.removeEventListener('pause', onPause)
      el.removeEventListener('waiting', onPause)
      el.removeEventListener('ended', onEnded)
      document.removeEventListener('visibilitychange', onHide)
      window.removeEventListener('pagehide', onExit)
      tracker.dispose()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- la cible change seulement avec son type et son id
  }, [target.kind, target.id, resumeAt, track])

  return (
    <div className="audio-card">
      <span className="audio-card-icon" aria-hidden><Icon name="headphones" size={22} /></span>
      <audio ref={ref} controls preload="metadata" src={src} aria-label={`Écouter : ${title}`}>
        Votre navigateur ne lit pas ce fichier audio.
      </audio>
    </div>
  )
}

/**
 * Lecture d'un devoir : video YouTube ou fichier audio, avec le suivi de lecture des exercices
 * (passages reellement lus, avances rapides, reprise) ; un lien externe s'ouvre dans un onglet.
 */
export function MediaPlayer({ media, title, target, resumeAt = 0, track = false, onProgress }: {
  media: AssignmentMedia
  title: string
  /** Devoir dont la lecture est mesuree (absent : simple lecture). */
  target?: PlaybackTarget
  resumeAt?: number
  track?: boolean
  onProgress?: (r: WatchResult) => void
}) {
  if (media.youtube_id && target) {
    return <YouTubePlayer target={target} videoId={media.youtube_id} resumeAt={resumeAt} track={track} onProgress={onProgress} />
  }
  if (media.youtube_id) return <YouTubeLite id={media.youtube_id} title={title} />
  if (media.audio_url && target) {
    return <TrackedAudio src={media.audio_url} title={title} target={target} resumeAt={resumeAt} track={track} onProgress={onProgress} />
  }
  if (media.audio_url) {
    return (
      <div className="audio-card">
        <span className="audio-card-icon" aria-hidden><Icon name="headphones" size={22} /></span>
        <audio controls preload="metadata" src={media.audio_url} aria-label={`Écouter : ${title}`}>Votre navigateur ne lit pas ce fichier audio.</audio>
      </div>
    )
  }
  if (media.link) {
    return (
      <a className="media-link" href={media.link} target="_blank" rel="noopener noreferrer">
        <Icon name={media.type === 'audio' ? 'headphones' : 'play'} size={22} />
        <span>
          <strong>{media.type === 'audio' ? "Écouter l'audio" : 'Regarder la vidéo'}</strong>
          <small>S'ouvre dans un nouvel onglet</small>
        </span>
        <Icon name="external" size={18} />
      </a>
    )
  }
  return null
}
