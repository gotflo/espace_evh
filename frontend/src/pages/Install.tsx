import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { Brand } from '../components/Brand'
import { InstallGuide } from '../components/InstallGuide'
import { LoginBackdrop } from '../components/LoginBackdrop'
import { APP_NAME } from '../config'

/**
 * Page publique « /installer » : le lien a envoyer (WhatsApp, courriel) a quelqu'un qui n'arrive pas a installer
 * l'application. Elle montre les gestes de son telephone, sans avoir besoin d'etre connecte.
 */
export default function Install() {
  const { isAuthenticated } = useAuth()
  return (
    <div className="screen login-screen">
      <LoginBackdrop />
      <div className="card login-card install-page">
        <Brand subtitle={APP_NAME} />
        <h2 className="section-title">Installer l’application</h2>
        <p className="section-sub">Une icône sur votre écran d’accueil : l’application s’ouvre d’un geste, en plein écran, avec les notifications.</p>
        <InstallGuide />
        <Link className="btn btn-ghost mt" to={isAuthenticated ? '/tableau-de-bord' : '/connexion'}>
          {isAuthenticated ? 'Retour à mon espace' : 'Se connecter'}
        </Link>
      </div>
    </div>
  )
}
