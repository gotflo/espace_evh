/**
 * Preparation des images AVANT l'envoi (photo de profil, annonces, evenements, apparence) :
 * 1. lecture : orientation du telephone respectee (EXIF), formats illisibles signales clairement ;
 * 2. reduction immediate a 2048 px au plus (une photo de 12 a 48 Mpx devient legere a manipuler,
 *    sans depasser les limites de memoire de Safari sur iPhone) ;
 * 3. recadrage / redimensionnement final par paliers (qualite nette, pas d'effet d'escalier) ;
 * 4. compression JPEG avec un poids cible : la qualite baisse par petites etapes seulement si besoin.
 * Les metadonnees (position GPS, appareil) disparaissent au passage. Le serveur refait une reduction
 * de securite (ImageOptimizer), mais l'envoi est deja leger et rapide, meme en 4G.
 */

/** Plus grand cote de l'image de travail (cadrage, apercu). */
const WORKING_MAX = 2048

export class ImageError extends Error {}

export interface WorkingImage {
  canvas: HTMLCanvasElement
  width: number
  height: number
  /** Adresse locale d'un apercu leger (a liberer avec release()). */
  url: string
}

function canvasOf(w: number, h: number): HTMLCanvasElement {
  const c = document.createElement('canvas')
  c.width = Math.max(1, Math.round(w))
  c.height = Math.max(1, Math.round(h))
  return c
}

function context(c: HTMLCanvasElement): CanvasRenderingContext2D {
  const ctx = c.getContext('2d')
  if (!ctx) throw new ImageError('Votre appareil ne permet pas de préparer cette image.')
  ctx.imageSmoothingEnabled = true
  ctx.imageSmoothingQuality = 'high'
  return ctx
}

function toBlob(c: HTMLCanvasElement, quality: number): Promise<Blob> {
  return new Promise((resolve, reject) => c.toBlob((b) => (b ? resolve(b) : reject(new ImageError('Image impossible à préparer.'))), 'image/jpeg', quality))
}

/** Decode le fichier (l'orientation EXIF est appliquee par le navigateur). */
async function decode(file: Blob): Promise<CanvasImageSource & { width: number; height: number }> {
  if ('createImageBitmap' in window) {
    try {
      return await createImageBitmap(file, { imageOrientation: 'from-image' })
    } catch { /* format que createImageBitmap refuse : essai avec une balise image */ }
  }
  const url = URL.createObjectURL(file)
  try {
    const img = new Image()
    img.decoding = 'async'
    img.src = url
    await img.decode()
    return Object.assign(img, { width: img.naturalWidth, height: img.naturalHeight })
  } catch {
    throw new ImageError('Cette image n’a pas pu être ouverte. Choisissez une photo JPEG ou PNG (sur iPhone : « Plus compatible » dans Réglages › Appareil photo › Formats).')
  } finally {
    URL.revokeObjectURL(url)
  }
}

/**
 * Dessine source[sx, sy, sw, sh] dans un canevas w x h, en divisant la taille par deux a chaque palier
 * tant que l'ecart est grand : bien plus net qu'une seule reduction brutale.
 */
function drawScaled(source: CanvasImageSource, sx: number, sy: number, sw: number, sh: number, w: number, h: number): HTMLCanvasElement {
  let src: CanvasImageSource = source
  let cx = sx, cy = sy, cw = sw, ch = sh
  // Paliers intermediaires liberes aussitot (Safari limite la memoire totale des canevas).
  const free = (c: CanvasImageSource) => { if (c !== source && c instanceof HTMLCanvasElement) { c.width = 0; c.height = 0 } }
  while (cw / 2 > w * 1.15 && ch / 2 > h * 1.15) {
    const step = canvasOf(cw / 2, ch / 2)
    context(step).drawImage(src, cx, cy, cw, ch, 0, 0, step.width, step.height)
    free(src)
    src = step; cx = 0; cy = 0; cw = step.width; ch = step.height
  }
  const out = canvasOf(w, h)
  const ctx = context(out)
  ctx.fillStyle = '#ffffff' // transparence (PNG) posee sur du blanc, comme sur le serveur
  ctx.fillRect(0, 0, out.width, out.height)
  ctx.drawImage(src, cx, cy, cw, ch, 0, 0, out.width, out.height)
  free(src)
  return out
}

