import { useEffect, useReducer } from 'react'

// Installation de l'application sur l'ecran d'accueil (PWA).
//
// Aucune page web ne peut s'installer toute seule : chaque navigateur exige un geste de la personne
// (Android : un appui sur « Installer » ; iPhone : Partager puis « Sur l'écran d'accueil »). On fait donc tout
// le reste : l'evenement d'installation d'Android est capte des le chargement (le bouton marche partout dans
// l'application), on reconnait le telephone et le navigateur pour montrer les bons gestes, et on fait sortir
// la personne des navigateurs integres (Facebook, Instagram...) ou l'installation est impossible.

export type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export type Platform = 'ios' | 'android' | 'desktop'
export type Browser = 'safari' | 'chrome' | 'samsung' | 'firefox' | 'edge' | 'opera' | 'other'

export interface Device {
  platform: Platform
  browser: Browser
  /** Navigateur integre a une application (Facebook, Instagram...) : installation impossible, il faut en sortir. */
  inApp: string | null
  /** iPad (bouton Partager en haut, et non en bas). */
  ipad: boolean
}

let deferred: BeforeInstallPromptEvent | null = null
let justInstalled = false
const listeners = new Set<() => void>()
const notify = () => listeners.forEach((fn) => fn())

const INSTALLED_KEY = 'evh_installed'

/** A appeler avant le premier rendu : Chrome envoie l'evenement tres tot, parfois avant React. */
export function initInstall(): void {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    deferred = e as BeforeInstallPromptEvent
    notify()
  })
  window.addEventListener('appinstalled', () => {
    deferred = null
    justInstalled = true
    try { localStorage.setItem(INSTALLED_KEY, String(Date.now())) } catch { /* stockage indisponible */ }
    notify()
  })
}

export function subscribeInstall(fn: () => void): () => void {
  listeners.add(fn)
  return () => { listeners.delete(fn) }
}

/** Le navigateur propose son bouton d'installation (Android, ordinateur) : un appui suffit. */
export function canPromptInstall(): boolean {
  return deferred !== null
}

export function wasJustInstalled(): boolean {
  return justInstalled
}

/** Fenetre d'installation du navigateur (a appeler dans un appui). */
export async function promptInstall(): Promise<'accepted' | 'dismissed' | 'unavailable'> {
  const event = deferred
  if (!event) return 'unavailable'
  try {
    await event.prompt()
    const choice = await event.userChoice
    // L'evenement ne sert qu'une fois ; Chrome en renverra un autre si la personne a refuse.
    deferred = null
    notify()
    return choice.outcome
  } catch {
    deferred = null
    notify()
    return 'unavailable'
  }
}

/** Ouverte depuis l'icone de l'ecran d'accueil (et non dans le navigateur). */
export function isStandalone(): boolean {
  try {
    return window.matchMedia('(display-mode: standalone)').matches
      || window.matchMedia('(display-mode: fullscreen)').matches
      || window.matchMedia('(display-mode: minimal-ui)').matches
      || (navigator as unknown as { standalone?: boolean }).standalone === true
      || document.referrer.startsWith('android-app://')
  } catch {
    return false
  }
}

