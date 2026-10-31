import { useEffect, useId, useRef, useState, type HTMLAttributes } from 'react'
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

// Fenetres ouvertes, la plus recente en dernier : Echap ne ferme que celle du dessus.
const opened: object[] = []

const isField = (el: Element) => /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || (el as HTMLElement).isContentEditable

/**
 * Fond d'une fenetre (modal-overlay). Les evenements React (clic sur le fond pour fermer...) remontent comme avant.
 * Pour toutes les fenetres : le focus y entre a l'ouverture et revient au bouton d'origine a la fermeture, Echap fait
 * la meme chose qu'un appui sur le fond (donc rien tant que le fond ne ferme pas), et la fenetre porte un nom
 * accessible (son titre, s'il n'en a pas deja un).
 */
export function ModalLayer(props: HTMLAttributes<HTMLDivElement>) {
  const overlayRef = useRef<HTMLDivElement>(null)
  const titleId = useId()
  const [opener] = useState(() => document.activeElement)
  const closeRef = useRef<(() => void) | undefined>(undefined)
  // Aucune de ces actions de fermeture ne lit l'evenement : elles s'appellent sans argument.
  const close = (props.onClick ?? props.onMouseDown) as (() => void) | undefined
  useEffect(() => { closeRef.current = close }, [close])

  useEffect(() => {
    const overlay = overlayRef.current
    if (!overlay) return
    const self = {}
    opened.push(self)

    const dialog = overlay.getAttribute('role') === 'dialog' ? overlay : overlay.querySelector<HTMLElement>('.modal-box') ?? overlay
    dialog.setAttribute('role', 'dialog')
    dialog.setAttribute('aria-modal', 'true')
    if (!dialog.hasAttribute('aria-label') && !dialog.hasAttribute('aria-labelledby')) {
      const title = dialog.querySelector('h1, h2, h3, h4')
      if (title) {
        if (!title.id) title.id = titleId
        dialog.setAttribute('aria-labelledby', title.id)
      }
    }
    // Un champ deja focalise (autoFocus) garde la main ; sinon la fenetre elle-meme, sans ouvrir le clavier.
    if (!overlay.contains(document.activeElement)) {
      dialog.tabIndex = -1
      dialog.focus({ preventScroll: true })
    }

    // Echap deja traite a l'interieur (liste ouverte) : il ne ferme que cette liste.
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && !e.defaultPrevented && opened[opened.length - 1] === self) closeRef.current?.()
    }
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('keydown', onKey)
      opened.splice(opened.indexOf(self), 1)
      // Apres le retrait reel de la fenetre ; pas vers un champ (le clavier du telephone se rouvrirait).
      window.setTimeout(() => {
        if (overlay.isConnected || !(opener instanceof HTMLElement) || !opener.isConnected || isField(opener)) return
        if (!document.activeElement || document.activeElement === document.body) opener.focus({ preventScroll: true })
      }, 0)
    }
  }, [opener, titleId])

  return createPortal(<div ref={overlayRef} {...props} />, layer())
}
