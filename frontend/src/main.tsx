import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
// Polices servies par le site (pas d'appel a un service tiers, affichage non bloque).
import '@fontsource/inter/latin-300.css'
import '@fontsource/inter/latin-400.css'
import '@fontsource/inter/latin-500.css'
import '@fontsource/inter/latin-600.css'
import '@fontsource/inter/latin-700.css'
import '@fontsource/cormorant-garamond/latin-500.css'
import '@fontsource/cormorant-garamond/latin-600.css'
import '@fontsource/cormorant-garamond/latin-700.css'
import './index.css'
import './features.css'
import './toast.css'
import './mission.css'
import './growth.css'
import './appointments.css'
import './access.css'
import './guide.css'
import App from './App.tsx'
import { AuthProvider } from './auth/AuthContext'
import { ErrorBoundary } from './components/ErrorBoundary'
import { isStaleBuildError, reloadOnce } from './utils/reload'
import { installClientErrorReporting } from './monitoring'
import { installIosViewportFix } from './utils/iosViewport'
import { startAppUpdates } from './utils/appUpdate'

// Apres une mise a jour du site, un ancien ecran peut chercher un fichier qui n'existe plus :
// on recharge une fois pour obtenir la nouvelle version (au lieu d'une page blanche).
window.addEventListener('vite:preloadError', (e) => { e.preventDefault(); reloadOnce() })
window.addEventListener('unhandledrejection', (e) => { if (isStaleBuildError(e.reason)) reloadOnce() })
// Erreurs d'affichage remontees a la supervision (sans donnee personnelle).
installClientErrorReporting()
// iPhone : barre du bas et menu remis en place apres la fermeture du clavier.
installIosViewportFix()
// Nouvelle version en ligne : chargee au prochain changement de page ou au retour dans l'application.
startAppUpdates()

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <ErrorBoundary>
      <BrowserRouter>
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    </ErrorBoundary>
  </StrictMode>,
)

// PWA : enregistre le service worker (installation sur l'écran d'accueil + hors-ligne).
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => { /* silencieux */ })
  })
}
