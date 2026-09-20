import { useEffect, useState } from 'react'

type BeforeInstallPromptEvent = Event & {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

const DISMISS_KEY = 'evh_install_dismissed'

function isIos(): boolean {
  return /iphone|ipad|ipod/i.test(navigator.userAgent)
}
function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches
    || (navigator as unknown as { standalone?: boolean }).standalone === true
}

/** Bandeau d'invitation a installer la PWA (Android : bouton natif ; iOS : instructions). */
export function InstallPrompt() {
  const [deferred, setDeferred] = useState<BeforeInstallPromptEvent | null>(null)
  const [show, setShow] = useState(false)

  useEffect(() => {
    if (isStandalone() || localStorage.getItem(DISMISS_KEY)) return

    const onBip = (e: Event) => {
      e.preventDefault()
      setDeferred(e as BeforeInstallPromptEvent)
      setShow(true)
    }
    window.addEventListener('beforeinstallprompt', onBip)

    // iOS ne declenche pas beforeinstallprompt : on affiche l'astuce apres un court delai.
    let t: number | undefined
    if (isIos()) t = window.setTimeout(() => setShow(true), 1500)

    return () => {
      window.removeEventListener('beforeinstallprompt', onBip)
      if (t) clearTimeout(t)
    }
  }, [])

  function dismiss() {
    setShow(false)
    localStorage.setItem(DISMISS_KEY, '1')
  }

  async function install() {
    if (!deferred) return
    await deferred.prompt()
    await deferred.userChoice
    setDeferred(null)
    setShow(false)
  }

  if (!show) return null

  return (
    <div className="install-banner" role="dialog" aria-label="Installer l'application">
      <img src="/icon-192.png" alt="" className="install-icon" />
      <div className="install-text">
        <strong>Installer l'application</strong>
        {isIos()
          ? <small>Appuyez sur Partager puis « Sur l'ecran d'accueil ».</small>
          : <small>Acces rapide depuis votre ecran d'accueil.</small>}
      </div>
      {!isIos() && deferred && (
        <button className="btn btn-primary small" onClick={install}>Installer</button>
      )}
      <button className="install-close" onClick={dismiss} aria-label="Fermer">×</button>
    </div>
  )
}
