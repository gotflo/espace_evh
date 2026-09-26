import { useEffect, useState } from 'react'

/**
 * Fond de l'ecran de connexion : photos de l'eglise en fondu enchaine, avec leger zoom
 * lent. Voile teal par-dessus pour garder le formulaire lisible. Sans animation si
 * l'utilisateur a demande de reduire les mouvements (une seule photo fixe).
 */
const PHOTOS = [
  { name: 'bible', position: 'center 40%' },
  { name: 'louange', position: 'center 30%' },
  { name: 'table', position: 'center 55%' },
  { name: 'fete', position: 'center 35%' },
]
const DELAY = 7000

export function LoginBackdrop() {
  const [index, setIndex] = useState(0)
  const [loaded, setLoaded] = useState<Record<number, boolean>>({})

  useEffect(() => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
    const id = window.setInterval(() => {
      if (document.hidden) return
      setIndex((i) => (i + 1) % PHOTOS.length)
    }, DELAY)
    return () => window.clearInterval(id)
  }, [])

  return (
    <div className="login-backdrop" aria-hidden>
      {PHOTOS.map((p, i) => {
        // Chargement progressif : la photo courante et la suivante seulement.
        const needed = i === index || i === (index + 1) % PHOTOS.length || loaded[i]
        if (!needed) return null
        return (
          <img key={p.name}
            className={`login-photo ${i === index && loaded[i] ? 'is-active' : ''}`}
            src={`/login/${p.name}-1600.jpg`}
            srcSet={`/login/${p.name}-800.jpg 800w, /login/${p.name}-1600.jpg 1600w`}
            sizes="100vw" alt="" decoding="async"
            fetchPriority={i === 0 ? 'high' : 'low'}
            style={{ objectPosition: p.position }}
            onLoad={() => setLoaded((l) => ({ ...l, [i]: true }))} />
        )
      })}
      <div className="login-veil" />
    </div>
  )
}