/** Etape 1 et 2 : ouvre le fichier choisi et le ramene a 2048 px au plus. */
export async function prepareImage(file: File): Promise<WorkingImage> {
  if (file.type && !file.type.startsWith('image/')) throw new ImageError('Ce fichier n’est pas une image.')
  if (file.size > 40 * 1024 * 1024) throw new ImageError('Cette image est trop lourde (40 Mo au plus).')
  const source = await decode(file)
  const ratio = Math.min(1, WORKING_MAX / Math.max(source.width, source.height))
  const canvas = drawScaled(source, 0, 0, source.width, source.height, source.width * ratio, source.height * ratio)
  if ('close' in source && typeof source.close === 'function') source.close()
  const url = URL.createObjectURL(await toBlob(canvas, 0.9))
  return { canvas, width: canvas.width, height: canvas.height, url }
}

export function release(image: WorkingImage | null) {
  if (image) URL.revokeObjectURL(image.url)
}

/** Image pivotee d'un quart de tour (sens horaire). */
export async function rotateImage(image: WorkingImage): Promise<WorkingImage> {
  const c = canvasOf(image.height, image.width)
  const ctx = context(c)
  ctx.translate(c.width, 0)
  ctx.rotate(Math.PI / 2)
  ctx.drawImage(image.canvas, 0, 0)
  release(image)
  return { canvas: c, width: c.width, height: c.height, url: URL.createObjectURL(await toBlob(c, 0.9)) }
}

/** Etape 4 : JPEG sous le poids cible (qualite abaissee par paliers, puis taille si vraiment necessaire). */
async function compress(canvas: HTMLCanvasElement, maxBytes: number): Promise<Blob> {
  let current = canvas
  for (let round = 0; round < 3; round++) {
    for (const q of [0.86, 0.8, 0.74, 0.68, 0.62]) {
      const blob = await toBlob(current, q)
      if (blob.size <= maxBytes) return blob
    }
    current = drawScaled(current, 0, 0, current.width, current.height, current.width * 0.8, current.height * 0.8)
  }
  return toBlob(current, 0.6)
}

const asFile = (blob: Blob, name: string) => new File([blob], name, { type: 'image/jpeg', lastModified: Date.now() })

/**
 * Photo de profil : carre centre sur (cx, cy) de cote `side` (en pixels de l'image de travail),
 * rendu en 512 x 512 et 180 Ko au plus.
 */
export async function exportSquare(image: WorkingImage, cx: number, cy: number, side: number, size = 512): Promise<File> {
  const s = Math.min(side, image.width, image.height)
  const sx = Math.min(Math.max(0, cx - s / 2), image.width - s)
  const sy = Math.min(Math.max(0, cy - s / 2), image.height - s)
  const out = drawScaled(image.canvas, sx, sy, s, s, Math.min(size, s), Math.min(size, s))
  return asFile(await compress(out, 180 * 1024), 'photo.jpg')
}

/** Image entiere ramenee a `maxSide` px (annonces, evenements, fonds) sous `maxBytes`. */
export async function exportScaled(image: WorkingImage, maxSide = 1600, maxBytes = 450 * 1024, name = 'image.jpg'): Promise<File> {
  const ratio = Math.min(1, maxSide / Math.max(image.width, image.height))
  const out = drawScaled(image.canvas, 0, 0, image.width, image.height, image.width * ratio, image.height * ratio)
  return asFile(await compress(out, maxBytes), name)
}

/** Raccourci sans cadrage : fichier choisi -> fichier reduit (annonces, evenements). */
export async function shrinkFile(file: File, maxSide = 1600, maxBytes = 450 * 1024): Promise<File> {
  // GIF : souvent anime, garde tel quel (le serveur le garde aussi).
  if (file.type === 'image/gif') return file
  const image = await prepareImage(file)
  try {
    const out = await exportScaled(image, maxSide, maxBytes, (file.name.replace(/\.[^.]+$/, '') || 'image') + '.jpg')
    // Image deja plus legere que le resultat : on garde l'originale.
    return out.size < file.size ? out : file
  } finally {
    release(image)
  }
}
