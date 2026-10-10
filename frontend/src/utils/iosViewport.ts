// iPhone / iPad : apres la fermeture du clavier, Safari laisse parfois les elements fixes (barre d'onglets du
// bas, menu, barres d'enregistrement) a mi-hauteur de l'ecran jusqu'au prochain defilement. Un micro-defilement
// invisible, une fois le clavier ferme, les remet a leur place.

function isIos(): boolean {
  return /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

export function installIosViewportFix(): void {
  if (!isIos()) return
  let timer: number | undefined
  const nudge = () => {
    window.clearTimeout(timer)
    timer = window.setTimeout(() => {
      const el = document.activeElement
      if (el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)) return // clavier encore ouvert
      const x = window.scrollX
      const y = window.scrollY
      window.scrollTo(x, y + 1)
      window.scrollTo(x, y)
    }, 150)
  }
  document.addEventListener('focusout', nudge)
  window.visualViewport?.addEventListener('resize', nudge)
  window.addEventListener('orientationchange', nudge)
  window.addEventListener('pageshow', nudge)
}
