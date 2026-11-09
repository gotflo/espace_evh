import { useEffect } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { AppLayout } from '../components/AppLayout'
import { Icon } from '../components/Icon'
import { InstallGuide } from '../components/InstallGuide'
import { NotificationPrefs } from '../components/NotificationPrefs'
import { PushSettings } from '../components/PushSettings'
import { APP_NAME } from '../config'
import { APP_VERSION, PLATFORM, versionsFor } from '../data/versions'
import type { ModuleName } from '../types'
import type { IconName } from '../utils/icons'
import { isStandalone } from '../utils/install'

interface SettingsLink { to: string; icon: IconName; label: string; hint: string; module?: ModuleName }

const ACCOUNT: SettingsLink[] = [
  { to: '/mon-profil', icon: 'profile', label: 'Mon profil', hint: 'Photo, coordonnées, tribu, famille' },
  { to: '/notifications', icon: 'bell', label: 'Mes notifications', hint: 'Toutes les alertes reçues' },
]

const HELP: SettingsLink[] = [
  { to: '/guide', icon: 'help', label: 'Guide', hint: 'Comment utiliser l’application, étape par étape, selon votre rôle', module: 'guide' },
  { to: '/contact', icon: 'requests', label: 'Nous contacter', hint: 'Une question, un besoin, une prière', module: 'contact' },
]

function LinkList({ links }: { links: SettingsLink[] }) {
  return (
    <ul className="settings-links">
      {links.map((l) => (
        <li key={l.to}>
          <Link to={l.to} className="settings-link">
            <span className="settings-link-icon"><Icon name={l.icon} size={18} /></span>
            <span className="settings-link-text"><strong>{l.label}</strong><small>{l.hint}</small></span>
            <Icon name="chevron" size={16} className="settings-link-chevron" />
          </Link>
        </li>
      ))}
    </ul>
  )
}

/**
 * Paramètres du membre, au meme endroit : installer l'application sur cet appareil (toujours disponible, sans jamais
 * insister ailleurs), notifications de cet appareil et choix par sujet, liens du compte, version de l'application et
 * ses nouveautes, politique de confidentialite. La deconnexion reste dans le menu (un seul bouton).
 */
export default function MySettings() {
  const { isPlatformManager, hasPermission, logout, moduleOn } = useAuth()
  // Aide : seulement les rubriques ouvertes (Guide, Nous contacter) ; la section disparait si les deux sont coupees.
  const help = HELP.filter((l) => !l.module || moduleOn(l.module))
  const navigate = useNavigate()
  // Nouveautes : seulement les lignes qui concernent cette personne (un fidele ne voit pas la gestion).
  const versions = versionsFor((k) => (k === PLATFORM ? isPlatformManager : hasPermission(k)))
  const location = useLocation()
  const standalone = isStandalone()

  async function onLogout() {
    await logout()
    navigate('/connexion', { replace: true })
  }

  // Lien direct (#application, #notifications) : la rubrique vient a l'ecran.
  useEffect(() => {
    const id = decodeURIComponent(location.hash.replace(/^#/, ''))
    if (!id) return
    requestAnimationFrame(() => document.getElementById(id)?.scrollIntoView({ block: 'start', behavior: 'smooth' }))
  }, [location.hash])

  return (
    <AppLayout title="Paramètres" subtitle="Application, notifications, aide et compte">
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
          {/* Le compte de gestion n'a pas d'espace membre : pas de choix par sujet, ni Guide, ni profil. */}
          {!isPlatformManager && <NotificationPrefs defaultOpen />}
        </div>

        {!isPlatformManager && help.length > 0 && (
          <section className="panel settings-account" id="aide">
            <div className="panel-head"><h3>Aide</h3></div>
            <LinkList links={help} />
          </section>
        )}

        {/* Mon compte : la deconnexion est ici, pour tous (un seul bouton dans l'application). */}
        <section className="panel settings-account">
          <div className="panel-head"><h3>Mon compte</h3></div>
          {!isPlatformManager && <LinkList links={ACCOUNT} />}
          <button type="button" className="btn btn-ghost small mt settings-logout" onClick={() => void onLogout()}>
            <Icon name="logout" size={16} /> Déconnexion
          </button>
        </section>

        {/* Version de l'application : elle se met a jour toute seule ; ce qui a change, et la confidentialite. */}
        <section className="panel settings-about" id="a-propos">
          <div className="panel-head"><h3>À propos</h3></div>
          <p className="settings-version"><strong>Version {APP_VERSION}</strong></p>
          <LinkList links={[{ to: '/confidentialite', icon: 'lock', label: 'Politique de confidentialité', hint: 'Vos renseignements, qui les voit, vos droits' }]} />
          <h4 className="settings-news-title">Nouveautés</h4>
          <ol className="settings-news">
            {versions.map((v, i) => (
              <li key={v.version}>
                <details open={i === 0}>
                  <summary>
                    <Icon name="chevron" size={16} className="settings-news-chevron" />
                    <strong className="settings-news-head">Version {v.version}</strong>
                  </summary>
                  <ul>{v.items.map((it) => <li key={it}>{it}</li>)}</ul>
                </details>
              </li>
            ))}
          </ol>
        </section>
      </div>
    </AppLayout>
  )
}
