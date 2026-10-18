import { reportClientError } from '../monitoring'

// iPhone / iPad (Safari 26 surtout) : apres le clavier, et parfois sans lui, Safari « decroche » le repere des elements
// fixes : la barre d'onglets du bas, le menu et les fenetres restent a l'endroit de la page ou ils etaient et partent
// avec le defilement (releves de production d'octobre : jusqu'a 3 969 px, sur 5 telephones). Trois protections :
// 1. la coque : dans l'application connectee, la page elle-meme ne defile plus, seul le cadre de l'application
//    (.app) defile (classe app-shell sur <html>, styles dans access.css). Sans defilement de la page, Safari n'a
//    plus rien a decrocher ;
// 2. apres le clavier, la page est remise tout en haut : avec la coque, c'est toujours sa bonne position
//    (sans coque, ecrans de connexion : micro-defilement qui force Safari a recalculer sa mise en page) ;
// 3. filet de securite : deux reperes fixes invisibles (en haut, en bas) mesurent ou Safari place reellement les
//    elements fixes ; un ecart restant est compense, sans plafond (variables CSS --ios-top et --ios-gap, 0 en temps
//    normal).
// Diagnostic (une fois par ouverture) : la compensation appliquee, et la barre encore mal placee si cela arrivait.

export function isIos(): boolean {
  return /iP(hone|ad|od)/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)
}

const isField = (el: Element | null) => !!el && (/^(INPUT|TEXTAREA|SELECT)$/.test(el.tagName) || (el as HTMLElement).isContentEditable)

/** Coque active : la page ne defile pas, le cadre de l'application defile (voir utils/pageScroll.ts). */
export function shellOn(): boolean {
  return document.documentElement.classList.contains('app-shell')
}

/** Active la coque tant que le cadre de l'application est affiche (iPhone / iPad). Rend la fonction qui la retire. */
export function enterAppShell(): () => void {
  if (!isIos()) return () => {}
  const root = document.documentElement
  window.scrollTo(0, 0) // un ecran precedent (connexion, profil) a pu laisser la fenetre defilee
  root.classList.add('app-shell')
  return () => root.classList.remove('app-shell')
}

export function installIosViewportFix(): void {
  if (!isIos()) return
  const root = document.documentElement
  const vv = window.visualViewport

  // 3. Reperes : ou Safari place un element fixe colle en haut, et un autre colle en bas.
  const probe = (edge: 'top' | 'bottom') => {
    const el = document.createElement('div')
    el.setAttribute('aria-hidden', 'true')
    el.style.cssText = `position:fixed;${edge}:0;left:0;width:1px;height:1px;visibility:hidden;pointer-events:none`
    document.body.appendChild(el)
    return el
  }
  const topProbe = probe('top')
  const bottomProbe = probe('bottom')

  // Ecart entre l'endroit visible et l'endroit ou Safari place les elements fixes (haut, bas), en pixels.
  // Clavier ouvert ou page zoomee : rien (Safari gere seul l'affichage pendant la saisie).
  const measure = (): [number, number] => {
    if (!vv || isField(document.activeElement) || Math.abs(vv.scale - 1) > 0.01) return [0, 0]
    // Garde-fou : jamais plus loin que la longueur de la page (ou du cadre de l'application, avec la coque).
    const appFrame = document.querySelector('#root > .app')
    const limit = Math.max(root.scrollHeight, appFrame ? appFrame.scrollHeight : 0, window.innerHeight) + vv.height
    const keep = (d: number) => (Math.abs(d) > 2 && Math.abs(d) < limit ? d : 0)
    return [
      keep(Math.round(-topProbe.getBoundingClientRect().top)),
      keep(Math.round(vv.height - bottomProbe.getBoundingClientRect().bottom)),
    ]
  }

  let topPx = 0
  let bottomPx = 0
  let frame = 0
  const update = () => {
    if (frame) return
    frame = requestAnimationFrame(() => {
      frame = 0
      const [top, bottom] = measure()
      if (top !== topPx) { topPx = top; root.style.setProperty('--ios-top', `${top}px`) }
      if (bottom !== bottomPx) { bottomPx = bottom; root.style.setProperty('--ios-gap', `${bottom}px`) }
      if (top || bottom) requestAnimationFrame(diagnose)
    })
  }

  // Diagnostic, sans donnee personnelle : sert a verifier la correction sur les vrais telephones.
  let toldCompensated = false
  let toldStillWrong = false
  const diagnose = () => {
    if (!vv || isField(document.activeElement)) return
    const facts = `coque ${shellOn() ? 'oui' : 'non'}, innerHeight ${window.innerHeight}, visualViewport `
      + `${Math.round(vv.height)}+${Math.round(vv.offsetTop)}, compensation haut ${topPx} px, bas ${bottomPx} px`
    if (!toldCompensated && Math.max(Math.abs(topPx), Math.abs(bottomPx)) >= 40) {
      toldCompensated = true
      reportClientError('error', new Error(`Diagnostic iPhone : decalage de Safari compense (${facts})`))
    }
    const nav = document.querySelector('.bottom-nav')
    if (toldStillWrong || !nav || getComputedStyle(nav).display === 'none') return
    const off = Math.round(vv.height - nav.getBoundingClientRect().bottom)
    if (Math.abs(off) > 40) {
      toldStillWrong = true
      reportClientError('error', new Error(`Diagnostic iPhone : barre du bas a ${off} px du bas apres correction (${facts})`))
    }
  }

  // 2. Apres le clavier (ou un retour dans l'application), en deux temps : deux defilements synchrones seraient
  // fusionnes par Safari et sans effet.
  let timer: number | undefined
  const settle = () => {
    window.clearTimeout(timer)
    timer = window.setTimeout(() => {
      if (isField(document.activeElement)) return
      if (shellOn()) {
        // Coque : la page doit toujours etre tout en haut ; on l'y remet (efface le decalage laisse par le clavier).
        if (window.scrollX || window.scrollY || (vv && Math.abs(vv.offsetTop) > 0.5)) window.scrollTo(0, 0)
        update()
        return
      }
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
  window.addEventListener('scroll', () => {
    update()
    // Coque : la page n'a aucune raison de defiler ; si Safari l'a fait (clavier ferme), on la remet en haut.
    if (shellOn() && window.scrollY && !isField(document.activeElement)) settle()
  }, { passive: true })
  document.addEventListener('focusout', () => { settle(); window.setTimeout(update, 400) })
  document.addEventListener('focusin', () => window.setTimeout(update, 50))
  window.addEventListener('orientationchange', settle)
  window.addEventListener('pageshow', () => { update(); settle() })
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') { update(); settle() } })
  update()
}
