import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { APP_NAME } from '../config'
import { toast } from '../toast'
import { de } from '../utils/french'
import {
  detectDevice, installedOnThisDevice, isStandalone, openInBrowserUrl, promptInstall, useInstallState, type Device,
} from '../utils/install'

// Pictogrammes des boutons a toucher, dessines comme sur le telephone.
const glyph = (children: ReactNode, label: string) => (
  <svg className="install-glyph" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"
    strokeLinecap="round" strokeLinejoin="round" role="img" aria-label={label}>{children}</svg>
)
const ShareGlyph = () => glyph(<><path d="M12 14V3" /><path d="M8 7l4-4 4 4" /><path d="M8 10H6v11h12V10h-2" /></>, 'Partager')
const DotsV = () => glyph(<><circle cx="12" cy="5" r="1.4" fill="currentColor" /><circle cx="12" cy="12" r="1.4" fill="currentColor" /><circle cx="12" cy="19" r="1.4" fill="currentColor" /></>, 'Menu')
const DotsH = () => glyph(<><circle cx="5" cy="12" r="1.4" fill="currentColor" /><circle cx="12" cy="12" r="1.4" fill="currentColor" /><circle cx="19" cy="12" r="1.4" fill="currentColor" /></>, 'Plus')
const Burger = () => glyph(<><path d="M4 7h16" /><path d="M4 12h16" /><path d="M4 17h16" /></>, 'Menu')
const AddGlyph = () => glyph(<><rect x="4" y="4" width="16" height="16" rx="4" /><path d="M12 8v8" /><path d="M8 12h8" /></>, 'Ajouter')

interface Step { title: ReactNode; text?: ReactNode }

function Steps({ steps }: { steps: Step[] }) {
  return (
    <ol className="install-steps">
      {steps.map((s, i) => (
        <li key={i}>
          <span className="install-step-num" aria-hidden>{i + 1}</span>
          <div><strong>{s.title}</strong>{s.text && <small>{s.text}</small>}</div>
        </li>
      ))}
    </ol>
  )
}

const iosSafariSteps = (ipad: boolean): Step[] => [
  { title: <>Touchez le bouton Partager <ShareGlyph /></>, text: <>{ipad ? 'En haut à droite de l’écran.' : 'En bas de l’écran.'} Vous ne le voyez pas ? Touchez d’abord <DotsH /> à côté de l’adresse.</> },
  { title: <>Touchez «&nbsp;Sur l’écran d’accueil&nbsp;» <AddGlyph /></>, text: 'Faites défiler la liste vers le bas si besoin.' },
  { title: 'Touchez «\u00a0Ajouter\u00a0»', text: 'En haut à droite. L’icône apparaît sur votre écran d’accueil.' },
  { title: `Ouvrez ${APP_NAME} depuis son icône`, text: 'Connectez-vous une fois dans l’application : elle est séparée de Safari.' },
]

const androidChromeSteps: Step[] = [
  { title: <>Touchez le menu <DotsV /></>, text: 'En haut à droite de Chrome.' },
  { title: '«\u00a0Installer l’application\u00a0» ou «\u00a0Ajouter à l’écran d’accueil\u00a0»', text: 'Puis confirmez avec «\u00a0Installer\u00a0».' },
  { title: `Ouvrez ${APP_NAME} depuis son icône`, text: 'Elle se trouve sur l’écran d’accueil ou parmi vos applications.' },
]

const androidSamsungSteps: Step[] = [
  { title: <>Touchez le menu <Burger /></>, text: 'En bas à droite de Samsung Internet.' },
  { title: '«\u00a0Ajouter la page à\u00a0», puis «\u00a0Écran d’accueil\u00a0»', text: 'Ou «\u00a0Installer\u00a0» si le choix apparaît.' },
  { title: `Ouvrez ${APP_NAME} depuis son icône`, text: 'Elle se trouve sur l’écran d’accueil.' },
]

