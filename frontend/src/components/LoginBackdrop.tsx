import { useEffect, useState } from 'react'
import { srcSetOf, useAppearance } from '../appearance'

/**
 * Fond de l'ecran de connexion : photos de l'eglise (choisies, ordonnees et cadrees par
 * l'administration, page Apparence) en fondu enchaine, avec leger zoom lent. Voile teal par-dessus
 * pour garder le formulaire lisible. Sans animation si l'utilisateur a demande de reduire les
 * mouvements (une seule photo fixe).
 */
const DELAY = 7000

export function LoginBackdrop() {
  const { appearance, known } = useAppearance(false)
  // Premiere visite sur cet appareil : on attend la liste un court instant plutot que d'afficher
  // des photos qui changeraient aussitot.
  const [waited, setWaited] = useState(known)
  const photos = known || waited ? appearance.login : []
  const [index, setIndex] = useState(0)
  const [loaded, setLoaded] = useState<Record<string, boolean>>({})

  useEffect(() => {
    if (known) return
    const id = window.setTimeout(() => setWaited(true), 1500)
    return () => window.clearTimeout(id)
  }, [known])

  useEffect(() => {
    if (photos.length < 2 || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return
    const id = window.setInterval(() => {
      if (document.hidden) return
      setIndex((i) => (i + 1) % photos.length)
    }, DELAY)
    return () => window.clearInterval(id)
  }, [photos.length])

  const current = photos.length ? index % photos.length : 0
  return (
    <div className="login-backdrop" aria-hidden>
      {photos.map((p, i) => {
        // Chargement progressif : la photo courante et la suivante seulement.
        const needed = i === current || i === (current + 1) % photos.length || loaded[p.id]
        if (!needed) return null
        return (
          <img key={p.id}
            className={`login-photo ${i === current && loaded[p.id] ? 'is-active' : ''}`}
            src={p.large}
            srcSet={srcSetOf(p)}
            sizes="100vw" alt="" decoding="async"
            fetchPriority={i === 0 ? 'high' : 'low'}
            style={{ objectPosition: p.position }}
            onLoad={() => setLoaded((l) => ({ ...l, [p.id]: true }))} />
        )
      })}
      <div className="login-veil" />
    </div>
  )
}
