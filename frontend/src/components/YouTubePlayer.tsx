import { useEffect, useRef, useState } from 'react'
import { createPlaybackTracker, type PlaybackTarget, type WatchResult } from '../utils/playback'

export type { WatchResult } from '../utils/playback'

// ---------------------------------------------------------------- API YouTube (chargee a la demande)

interface YTPlayer {
  getCurrentTime(): number
  getDuration(): number
  getPlaybackRate(): number
  getPlayerState(): number
  destroy(): void
}
interface YTNamespace {
  Player: new (el: HTMLElement, opts: Record<string, unknown>) => YTPlayer
}
declare global {
  interface Window { YT?: YTNamespace; onYouTubeIframeAPIReady?: () => void }
}

const PLAYING = 1
const ENDED = 0
let apiPromise: Promise<YTNamespace> | null = null

function loadYouTube(): Promise<YTNamespace> {
  if (window.YT?.Player) return Promise.resolve(window.YT)
  if (!apiPromise) {
    apiPromise = new Promise((resolve, reject) => {
      const previous = window.onYouTubeIframeAPIReady
      window.onYouTubeIframeAPIReady = () => { previous?.(); if (window.YT) resolve(window.YT) }
      const script = document.createElement('script')
      script.src = 'https://www.youtube.com/iframe_api'
      script.async = true
      script.onerror = () => { apiPromise = null; reject(new Error('YouTube indisponible')) }
      document.head.appendChild(script)
      window.setTimeout(() => { if (!window.YT?.Player) { apiPromise = null; reject(new Error('YouTube indisponible')) } }, 15000)
    })
  }
  return apiPromise
}

/**
 * Lecteur YouTube (youtube-nocookie) qui mesure ce qui est reellement regarde (voir utils/playback) :
 * exercices video et devoirs video.
 */
export function YouTubePlayer({ target, videoId, resumeAt = 0, track, onProgress }: {
  /** Exercice ou devoir dont la lecture est mesuree. */
  target: PlaybackTarget
  videoId: string
  resumeAt?: number
  track: boolean
  onProgress?: (r: WatchResult) => void
}) {
  const host = useRef<HTMLDivElement>(null)
  const [error, setError] = useState('')
  const onProgressRef = useRef(onProgress)
  onProgressRef.current = onProgress

  useEffect(() => {
    let player: YTPlayer | null = null
    let disposed = false
    const tracker = createPlaybackTracker({
      target, resumeAt, track,
      source: () => (player ? { time: () => player!.getCurrentTime(), duration: () => player!.getDuration(), rate: () => player!.getPlaybackRate() } : null),
      onProgress: (r) => onProgressRef.current?.(r),
    })

    function onState(e: { data: number }) {
      if (!player) return
      if (e.data === PLAYING) tracker.playing()
      else tracker.stopped(e.data === ENDED)
    }

    loadYouTube().then((YT) => {
      if (disposed || !host.current) return
      const el = document.createElement('div')
      host.current.appendChild(el)
      player = new YT.Player(el, {
        videoId,
        host: 'https://www.youtube-nocookie.com',
        playerVars: { playsinline: 1, rel: 0, modestbranding: 1, start: Math.max(0, Math.floor(resumeAt)), origin: window.location.origin },
        events: {
          onStateChange: onState,
          onError: () => setError("La vidéo ne peut pas être lue ici. Elle est peut-être privée ou son auteur interdit sa lecture hors de YouTube."),
        },
      })
    }).catch(() => setError('Impossible de charger le lecteur YouTube. Vérifiez votre connexion.'))

    const flushTimer = window.setInterval(() => { if (player?.getPlayerState() === PLAYING) tracker.tick() }, 15000)
    const onHide = () => { if (document.visibilityState === 'hidden') tracker.exit() }
    const onExit = () => tracker.exit()
    document.addEventListener('visibilitychange', onHide)
    window.addEventListener('pagehide', onExit)

    return () => {
      disposed = true
      window.clearInterval(flushTimer)
      document.removeEventListener('visibilitychange', onHide)
      window.removeEventListener('pagehide', onExit)
      tracker.dispose()
      player?.destroy()
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- la cible change seulement avec son type et son id
  }, [target.kind, target.id, videoId, resumeAt, track])

  return (
    <div className="yt-wrap">
      <div className="yt-frame" ref={host} />
      {error && <p className="alert alert-error mt-sm">{error}</p>}
    </div>
  )
}
