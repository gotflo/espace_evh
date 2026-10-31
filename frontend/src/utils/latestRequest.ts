import { useCallback, useEffect, useRef } from 'react'

/**
 * Ancienne reponse qui arrive apres la nouvelle (filtre, date ou pasteur change entre-temps) : ignoree.
 * begin() numerote une nouvelle lecture et rend le controle a faire a l'arrivee de sa reponse ;
 * follow() sert a la suite de la lecture courante (page suivante) : elle ne perime pas cette lecture,
 * mais devient elle-meme perimee des qu'une nouvelle lecture part. Quitter la page perime tout.
 */
export function useLatestRequest(): { begin: () => () => boolean; follow: () => () => boolean } {
  const last = useRef(0)
  useEffect(() => () => { last.current += 1 }, [])
  const begin = useCallback(() => {
    last.current += 1
    const mine = last.current
    return () => mine === last.current
  }, [])
  const follow = useCallback(() => {
    const mine = last.current
    return () => mine === last.current
  }, [])
  return { begin, follow }
}
