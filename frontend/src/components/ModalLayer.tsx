import type { HTMLAttributes } from 'react'
import { createPortal } from 'react-dom'

/**
 * Calque des fenetres, dans <body> : jamais pris dans un bloc de la page (animation, transformation,
 * defilement), il couvre toujours tout l'ecran, au-dessus de la barre du bas. Il porte la classe « app »
 * (sans boite : display contents) pour garder exactement les couleurs et styles de l'application.
 */
function layer(): HTMLElement {
  let el = document.getElementById('app-layer')
  if (!el) {
    el = document.createElement('div')
    el.id = 'app-layer'
    el.className = 'app app-layer'
    document.body.appendChild(el)
  }
  return el
}

/** Fond d'une fenetre (modal-overlay). Les evenements React (clic sur le fond pour fermer...) remontent comme avant. */
export function ModalLayer(props: HTMLAttributes<HTMLDivElement>) {
  return createPortal(<div {...props} />, layer())
}
