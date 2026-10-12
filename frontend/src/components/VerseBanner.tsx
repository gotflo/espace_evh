import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { api } from '../api/client'
import { srcSetOf, useAppearance } from '../appearance'
import { usePulse } from '../pulse'

export interface VerseContent { id: number | null; label: string; text: string; reference: string; message: string | null }

const CACHE_KEY = 'evh_verse'

/*
 * Image de fond du verset : choisie et cadree par l'administration (page Apparence), deux tailles
 * (telephone et ordinateur). Un voile teal par-dessus garde le texte lisible quelle que soit la photo.
 */

function cached(): VerseContent | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY)
    return raw ? (JSON.parse(raw) as VerseContent) : null
  } catch { return null }
}

/** Taille du texte selon sa longueur : un verset court s'affiche en grand, un long reste confortable. */
function sizeOf(text: string): 'short' | 'medium' | 'long' | 'xlong' {
  const n = text.trim().length
  if (n <= 90) return 'short'
  if (n <= 220) return 'medium'
  if (n <= 450) return 'long'
  return 'xlong'
}

/** Encadre du verset (tableau de bord et apercu de l'administration). */
export function VerseCard({ verse }: { verse: VerseContent }) {
  const size = sizeOf(verse.text)
  const textRef = useRef<HTMLParagraphElement>(null)
  const [expanded, setExpanded] = useState(false)
  const [clamped, setClamped] = useState(false)
  const background = useAppearance().appearance.home
  const [failed, setFailed] = useState<string | null>(null)
  const imageOk = failed !== background.small

  // Texte tres long sur petit ecran : replie avec « Lire la suite » (mesure apres affichage).
  useLayoutEffect(() => {
    const el = textRef.current
    if (!el) return
    const measure = () => setClamped(el.scrollHeight > el.clientHeight + 2)
    measure()
    window.addEventListener('resize', measure)
    return () => window.removeEventListener('resize', measure)
  }, [verse.text, expanded])

  return (
    <figure className={`verse-banner verse-${size} ${imageOk ? 'has-image' : ''}`}>
      {imageOk && (
        <img key={background.id} className="verse-bg" src={background.small} srcSet={srcSetOf(background)}
          sizes="(max-width: 980px) 100vw, 70vw" alt="" aria-hidden decoding="async" style={{ objectPosition: background.position }}
          onError={() => setFailed(background.small)} />
      )}
      <span className="verse-shade" aria-hidden />
      <div className="verse-body">
        <span className="verse-label">{verse.label}</span>
        <blockquote className="verse-quote">
          <p ref={textRef} className={`verse-text ${expanded ? 'expanded' : ''}`}>{verse.text}</p>
        </blockquote>
        {(clamped || expanded) && size === 'xlong' && (
          <button type="button" className="verse-more" onClick={() => setExpanded((e) => !e)} aria-expanded={expanded}>
            {expanded ? 'Réduire' : 'Lire la suite'}
          </button>
        )}
        <figcaption className="verse-ref">{verse.reference}</figcaption>
        {verse.message && <p className="verse-message">{verse.message}</p>}
      </div>
    </figure>
  )
}

/** Verset du jour choisi par le serveur (programmation, rotation, mise en avant). */
export function VerseBanner() {
  const [verse, setVerse] = useState<VerseContent | null>(cached)

  const load = useCallback(() => {
    api<{ verse: VerseContent }>('/dashboard/verse').then((r) => {
      setVerse(r.verse)
      try { localStorage.setItem(CACHE_KEY, JSON.stringify(r.verse)) } catch { /* stockage indisponible */ }
    }).catch(() => { /* on garde le dernier verset connu */ })
  }, [])
  useEffect(() => { load() }, [load])
  // Verset publie, programme ou reordonne : il change aussitot sur l'accueil de chacun.
  usePulse('verses', load)

  if (!verse) return <div className="skeleton verse-skeleton" aria-hidden />
  return <VerseCard verse={verse} />
}
