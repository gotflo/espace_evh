import { useEffect, useRef, useState, type PointerEvent as ReactPointerEvent } from 'react'
import { ApiError } from '../api/client'
import { exportScaled, ImageError, prepareImage, release, type WorkingImage } from '../utils/imagePipeline'
import { ModalLayer } from './ModalLayer'

export interface FramePreview { label: string; ratio: number }
export interface FrameResult { file?: File; x: number; y: number }

const clamp = (n: number) => Math.round(Math.min(100, Math.max(0, n)))

/**
 * Cadrage d'une image de fond (accueil, ecran de connexion) : l'image s'affiche en entier avec un repere,
 * et en apercu tel qu'elle apparaitra sur ordinateur et sur telephone. On touche la partie a garder
 * visible, ou on fait glisser l'image dans un apercu. Une nouvelle image est reduite avant l'envoi
 * (1600 px, 500 Ko au plus) ; pour un simple recadrage, rien n'est renvoye que la position.
 */
export function ImageFramer({ file, src, x: x0 = 50, y: y0 = 50, previews, title, onCancel, onDone }: {
  file?: File
  src?: string
  x?: number
  y?: number
  previews: FramePreview[]
  title: string
  onCancel: () => void
  onDone: (result: FrameResult) => Promise<void>
}) {
  const [image, setImage] = useState<WorkingImage | null>(null)
  const [natural, setNatural] = useState<{ w: number; h: number } | null>(null)
  const [pos, setPos] = useState({ x: x0, y: y0 })
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const imageRef = useRef<WorkingImage | null>(null)
  const drag = useRef<{ mode: 'point' | 'pan'; el: HTMLElement; lastX: number; lastY: number } | null>(null)

  useEffect(() => {
    if (!file) return
    let cancelled = false
    prepareImage(file).then((img) => {
      if (cancelled) { release(img); return }
      imageRef.current = img
      setImage(img)
      setNatural({ w: img.width, h: img.height })
    }).catch((e) => setError(e instanceof ImageError ? e.message : 'Cette image n’a pas pu être ouverte.'))
    return () => { cancelled = true }
  }, [file])
  useEffect(() => () => release(imageRef.current), [])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !busy) onCancel() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onCancel, busy])

  const url = image?.url ?? src ?? ''
  const position = `${pos.x}% ${pos.y}%`

  /** Repere pose a l'endroit touche sur l'image entiere. */
  function pointAt(el: HTMLElement, cx: number, cy: number) {
    const r = el.getBoundingClientRect()
    setPos({ x: clamp(((cx - r.left) / r.width) * 100), y: clamp(((cy - r.top) / r.height) * 100) })
  }

  /** Glisser dans un apercu : deplace l'image comme sous le doigt. */
  function panBy(el: HTMLElement, dx: number, dy: number) {
    if (!natural) return
    const r = el.getBoundingClientRect()
    const cover = Math.max(r.width / natural.w, r.height / natural.h)
    const overflowX = natural.w * cover - r.width
    const overflowY = natural.h * cover - r.height
    setPos((p) => ({
      x: overflowX > 1 ? clamp(p.x - (dx / overflowX) * 100) : p.x,
      y: overflowY > 1 ? clamp(p.y - (dy / overflowY) * 100) : p.y,
    }))
  }

  function down(mode: 'point' | 'pan') {
    return (e: ReactPointerEvent<HTMLElement>) => {
      try { e.currentTarget.setPointerCapture(e.pointerId) } catch { /* pointeur deja relache */ }
      drag.current = { mode, el: e.currentTarget, lastX: e.clientX, lastY: e.clientY }
      if (mode === 'point') pointAt(e.currentTarget, e.clientX, e.clientY)
    }
  }
  function move(e: ReactPointerEvent<HTMLElement>) {
    const d = drag.current
    if (!d) return
    if (d.mode === 'point') pointAt(d.el, e.clientX, e.clientY)
    else panBy(d.el, e.clientX - d.lastX, e.clientY - d.lastY)
    d.lastX = e.clientX; d.lastY = e.clientY
  }
  const up = () => { drag.current = null }

  async function validate() {
    setBusy(true); setError('')
    try {
      const out = image ? await exportScaled(image, 1600, 500 * 1024, 'fond.jpg') : undefined
      await onDone({ file: out, ...pos })
    } catch (e) {
      setError(e instanceof ImageError ? e.message : e instanceof ApiError ? e.firstMessage : 'L’enregistrement n’a pas abouti. Réessayez.')
      setBusy(false)
    }
  }

  const ready = !!url && !!natural
  return (
    <ModalLayer className="modal-overlay" onClick={() => !busy && onCancel()}>
      <div className="modal-box modal-wide framer" role="dialog" aria-modal="true" aria-labelledby="framer-title" onClick={(e) => e.stopPropagation()}>
        <div className="panel-head"><h3 id="framer-title">{title}</h3></div>
        <p className="helper">Touchez la partie de l’image à garder visible, ou faites glisser l’image dans un aperçu.</p>

        <div className="framer-main">
          {url ? (
            <div className="framer-full" onPointerDown={down('point')} onPointerMove={move} onPointerUp={up} onPointerCancel={up}
              role="slider" tabIndex={0} aria-label="Point de l’image gardé visible" aria-valuetext={`${pos.x} % horizontalement, ${pos.y} % verticalement`}
              onKeyDown={(e) => {
                const k: Record<string, [number, number]> = { ArrowLeft: [-2, 0], ArrowRight: [2, 0], ArrowUp: [0, -2], ArrowDown: [0, 2] }
                if (k[e.key]) { e.preventDefault(); setPos((p) => ({ x: clamp(p.x + k[e.key][0]), y: clamp(p.y + k[e.key][1]) })) }
              }}>
              <img src={url} alt="" draggable={false} onLoad={(e) => !image && setNatural({ w: e.currentTarget.naturalWidth, h: e.currentTarget.naturalHeight })} />
              <span className="framer-marker" aria-hidden style={{ left: `${pos.x}%`, top: `${pos.y}%` }} />
            </div>
          ) : !error && <span className="cropper-loading"><span className="spinner" /> Préparation de l’image…</span>}
        </div>

        {ready && (
          <div className="framer-previews">
            {previews.map((p) => (
              <figure key={p.label} className="framer-preview" style={{ width: `min(100%, ${Math.round(p.ratio * (p.ratio > 1 ? 88 : 150))}px)` }}>
                <div className="framer-frame" style={{ aspectRatio: String(p.ratio) }}
                  onPointerDown={down('pan')} onPointerMove={move} onPointerUp={up} onPointerCancel={up}>
                  <img src={url} alt="" draggable={false} style={{ objectPosition: position }} />
                </div>
                <figcaption>{p.label}</figcaption>
              </figure>
            ))}
          </div>
        )}

        {natural && natural.h > natural.w * 1.05 && (
          <p className="helper framer-warning">Photo en hauteur : sur ordinateur, seule une bande sera visible. Une photo prise en largeur rendra mieux.</p>
        )}
        {natural && Math.max(natural.w, natural.h) < 1000 && (
          <p className="helper framer-warning">Image assez petite : elle pourra paraître floue sur un grand écran.</p>
        )}
        {error && <p className="helper editor-error" role="alert">{error}</p>}
        <div className="editor-actions">
          <button type="button" className="btn btn-primary" disabled={!ready || busy} onClick={validate}>{busy ? <span className="spinner" /> : 'Enregistrer'}</button>
          <button type="button" className="btn btn-ghost" disabled={busy} onClick={onCancel}>Annuler</button>
        </div>
      </div>
    </ModalLayer>
  )
}
