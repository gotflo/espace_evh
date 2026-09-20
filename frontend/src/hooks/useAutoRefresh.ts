import { useEffect, useRef } from 'react'

/**
 * Quasi temps reel : rappelle `reload` a intervalle regulier et des que
 * l'application revient au premier plan (retour sur l'onglet / l'appli).
 * On ne rafraichit jamais en arriere-plan (economie de batterie et de requetes).
 * Le composant garde la main sur son propre chargement au montage.
 */
export function useAutoRefresh(reload: () => void, intervalMs = 20000, enabled = true) {
  const cbRef = useRef(reload)
  cbRef.current = reload

  useEffect(() => {
    if (!enabled) return
    const tick = () => { if (document.visibilityState === 'visible') cbRef.current() }
    const id = window.setInterval(tick, intervalMs)
    document.addEventListener('visibilitychange', tick)
    window.addEventListener('focus', tick)
    return () => {
      window.clearInterval(id)
      document.removeEventListener('visibilitychange', tick)
      window.removeEventListener('focus', tick)
    }
  }, [intervalMs, enabled])
}
