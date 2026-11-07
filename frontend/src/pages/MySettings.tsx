import { useEffect } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { AppLayout } from '../components/AppLayout'
import { Icon } from '../components/Icon'
import { InstallGuide } from '../components/InstallGuide'
import { NotificationPrefs } from '../components/NotificationPrefs'
import { PushSettings } from '../components/PushSettings'
import { APP_NAME } from '../config'
import type { IconName } from '../utils/icons'
import { isStandalone } from '../utils/install'

const LINKS: Array<{ to: string; icon: IconName; label: string; hint: string }> = [
  { to: '/mon-profil', icon: 'profile', label: 'Mon profil', hint: 'Photo, coordonnées, tribu, famille' },
  { to: '/notifications', icon: 'bell', label: 'Mes notifications', hint: 'Toutes les alertes reçues' },
  { to: '/guide', icon: 'help', label: 'Guide', hint: 'Comment utiliser l’application' },
  { to: '/contact', icon: 'requests', label: 'Nous contacter', hint: 'Une question, un besoin, une prière' },
]

/**
 * Paramètres du membre, au meme endroit : installer l'application sur cet appareil (toujours disponible, sans jamais
 * insister ailleurs), notifications de cet appareil et choix par sujet, liens du compte et deconnexion.
 */
export default function MySettings() {
  const { logout } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const standalone = isStandalone()

  // Lien direct (#application, #notifications) : la rubrique vient a l'ecran.
  useEffect(() => {
    const id = decodeURIComponent(location.hash.replace(/^#/, ''))
    if (!id) return
    requestAnimationFrame(() => document.getElementById(id)?.scrollIntoView({ block: 'start', behavior: 'smooth' }))
  }, [location.hash])

  async function onLogout() {
    await logout()
    navigate('/connexion', { replace: true })
  }

  return (
    <AppLayout title="Paramètres" subtitle="Application, notifications et compte">
      <div className="settings-grid">
        <section className="panel settings-app" id="application">
          <div className="panel-head">
            <h3>Application</h3>
            <span className={`status-pill ${standalone ? 'on' : ''}`}>{standalone ? 'Installée' : 'Dans le navigateur'}</span>
          </div>
          {!standalone && (
            <p className="helper settings-intro">
              Installez {APP_NAME} sur cet appareil : une icône sur l’écran d’accueil, l’ouverture en plein écran et les notifications.
            </p>
          )}
          <InstallGuide />
        </section>

        <div className="settings-col" id="notifications">
          <PushSettings />
          <NotificationPrefs defaultOpen />
        </div>

        <section className="panel settings-account">
          <div className="panel-head"><h3>Mon compte</h3></div>
          <ul className="settings-links">
            {LINKS.map((l) => (
              <li key={l.to}>
                <Link to={l.to} className="settings-link">
                  <span className="settings-link-icon"><Icon name={l.icon} size={18} /></span>
                  <span className="settings-link-text"><strong>{l.label}</strong><small>{l.hint}</small></span>
                  <Icon name="chevron" size={16} className="settings-link-chevron" />
                </Link>
              </li>
            ))}
          </ul>
          <button type="button" className="btn btn-ghost small mt" onClick={() => void onLogout()}>
            <Icon name="logout" size={16} /> Se déconnecter
          </button>
        </section>
      </div>
    </AppLayout>
  )
}
