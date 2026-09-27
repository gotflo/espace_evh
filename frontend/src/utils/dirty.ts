import { useCallback, useRef, useState } from 'react'

/**
 * Formulaire modifie ou non : compare les valeurs actuelles a une reference prise au premier
 * rendu, ou apres reset() (donnees arrivees du serveur, enregistrement reussi). Sert a n'activer
 * le bouton « Enregistrer » que lorsqu'il y a vraiment quelque chose a enregistrer.
 */
export function useDirty(value: unknown): { dirty: boolean; reset: () => void } {
  const current = JSON.stringify(value)
  const base = useRef<string | null>(null)
  const [, rerender] = useState(0)
  if (base.current === null) base.current = current
  // Reference reprise au rendu suivant (celui qui porte les nouvelles valeurs).
  const reset = useCallback(() => { base.current = null; rerender((n) => n + 1) }, [])
  return { dirty: base.current !== current, reset }
}