const IN_APP: Array<[RegExp, string]> = [
  [/Instagram/i, 'Instagram'],
  [/FBAN\/Messenger|FB_IAB\/MESSENGER|MessengerLite/i, 'Messenger'],
  [/FBAN|FBAV|FB_IAB|FBIOS|FB4A/i, 'Facebook'],
  [/musical_ly|BytedanceWebview|TikTok|trill/i, 'TikTok'],
  [/Snapchat/i, 'Snapchat'],
  [/LinkedInApp/i, 'LinkedIn'],
  [/\bLine\//i, 'Line'],
  [/Telegram/i, 'Telegram'],
  [/WhatsApp/i, 'WhatsApp'],
  [/GSA\//i, 'Google'],
]

export function detectDevice(ua: string = navigator.userAgent, touchPoints: number = navigator.maxTouchPoints || 0): Device {
  // iPadOS se presente comme un Mac : on le reconnait a l'ecran tactile.
  const ipad = /iPad/i.test(ua) || (/Macintosh/i.test(ua) && touchPoints > 1)
  const ios = ipad || /iPhone|iPod/i.test(ua)
  const android = !ios && /Android/i.test(ua)
  const platform: Platform = ios ? 'ios' : android ? 'android' : 'desktop'

  let inApp: string | null = null
  for (const [re, name] of IN_APP) {
    if (re.test(ua)) { inApp = name; break }
  }
  // Vue web d'une application Android (« ; wv) ») ou d'une application iPhone (pas de « Safari/ » dans l'agent).
  if (!inApp && android && /;\s*wv\)/.test(ua)) inApp = 'une application'
  if (!inApp && ios && !/Safari\//.test(ua) && !/CriOS|FxiOS|EdgiOS|OPiOS/.test(ua)) inApp = 'une application'

  let browser: Browser = 'other'
  if (ios) {
    browser = /CriOS/.test(ua) ? 'chrome' : /FxiOS/.test(ua) ? 'firefox' : /EdgiOS/.test(ua) ? 'edge' : /OPiOS|OPT\//.test(ua) ? 'opera' : 'safari'
  } else if (/SamsungBrowser/i.test(ua)) browser = 'samsung'
  else if (/Firefox\//.test(ua)) browser = 'firefox'
  else if (/EdgA?\//.test(ua)) browser = 'edge'
  else if (/OPR\/|Opera/.test(ua)) browser = 'opera'
  else if (/Chrome\//.test(ua) && !/MiuiBrowser|HuaweiBrowser|HeyTapBrowser|UCBrowser|YaBrowser|VivoBrowser/i.test(ua)) browser = 'chrome'

  return { platform, browser, inApp, ipad }
}

/**
 * Lien qui ouvre la meme page dans le vrai navigateur : Chrome sur Android (lien « intent »), Safari sur
 * iPhone (iOS 17 et plus). Sert a sortir d'un navigateur integre, ou d'un navigateur Android sans installation.
 */
export function openInBrowserUrl(device: Device, href: string = window.location.href): string | null {
  const url = new URL(href)
  if (device.platform === 'android') {
    const fallback = encodeURIComponent(url.href)
    // Pas de « #... » dans l'adresse : le « # » ouvre la partie Intent du lien.
    return `intent://${url.host}${url.pathname}${url.search}#Intent;scheme=https;package=com.android.chrome;S.browser_fallback_url=${fallback};end`
  }
  if (device.platform === 'ios') return `x-safari-${url.href}`
  return null
}

/** Android (Chrome) : l'application est-elle deja installee sur ce telephone ? */
export async function installedOnThisDevice(): Promise<boolean> {
  try {
    const nav = navigator as unknown as { getInstalledRelatedApps?: () => Promise<unknown[]> }
    if (nav.getInstalledRelatedApps) {
      const apps = await nav.getInstalledRelatedApps()
      if (apps.length > 0) return true
    }
  } catch { /* non pris en charge */ }
  return false
}

/** Ouvre la fenetre « Installer l'application » (menu, guide...). */
export function openInstallSheet(): void {
  window.dispatchEvent(new CustomEvent('evh-install-open'))
}

/** Installation constatee dans ce navigateur (evenement « appinstalled »). */
export function installedHere(): boolean {
  try { return !!localStorage.getItem(INSTALLED_KEY) } catch { return false }
}

/** Etat de l'installation, mis a jour quand le navigateur propose (ou retire) son bouton. */
export function useInstallState() {
  const [, refresh] = useReducer((n: number) => n + 1, 0)
  useEffect(() => subscribeInstall(refresh), [])
  return { canPrompt: canPromptInstall(), justInstalled: wasJustInstalled() }
}
