import { useEffect, useMemo, useState } from 'react'
import { useLocation } from 'react-router-dom'
import { APP_NAME } from '../config'
import { detectDevice, installedOnThisDevice, isStandalone, promptInstall, useInstallState } from '../utils/install'
import { InstallGuide } from './InstallGuide'
import { ModalLayer } from './ModalLayer'

/** Ancien reglage : « × » cachait le bandeau pour toujours. Repris une seule fois comme une premiere fermeture. */
const OLD_DISMISS_KEY = 'evh_install_dismissed'
/** Bandeau : { until: date de retour possible, closes: nombre de fermetures }. */
const BANNER_KEY = 'evh_install_banner'

/** Apres une fermeture, le bandeau ne revient pas avant un mois. */
const PAUSE_MS = 30 * 24 * 3600_000
/** Ferme deux fois : il ne revient plus de lui-meme (l'installation reste dans Paramètres). */
const MAX_CLOSES = 2

interface BannerState { until: number; closes: number }

function readBanner(): BannerState {
  try {
    if (localStorage.getItem(OLD_DISMISS_KEY)) {
      // Ferme avec l'ancien bandeau : il revient une fois (nouvelles etapes d'installation), puis la regle normale.
      localStorage.removeItem(OLD_DISMISS_KEY)
      const migrated = { until: 0, closes: 1 }
      localStorage.setItem(BANNER_KEY, JSON.stringify(migrated))
      return migrated
    }
    const saved = JSON.parse(localStorage.getItem(BANNER_KEY) || 'null') as BannerState | null
    return { until: Number(saved?.until) || 0, closes: Number(saved?.closes) || 0 }
  } catch {
    return { until: 0, closes: 0 }
  }
}

/**
 * Invitation discrete a installer l'application, sans jamais insister :
 * - un petit bandeau, sur l'Accueil seulement, sur un telephone ou elle n'est pas installee ;
 * - « × » le cache un mois ; ferme deux fois, il ne revient plus de lui-meme ;
 * - sur iPhone, pas de bandeau : l'invitation aux notifications explique deja comment l'installer ;
 * - l'installation reste toujours disponible dans Menu > Paramètres (et la page publique /installer) ;
 * - la fenetre des etapes ne s'ouvre jamais toute seule : seulement sur un appui (evenement evh-install-open).
 */
export function InstallPrompt() {
  const device = useMemo(() => detectDevice(), [])
  const { pathname } = useLocation()
  const { canPrompt, justInstalled } = useInstallState()
  const [open, setOpen] = useState(false)
  const [banner, setBanner] = useState<BannerState>(readBanner)
  const [installed, setInstalled] = useState(false)
  const standalone = isStandalone()
  const mobile = device.platform !== 'desktop'

  useEffect(() => {
    if (mobile && !standalone) void installedOnThisDevice().then((yes) => { if (yes) setInstalled(true) })
  }, [mobile, standalone])

  // Ouverture a la demande seulement (bandeau, invitation aux notifications).
  useEffect(() => {
    const onOpen = () => setOpen(true)
    window.addEventListener('evh-install-open', onOpen)
    return () => window.removeEventListener('evh-install-open', onOpen)
  }, [])

  function closeBanner() {
    const next = { until: Date.now() + PAUSE_MS, closes: banner.closes + 1 }
    try { localStorage.setItem(BANNER_KEY, JSON.stringify(next)) } catch { /* stockage indisponible */ }
    setBanner(next)
  }

  async function bannerInstall() {
    // Android : la fenetre du telephone directement ; sinon les etapes.
    if (canPrompt) {
      const outcome = await promptInstall()
      if (outcome !== 'accepted') setOpen(true)
    } else {
      setOpen(true)
    }
  }

  if (standalone) return null

  const showBanner = mobile && !open && !installed && !justInstalled
    && pathname === '/tableau-de-bord'
    && (device.platform !== 'ios' || device.inApp !== null)
    && banner.closes < MAX_CLOSES && banner.until <= Date.now()

  return (
    <>
      {showBanner && (
        <div className="install-banner" role="dialog" aria-label={`Installer ${APP_NAME}`}>
          <img src="/icon-192.png" alt="" className="install-icon" />
          <div className="install-text">
            <strong>Installer {APP_NAME}</strong>
            <small>{device.inApp
              ? `Ouvrez ce lien dans ${device.platform === 'ios' ? 'Safari' : 'Chrome'} pour installer l’application.`
              : 'Une icône sur votre écran d’accueil, et les notifications.'}</small>
          </div>
          <button className="btn btn-primary small" onClick={() => void bannerInstall()}>Installer</button>
          <button className="install-close" onClick={closeBanner} aria-label="Fermer">×</button>
        </div>
      )}
      {open && (
        <ModalLayer className="modal-overlay install-overlay" onClick={() => setOpen(false)}>
          <div className="modal-box install-sheet" role="dialog" aria-modal="true" aria-labelledby="install-title" onClick={(e) => e.stopPropagation()}>
            <div className="install-sheet-head">
              <img src="/icon-192.png" alt="" className="install-sheet-icon" />
              <div>
                <h3 id="install-title">Installer {APP_NAME}</h3>
                <p className="helper">Une icône sur votre écran d’accueil : l’application s’ouvre d’un geste, en plein écran, avec les notifications.</p>
              </div>
            </div>
            <InstallGuide />
            <div className="install-sheet-actions">
              <p className="helper">Toujours disponible dans Menu &gt; Paramètres.</p>
              <button type="button" className="btn btn-ghost" onClick={() => setOpen(false)}>Fermer</button>
            </div>
          </div>
        </ModalLayer>
      )}
    </>
  )
}
