import type { HTMLAttributes } from 'react'
import { createPortal } from 'react-dom'

/**
 * Fond d'une fenetre (modal-overlay), rendu directement dans <body> : jamais pris dans un bloc de la page
 * (animation, transformation, defilement), il couvre toujours tout l'ecran, au-dessus de la barre du bas.
 * Les evenements React (clic sur le fond pour fermer...) remontent comme avant.
 */
export function ModalLayer(props: HTMLAttributes<HTMLDivElement>) {
  return createPortal(<div {...props} />, document.body)
}
