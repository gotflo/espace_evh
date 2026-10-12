import { useCallback, useEffect, useRef, useState, type KeyboardEvent as ReactKeyboardEvent, type PointerEvent as ReactPointerEvent } from 'react'
import { exportSquare, ImageError, prepareImage, release, rotateImage, type WorkingImage } from '../utils/imagePipeline'
import { ModalLayer } from './ModalLayer'

/** Marge autour du cercle, et zoom maximal (x4 par rapport a l'image qui remplit juste le cercle). */
const MARGIN = 18
const MAX_ZOOM = 4

interface View { z: number; u: number; v: number }

/**
 * Cadrage de la photo de profil avant l'envoi : l'utilisateur fait glisser la photo pour placer son visage
 * dans le cercle, zoome (pincement, molette, curseur ou touches + / -), peut la pivoter, puis valide.
 * La photo est alors reduite (512 x 512, 180 Ko au plus) : c'est ce fichier leger qui part au serveur.
 */
export function PhotoCropper({ file, onCancel, onDone }: { file: File; onCancel: () => void; onDone: (photo: File) => void }) {
  const [image, setImage] = useState<WorkingImage | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [stage, setStage] = useState(300)
  const [view, setView] = useState<View>({ z: 1, u: 0, v: 0 })
  const stageRef = useRef<HTMLDivElement>(null)
  const pointers = useRef(new Map<number, { x: number; y: number }>())
  const imageRef = useRef<WorkingImage | null>(null)

  // Ouverture et reduction immediate de la photo choisie.
  useEffect(() => {
    let cancelled = false
    prepareImage(file).then((img) => {
      if (cancelled) { release(img); return }
      imageRef.current = img
      setImage(img)
      setView({ z: 1, u: img.width / 2, v: img.height / 2 })
    }).catch((e) => setError(e instanceof ImageError ? e.message : 'Cette image n’a pas pu être ouverte.'))
    return () => { cancelled = true }
  }, [file])
  useEffect(() => () => release(imageRef.current), [])

  // Taille de la zone (s'adapte a la largeur de l'ecran).
  useEffect(() => {
    const el = stageRef.current
    if (!el) return
    const measure = () => setStage(Math.max(200, Math.round(el.clientWidth)))
    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(el)
    return () => ro.disconnect()
  }, [image])

  const circle = stage - MARGIN * 2
  const base = image ? circle / Math.min(image.width, image.height) : 1

  /** Garde le cercle toujours rempli par la photo. */
  const clamp = useCallback((next: View): View => {
    if (!image) return next
    const z = Math.min(MAX_ZOOM, Math.max(1, next.z))
    const half = circle / (2 * base * z)
    return {
      z,
      u: Math.min(image.width - half, Math.max(half, next.u)),
      v: Math.min(image.height - half, Math.max(half, next.v)),
    }
  }, [image, circle, base])

  /** Zoom autour d'un point de la zone (doigts, curseur de la souris) : ce point reste sous le doigt. */
  const zoomAt = useCallback((zoom: (z: number) => number, px = stage / 2, py = stage / 2) => {
    setView((cur) => {
      const s0 = base * cur.z
      const target = clamp({ ...cur, z: zoom(cur.z) })
      const s1 = base * target.z
      const ix = cur.u + (px - stage / 2) / s0
      const iy = cur.v + (py - stage / 2) / s0
      return clamp({ z: target.z, u: ix - (px - stage / 2) / s1, v: iy - (py - stage / 2) / s1 })
    })
  }, [base, clamp, stage])

  // Molette (ordinateur) : ecouteur non passif pour ne pas faire defiler la page.
  useEffect(() => {
    const el = stageRef.current
    if (!el || !image) return
    const onWheel = (e: WheelEvent) => {
      e.preventDefault()
      const r = el.getBoundingClientRect()
      const factor = Math.exp(-e.deltaY * 0.0015)
      zoomAt((z) => z * factor, e.clientX - r.left, e.clientY - r.top)
    }
    el.addEventListener('wheel', onWheel, { passive: false })
    return () => el.removeEventListener('wheel', onWheel)
  }, [image, zoomAt])

  function onPointerDown(e: ReactPointerEvent<HTMLDivElement>) {
    try { e.currentTarget.setPointerCapture(e.pointerId) } catch { /* pointeur deja relache */ }
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
  }

  function onPointerMove(e: ReactPointerEvent<HTMLDivElement>) {
    const map = pointers.current
    const prev = map.get(e.pointerId)
    if (!prev) return
    const pts = [...map.entries()]
    if (pts.length === 1) {
      const dx = e.clientX - prev.x
      const dy = e.clientY - prev.y
      setView((cur) => clamp({ ...cur, u: cur.u - dx / (base * cur.z), v: cur.v - dy / (base * cur.z) }))
    } else if (pts.length === 2) {
      const other = pts.find(([id]) => id !== e.pointerId)![1]
      const before = Math.hypot(prev.x - other.x, prev.y - other.y)
      const after = Math.hypot(e.clientX - other.x, e.clientY - other.y)
      const r = e.currentTarget.getBoundingClientRect()
      if (before > 0) zoomAt((z) => z * (after / before), (e.clientX + other.x) / 2 - r.left, (e.clientY + other.y) / 2 - r.top)
    }
    map.set(e.pointerId, { x: e.clientX, y: e.clientY })
  }

  function onPointerUp(e: ReactPointerEvent<HTMLDivElement>) {
    pointers.current.delete(e.pointerId)
  }

  function onKeyDown(e: ReactKeyboardEvent<HTMLDivElement>) {
    const step = 12 / (base * view.z)
    const moves: Record<string, [number, number]> = { ArrowLeft: [-step, 0], ArrowRight: [step, 0], ArrowUp: [0, -step], ArrowDown: [0, step] }
    if (moves[e.key]) {
      e.preventDefault()
      const [du, dv] = moves[e.key]
      setView((cur) => clamp({ ...cur, u: cur.u + du, v: cur.v + dv }))
    } else if (e.key === '+' || e.key === '=') {
      e.preventDefault(); zoomAt((z) => z * 1.15)
    } else if (e.key === '-') {
      e.preventDefault(); zoomAt((z) => z / 1.15)
    }
  }

  async function rotate() {
    if (!image) return
    setBusy(true)
    try {
      const next = await rotateImage(image)
      imageRef.current = next
      setImage(next)
      setView({ z: 1, u: next.width / 2, v: next.height / 2 })
    } finally { setBusy(false) }
  }

  async function validate() {
    if (!image) return
    setBusy(true)
    try {
      onDone(await exportSquare(image, view.u, view.v, circle / (base * view.z)))
    } catch (e) {
      setError(e instanceof ImageError ? e.message : 'La photo n’a pas pu être préparée. Réessayez.')
      setBusy(false)
    }
  }

  // Echap ferme la fenetre.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onCancel() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onCancel])

  const scale = base * view.z
  return (
    <ModalLayer className="modal-overlay" onClick={onCancel}>
      <div className="modal-box cropper" role="dialog" aria-modal="true" aria-labelledby="cropper-title" onClick={(e) => e.stopPropagation()}>
        <div className="panel-head"><h3 id="cropper-title">Ajuster la photo</h3></div>
        <p className="helper cropper-help">Faites glisser la photo pour placer le visage dans le cercle. Pincez, ou utilisez le curseur, pour zoomer.</p>

        <div ref={stageRef} className="cropper-stage" tabIndex={0} role="application"
          aria-label="Zone de cadrage : flèches pour déplacer, plus et moins pour zoomer"
          onPointerDown={onPointerDown} onPointerMove={onPointerMove} onPointerUp={onPointerUp} onPointerCancel={onPointerUp}
          onKeyDown={onKeyDown} style={{ height: stage }}>
          {image ? (
            <img className="cropper-image" src={image.url} alt="" draggable={false}
              style={{ width: image.width * scale, height: image.height * scale, transform: `translate3d(${stage / 2 - view.u * scale}px, ${stage / 2 - view.v * scale}px, 0)` }} />
          ) : !error && <span className="cropper-loading"><span className="spinner" /> Préparation de la photo…</span>}
          <span className="cropper-mask" aria-hidden style={{ width: circle, height: circle }} />
        </div>

        {error && <p className="helper editor-error" role="alert">{error}</p>}

        <div className="cropper-tools">
          <button type="button" className="cropper-zoom-btn" aria-label="Dézoomer" disabled={!image || view.z <= 1} onClick={() => zoomAt((z) => z / 1.2)}>−</button>
          <input type="range" className="cropper-range" min={1} max={MAX_ZOOM} step={0.01} value={view.z} disabled={!image}
            aria-label="Zoom" onChange={(e) => { const z = Number(e.target.value); zoomAt(() => z) }} />
          <button type="button" className="cropper-zoom-btn" aria-label="Zoomer" disabled={!image || view.z >= MAX_ZOOM} onClick={() => zoomAt((z) => z * 1.2)}>+</button>
        </div>
        <div className="cropper-secondary">
          <button type="button" className="btn btn-ghost small" disabled={!image || busy} onClick={rotate}>Pivoter</button>
          <button type="button" className="btn btn-ghost small" disabled={!image || busy}
            onClick={() => image && setView({ z: 1, u: image.width / 2, v: image.height / 2 })}>Recentrer</button>
        </div>

        <div className="editor-actions">
          <button type="button" className="btn btn-primary" disabled={!image || busy} onClick={validate}>{busy ? <span className="spinner" /> : 'Valider la photo'}</button>
          <button type="button" className="btn btn-ghost" onClick={onCancel}>Annuler</button>
        </div>
      </div>
    </ModalLayer>
  )
}
