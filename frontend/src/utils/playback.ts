import { api, auth } from '../api/client'

// ---------------------------------------------------------------- Suivi de lecture (video ou audio)
// Meme mesure pour les exercices video et les devoirs (video YouTube ou audio) : chaque seconde de
// lecture continue est un passage « lu » ; un saut en avant (barre de progression, touches) est
// compte comme avance rapide et n'est pas considere comme lu. Les passages partent au serveur toutes
// les 15 s, a la pause, a la fin et a la fermeture de la page ; le serveur refait tous les controles.

export interface WatchResult { percent: number; video_completed: boolean; status: string }

/** Ce qui est lu : un exercice video, un devoir (video ou audio) ou une ressource video des RM. */
export interface PlaybackTarget { kind: 'exercise' | 'assignment' | 'resource'; id: number }

/** Adresse de l'API qui recoit les passages lus. */
const progressPath = (t: PlaybackTarget) => (t.kind === 'exercise' ? `/me/exercises/${t.id}/progress`
  : t.kind === 'resource' ? `/me/resources/${t.id}/progress` : `/me/assignments/${t.id}/progress`)

/** Ce que le lecteur sait dire de la lecture en cours. */
export interface PlaybackSource { time(): number; duration(): number; rate(): number }

/** Ajoute un passage [a, b] a la liste en fusionnant les passages contigus. */
function addSegment(list: [number, number][], a: number, b: number) {
  if (b - a <= 0.05) return
  const last = list[list.length - 1]
  if (last && a <= last[1] + 1 && a >= last[0]) last[1] = Math.max(last[1], b)
  else list.push([Math.round(a * 10) / 10, Math.round(b * 10) / 10])
}

export interface PlaybackTracker {
  /** La lecture (re)demarre. */
  playing(): void
  /** La lecture s'arrete (pause, chargement, fin). */
  stopped(ended: boolean): void
  /** A appeler regulierement pendant la lecture (envoi toutes les 15 s). */
  tick(): void
  /** Page cachee ou fermee : envoi de dernier recours. */
  exit(): void
  dispose(): void
}

export function createPlaybackTracker({ target, resumeAt, track, source, onProgress }: {
  target: PlaybackTarget
  resumeAt: number
  track: boolean
  source: () => PlaybackSource | null
  onProgress?: (r: WatchResult) => void
}): PlaybackTracker {
  let timer: number | undefined
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

  const payload = () => {
    const s = source()
    return {
      segments: pending.splice(0, pending.length),
      position: s ? s.time() : lastKnown,
      duration: s ? s.duration() : 0,
      seeks: (() => { const v = seeks; seeks = 0; return v })(),
      skipped: (() => { const v = Math.round(skipped); skipped = 0; return v })(),
      rate: s ? s.rate() : 1,
    }
  }

  async function flush(force = false) {
    if (stopped || sending || (!force && Date.now() - lastSend < 3000)) return
    if (started && pending.length === 0 && seeks === 0 && !force) return
    sending = true
    lastSend = Date.now()
    const body = payload()
    started = true
    try {
      const r = await api<WatchResult>(progressPath(target), { method: 'POST', body, toast: false })
      onProgress?.(r)
    } catch (err) {
      const status = (err as { status?: number }).status
      if (status === 422 || status === 403 || status === 404) stopped = true // ferme ou retire : on arrete le suivi
      else pending.unshift(...body.segments) // reseau : on renverra au prochain passage
    } finally { sending = false }
  }

  function exit() {
    if (stopped || (pending.length === 0 && seeks === 0)) return
    const token = auth.get()
    try {
      fetch(`/api${progressPath(target)}`, {
        method: 'POST', keepalive: true,
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) },
        body: JSON.stringify(payload()),
      }).catch(() => {})
    } catch { /* navigateur sans keepalive */ }
  }

  function sample() {
    // Hors lecture continue (pause, chargement apres un saut) : rien a mesurer ; la position de
    // reference reste celle de la pause pour detecter un saut a la reprise.
    const s = source()
    if (!s || lastT === null) return
    const t = s.time()
    const wall = performance.now()
    const dt = t - lastT
    const expected = ((wall - lastWall) / 1000) * (s.rate() || 1)
    if (dt >= -0.3 && dt <= expected + 1.5) addSegment(pending, lastT, t)
    else if (dt > expected + 1.5) { seeks += 1; skipped += dt - expected }
    lastT = t
    lastWall = wall
    lastKnown = t
  }

  return {
    playing() {
      const s = source()
      if (!s) return
      const t = s.time()
      // Saut effectue pendant la pause ou le chargement.
      if (t - lastKnown > 2.5) { seeks += 1; skipped += t - lastKnown }
      lastT = t; lastWall = performance.now(); lastKnown = t
      if (!started) void flush(true)
      window.clearInterval(timer)
      timer = window.setInterval(sample, 1000)
    },
    stopped(ended) {
      sample()
      window.clearInterval(timer)
      lastT = null
      void flush(ended)
    },
    tick() { if (lastT !== null) { sample(); void flush() } },
    exit() { sample(); exit() },
    dispose() {
      window.clearInterval(timer)
      sample()
      exit()
      stopped = true
    },
  }
}
