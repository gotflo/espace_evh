import { useEffect, useRef, useState } from 'react'
import { api, auth } from '../api/client'

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

// ---------------------------------------------------------------- Suivi du visionnage

export interface WatchResult { percent: number; video_completed: boolean; status: string }

/** Ajoute un passage [a, b] a la liste en fusionnant les passages contigus. */
function addSegment(list: [number, number][], a: number, b: number) {
  if (b - a <= 0.05) return
  const last = list[list.length - 1]
  if (last && a <= last[1] + 1 && a >= last[0]) last[1] = Math.max(last[1], b)
  else list.push([Math.round(a * 10) / 10, Math.round(b * 10) / 10])
}

/**
 * Lecteur YouTube (youtube-nocookie) qui mesure ce qui est reellement regarde :
 * chaque seconde de lecture continue est un passage « vu » ; un saut en avant (barre de
 * progression, touches) est compte comme avance rapide et n'est pas considere comme vu.
 * Les passages sont envoyes au serveur toutes les 15 s, a la pause, a la fin et a la fermeture.
 */
export function YouTubePlayer({ exerciseId, videoId, resumeAt = 0, track, onProgress }: {
  exerciseId: number
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
    let timer: number | undefined
    // Etat du suivi
    const pending: [number, number][] = []
    let seeks = 0
    let skipped = 0
    let lastT: number | null = null      // derniere position pendant une lecture continue
    let lastWall = 0
    let lastKnown = resumeAt             // derniere position connue (pour detecter un saut apres pause)
    let started = false
    let sending = false
    let lastSend = 0
    let stopped = !track

    const payload = () => ({
      segments: pending.splice(0, pending.length),
      position: player ? player.getCurrentTime() : lastKnown,
      duration: player ? player.getDuration() : 0,
      seeks: (() => { const s = seeks; seeks = 0; return s })(),
      skipped: (() => { const s = Math.round(skipped); skipped = 0; return s })(),
      rate: player ? player.getPlaybackRate() : 1,
    })

    async function flush(force = false) {
      if (stopped || sending || (!force && Date.now() - lastSend < 3000)) return
      if (started && pending.length === 0 && seeks === 0 && !force) return
      sending = true
      lastSend = Date.now()
      const body = payload()
      started = true
      try {
        const r = await api<WatchResult>(`/me/exercises/${exerciseId}/progress`, { method: 'POST', body, toast: false })
        onProgressRef.current?.(r)
      } catch (err) {
        const status = (err as { status?: number }).status
        if (status === 422 || status === 403 || status === 404) stopped = true // exercice ferme : on arrete le suivi
        else pending.unshift(...body.segments) // reseau : on renverra au prochain passage
      } finally { sending = false }
    }

    /** Envoi de dernier recours a la fermeture de la page (la requete survit a la page). */
    function flushOnExit() {
      if (stopped || (pending.length === 0 && seeks === 0)) return
      const token = auth.get()
      try {
        fetch(`/api/me/exercises/${exerciseId}/progress`, {
          method: 'POST', keepalive: true,
          headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
          body: JSON.stringify(payload()),
        }).catch(() => {})
      } catch { /* navigateur sans keepalive */ }
    }

    function sample() {
      // Hors lecture continue (pause, chargement apres un saut) : rien a mesurer ; la
      // position de reference reste celle de la pause pour detecter un saut a la reprise.
      if (!player || lastT === null) return
      const t = player.getCurrentTime()
      const wall = performance.now()
      if (lastT !== null) {
        const dt = t - lastT
        const expected = ((wall - lastWall) / 1000) * (player.getPlaybackRate() || 1)
        if (dt >= -0.3 && dt <= expected + 1.5) addSegment(pending, lastT, t)
        else if (dt > expected + 1.5) { seeks += 1; skipped += dt - expected }
      }
      lastT = t
      lastWall = wall
      lastKnown = t
    }

    function onState(e: { data: number }) {
      if (!player) return
      if (e.data === PLAYING) {
        const t = player.getCurrentTime()
        // Saut effectue pendant la pause ou le chargement.
        if (t - lastKnown > 2.5) { seeks += 1; skipped += t - lastKnown }
        lastT = t; lastWall = performance.now(); lastKnown = t
        if (!started) void flush(true)
        window.clearInterval(timer)
        timer = window.setInterval(sample, 1000)
      } else {
        sample()
        window.clearInterval(timer)
        lastT = null
        void flush(e.data === ENDED)
      }
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

    const flushTimer = window.setInterval(() => { if (player?.getPlayerState() === PLAYING) { sample(); void flush() } }, 15000)
    const onHide = () => { if (document.visibilityState === 'hidden') { sample(); flushOnExit() } }
    document.addEventListener('visibilitychange', onHide)
    window.addEventListener('pagehide', flushOnExit)

    return () => {
      disposed = true
      window.clearInterval(timer)
      window.clearInterval(flushTimer)
      document.removeEventListener('visibilitychange', onHide)
      window.removeEventListener('pagehide', flushOnExit)
      if (player) { sample(); flushOnExit(); player.destroy() }
      stopped = true
    }
  }, [exerciseId, videoId, resumeAt, track])

  return (
    <div className="yt-wrap">
      <div className="yt-frame" ref={host} />
      {error && <p className="alert alert-error mt-sm">{error}</p>}
    </div>
  )
}
