const RELOAD_KEY = 'evh_reloaded_at'

/** Fichier de code introuvable : une nouvelle version a ete mise en ligne depuis l'ouverture de l'app. */
export function isStaleBuildError(error: unknown): boolean {
  const msg = error instanceof Error ? `${error.name} ${error.message}` : String(error)
  return /dynamically imported module|Importing a module script failed|ChunkLoadError|Loading chunk|preload/i.test(msg)
}

/** Recharge la page une seule fois par minute (evite toute boucle de rechargement). */
export function reloadOnce(): boolean {
  try {
    const last = Number(sessionStorage.getItem(RELOAD_KEY) || 0)
    if (Date.now() - last < 60000) return false
    sessionStorage.setItem(RELOAD_KEY, String(Date.now()))
  } catch { /* stockage indisponible : on recharge quand meme */ }
  window.location.reload()
  return true
}