const androidOtherSteps: Step[] = [
  { title: <>Touchez le menu du navigateur <DotsV /></>, text: 'En haut ou en bas à droite.' },
  { title: '«\u00a0Installer\u00a0» ou «\u00a0Ajouter à l’écran d’accueil\u00a0»', text: 'Puis confirmez.' },
  { title: `Ouvrez ${APP_NAME} depuis son icône`, text: 'Elle se trouve sur l’écran d’accueil.' },
]

async function copyLink(href: string) {
  try {
    await navigator.clipboard.writeText(href)
    toast.success('Lien copié : collez-le dans la barre d’adresse du navigateur.')
  } catch {
    window.prompt('Copiez ce lien, puis collez-le dans votre navigateur :', href)
  }
}

/** Bouton qui ouvre la page dans le vrai navigateur (Chrome sur Android, Safari sur iPhone). */
function OpenInBrowser({ device, label }: { device: Device; label: string }) {
  const href = openInBrowserUrl(device)
  if (!href) return null
  return <a className="btn btn-primary install-main" href={href}>{label}</a>
}

/**
 * Gestes a faire pour installer l'application, choisis selon le telephone et le navigateur. Un bouton suffit quand le
 * navigateur le permet (Android, ordinateur) ; sinon les etapes, et la sortie des navigateurs integres.
 */
