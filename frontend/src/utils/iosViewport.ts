import { reportClientError } from '../monitoring'

// iPhone / iPad : apres la fermeture du clavier, Safari garde parfois une « fenetre d'affichage » plus courte
// que l'écran : la barre d'onglets du bas, le menu et les barres d'enregistrement restent alors a mi-hauteur.
// Deux protections :
// 1. recalage : l'ecart entre la hauteur reellement visible (visualViewport) et celle que Safari utilise pour
//    les elements fixes (innerHeight) est mesure en continu ; les elements du bas sont decales d'autant
//    (variable CSS --ios-gap, 0 en temps normal) ;
// 2. micro-defilement asynchrone apres la fermeture du clavier, qui force Safari a recalculer sa mise en page.

function isIos(): boolean {
  return /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

const isField = (el: Element | null) => !!el && /^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName)

export function installIosViewportFix(): void {
  if (!isIos()) return
  const root = document.documentElement
  const vv = window.visualViewport

  // 1. Recalage des elements fixes du bas.
  let gapPx = 0
  const update = () => {
    // Clavier ouvert : ne rien deplacer (Safari gere seul l'affichage pendant la saisie).
    const gap = !vv || isField(document.activeElement) ? 0 : Math.round(vv.height + vv.offsetTop - window.innerHeight)
    const next = gap > 2 && gap < 700 ? gap : 0
    if (next !== gapPx) {
      gapPx = next
      root.style.setProperty('--ios-gap', `${next}px`)
    }
    diagnose()
  }

  // Diagnostic (une fois par ouverture) : barre du bas encore loin du bas de l'ecran visible, avec les mesures
  // de Safari. Aucune donnee personnelle ; sert a verifier la correction sur les vrais telephones.
  let reported = false
  const diagnose = () => {
    if (reported || !vv || isField(document.activeElement)) return
    const nav = document.querySelector('.bottom-nav')
    if (!nav || getComputedStyle(nav).display === 'none') return
    const bottom = nav.getBoundingClientRect().bottom
    if (vv.height - bottom > 40) {
      reported = true
      reportClientError('error', new Error(`Diagnostic iPhone : barre du bas a ${Math.round(vv.height - bottom)} px du bas `
        + `(innerHeight ${window.innerHeight}, visualViewport ${Math.round(vv.height)}+${Math.round(vv.offsetTop)}, correction ${gapPx} px)`))
    }
  }

  // 2. Micro-defilement (en deux temps : deux appels synchrones seraient fusionnes et sans effet).
  let timer: number | undefined
  const settle = () => {
    window.clearTimeout(timer)
    timer = window.setTimeout(() => {
      if (isField(document.activeElement)) return
      const x = window.scrollX
      const y = window.scrollY
      window.scrollTo(x, y > 0 ? y - 1 : y + 1)
      window.setTimeout(() => {
        window.scrollTo(x, y)
        update()
      }, 32)
    }, 250)
  }

  vv?.addEventListener('resize', () => { update(); settle() })
  vv?.addEventListener('scroll', update)
  window.addEventListener('resize', update)
  window.addEventListener('scroll', update, { passive: true })
  document.addEventListener('focusout', () => { settle(); window.setTimeout(update, 400) })
  document.addEventListener('focusin', () => window.setTimeout(update, 50))
  window.addEventListener('orientationchange', settle)
  window.addEventListener('pageshow', () => { update(); settle() })
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { update(); settle() } })
  update()
}
