/**
 * Lien d'un courriel ou d'une notification ouvert sans etre connecte : la page demandee est gardee,
 * puis rouverte juste apres la connexion (au lieu du tableau de bord).
 */
const KEY = 'evh:after-login'

export function rememberDestination(path: string): void {
  try {
    if (path.startsWith('/') && !path.startsWith('//') && path !== '/' && !path.startsWith('/connexion') && !path.startsWith('/tableau-de-bord')) {
      sessionStorage.setItem(KEY, path)
    }
  } catch { /* navigation privee */ }
}

export function takeDestination(): string | null {
  try {
    const path = sessionStorage.getItem(KEY)
    sessionStorage.removeItem(KEY)
    return path && path.startsWith('/') && !path.startsWith('//') ? path : null
  } catch { return null }
}