export function InstallGuide({ onInstalled }: { onInstalled?: () => void }) {
  const device = useMemo(() => detectDevice(), [])
  const { canPrompt, justInstalled } = useInstallState()
  const [busy, setBusy] = useState(false)
  const [refused, setRefused] = useState(false)
  const [already, setAlready] = useState(false)
  const standalone = isStandalone()
  const href = window.location.origin + '/installer'

  useEffect(() => { if (device.platform === 'android') void installedOnThisDevice().then(setAlready) }, [device.platform])
  useEffect(() => { if (justInstalled) onInstalled?.() }, [justInstalled, onInstalled])

  async function install() {
    setBusy(true)
    const outcome = await promptInstall()
    setBusy(false)
    if (outcome === 'dismissed') setRefused(true)
  }

  if (standalone || justInstalled) {
    return (
      <div className="install-done">
        <span className="install-done-mark" aria-hidden>✓</span>
        <strong>{standalone ? `${APP_NAME} est installée sur cet appareil.` : 'C’est fait !'}</strong>
        <p className="helper">{standalone
          ? 'Ouvrez-la toujours depuis son icône pour recevoir les notifications.'
          : `L’icône ${APP_NAME} est sur votre écran d’accueil : ouvrez l’application depuis là désormais.`}</p>
      </div>
    )
  }

  const browserName = device.platform === 'ios' ? 'Safari' : 'Chrome'

  let body: ReactNode
  if (device.inApp) {
    // Facebook, Instagram... : aucune installation possible dans ces navigateurs.
    body = (
      <>
        <p className="install-lead">Vous êtes dans le navigateur {de(device.inApp)} : l’installation y est impossible. Ouvrez d’abord ce lien dans <strong>{browserName}</strong>.</p>
        <OpenInBrowser device={device} label={`Ouvrir dans ${browserName}`} />
        <Steps steps={device.platform === 'ios'
          ? [
            { title: <>Le bouton ne marche pas ? Touchez <DotsH /> ou l’icône du navigateur</>, text: 'En haut ou en bas de l’écran.' },
            { title: '«\u00a0Ouvrir dans Safari\u00a0» (ou «\u00a0Ouvrir dans le navigateur\u00a0»)', text: 'Les étapes d’installation s’afficheront dans Safari.' },
          ]
          : [
            { title: <>Le bouton ne marche pas ? Touchez le menu <DotsV /></>, text: 'En haut à droite.' },
            { title: '«\u00a0Ouvrir dans Chrome\u00a0» (ou «\u00a0Ouvrir dans le navigateur\u00a0»)', text: 'Les étapes d’installation s’afficheront dans Chrome.' },
          ]} />
        <button type="button" className="btn btn-ghost small" onClick={() => void copyLink(href)}>Copier le lien</button>
      </>
    )
  } else if (canPrompt) {
    // Android (Chrome, Samsung, Edge...) ou ordinateur : le navigateur installe en un appui.
    body = (
      <>
        <p className="install-lead">Un appui suffit : votre téléphone vous demandera de confirmer.</p>
        <button type="button" className="btn btn-primary install-main" onClick={() => void install()} disabled={busy}>
          {busy ? <span className="spinner" /> : 'Installer maintenant'}
        </button>
        <details className="install-more">
          <summary>Le bouton ne marche pas ?</summary>
          <Steps steps={device.browser === 'samsung' ? androidSamsungSteps : androidChromeSteps} />
        </details>
      </>
    )
  } else if (device.platform === 'ios') {
    body = device.browser === 'safari' ? (
      <>
        <p className="install-lead">Sur iPhone, l’installation se fait dans Safari, en quelques gestes :</p>
        <Steps steps={iosSafariSteps(device.ipad)} />
      </>
    ) : (
      <>
        <p className="install-lead">Sur iPhone, l’installation se fait depuis <strong>Safari</strong>.</p>
        <OpenInBrowser device={device} label="Ouvrir dans Safari" />
        <details className="install-more">
          <summary>Rester dans ce navigateur</summary>
          <Steps steps={[
            { title: <>Touchez le bouton Partager <ShareGlyph /></>, text: 'Dans la barre d’adresse ou dans le menu du navigateur.' },
            { title: '«\u00a0Sur l’écran d’accueil\u00a0» (ou «\u00a0Ajouter à l’écran d’accueil\u00a0»)', text: 'Puis «\u00a0Ajouter\u00a0».' },
            { title: `Ouvrez ${APP_NAME} depuis son icône`, text: 'Connectez-vous une fois dans l’application.' },
          ]} />
        </details>
        <button type="button" className="btn btn-ghost small" onClick={() => void copyLink(href)}>Copier le lien</button>
      </>
    )
  } else if (device.platform === 'android') {
    body = (
      <>
        {already && <p className="install-note">Elle semble déjà installée sur ce téléphone : cherchez l’icône {APP_NAME}, ou «&nbsp;Ouvrir dans l’application&nbsp;» dans le menu de Chrome.</p>}
        {device.browser === 'chrome' ? (
          <Steps steps={androidChromeSteps} />
        ) : (
          <>
            <p className="install-lead">Le plus simple : ouvrir ce lien dans <strong>Chrome</strong>, qui installe en un appui.</p>
            <OpenInBrowser device={device} label="Ouvrir dans Chrome" />
            <details className="install-more">
              <summary>Rester dans ce navigateur</summary>
              <Steps steps={device.browser === 'samsung' ? androidSamsungSteps : androidOtherSteps} />
            </details>
          </>
        )}
        {refused && <p className="install-note">Installation annulée. Vous pouvez la faire par le menu, comme ci-dessus.</p>}
      </>
    )
  } else {
    body = (
      <>
        <p className="install-lead">Sur ordinateur : dans Chrome ou Edge, cliquez sur l’icône d’installation à droite de la barre d’adresse.</p>
        <p className="helper">Sur votre téléphone, ouvrez <strong>{href.replace(/^https?:\/\//, '')}</strong> : les étapes s’afficheront.</p>
      </>
    )
  }

  return (
    <div className="install-guide">
      {body}
      {refused && canPrompt === false && device.platform !== 'android' && (
        <p className="install-note">Installation annulée. Vous pourrez la refaire à tout moment depuis le menu «&nbsp;Installer l’application&nbsp;».</p>
      )}
      <details className="install-more">
        <summary>Aider quelqu’un sur un autre téléphone</summary>
        <h4 className="install-sub">iPhone (Safari)</h4>
        <Steps steps={iosSafariSteps(false)} />
        <h4 className="install-sub">Android (Chrome)</h4>
        <Steps steps={androidChromeSteps} />
        <h4 className="install-sub">Samsung Internet</h4>
        <Steps steps={androidSamsungSteps} />
        <p className="helper">Lien à partager : <strong className="install-link">{href.replace(/^https?:\/\//, '')}</strong></p>
        <button type="button" className="btn btn-ghost small" onClick={() => void copyLink(href)}>Copier le lien</button>
      </details>
    </div>
  )
}
