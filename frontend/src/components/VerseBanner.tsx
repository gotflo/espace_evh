import { useEffect, useState } from 'react'
import { api } from '../api/client'

export interface VerseContent { id: number | null; label: string; text: string; reference: string; message: string | null }

const CACHE_KEY = 'evh_verse'

function cached(): VerseContent | null {
  try {
    const raw = localStorage.getItem(CACHE_KEY)
    return raw ? (JSON.parse(raw) as VerseContent) : null
  } catch { return null }
}

/** Encadre du verset (tableau de bord et apercu de l'administration). */
export function VerseCard({ verse }: { verse: VerseContent }) {
  return (
    <div className="verse-banner">
      <span className="verse-label">{verse.label}</span>
      <p className="verse-text">{verse.text}</p>
      <span className="verse-ref">{verse.reference}</span>
      {verse.message && <p className="verse-message">{verse.message}</p>}
    </div>
  )
}

/** Verset du jour choisi par le serveur (programmation, rotation, mise en avant). */
export function VerseBanner() {
  const [verse, setVerse] = useState<VerseContent | null>(cached)

  useEffect(() => {
    api<{ verse: VerseContent }>('/dashboard/verse').then((r) => {
      setVerse(r.verse)
      try { localStorage.setItem(CACHE_KEY, JSON.stringify(r.verse)) } catch { /* stockage indisponible */ }
    }).catch(() => { /* on garde le dernier verset connu */ })
  }, [])

  if (!verse) return <div className="skeleton verse-skeleton" aria-hidden />
  return <VerseCard verse={verse} />
}
