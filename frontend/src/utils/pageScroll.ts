// Defilement de la page. Sur iPhone et iPad, dans l'application connectee, la page elle-meme ne defile pas :
// c'est le cadre de l'application (.app) qui defile (coque, voir utils/iosViewport.ts). Partout ailleurs, la fenetre.

/** Element qui defile quand la coque est active ; null : c'est la fenetre qui defile. */
export function pageScroller(): HTMLElement | null {
  if (!document.documentElement.classList.contains('app-shell')) return null
  return document.querySelector<HTMLElement>('#root > .app')
}

export function pageScrollTop(): number {
  const el = pageScroller()
  return el ? el.scrollTop : window.scrollY
}

export function scrollPageTo(top: number, smooth = false): void {
  const options: ScrollToOptions = { top, behavior: smooth ? 'smooth' : 'auto' }
  const el = pageScroller()
  if (el) el.scrollTo(options)
  else window.scrollTo(options)
}

// Position de chaque page visitee (cle de l'historique) : le retour arriere la retrouve. Avec la fenetre, le
// navigateur s'en charge seul ; avec la coque, c'est a nous de le faire.
const positions = new Map<string, number>()

export function rememberPageScroll(key: string, top: number): void {
  positions.set(key, top)
  if (positions.size > 200) positions.delete(positions.keys().next().value as string)
}

/** Retour arriere avec la coque : la page reprend sa position (quand son contenu est assez long pour l'atteindre). */
export function restorePageScroll(key: string): void {
  const el = pageScroller()
  if (!el) return
  const top = positions.get(key) ?? 0
  let tries = 0
  const attempt = () => {
    if (el.scrollHeight - el.clientHeight >= top - 1 || ++tries >= 15) {
      el.scrollTop = top
      return
    }
    window.setTimeout(attempt, 80)
  }
  requestAnimationFrame(attempt)
}
