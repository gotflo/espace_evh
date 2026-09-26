import { useEffect, useState } from 'react'

/** Bandeau discret quand l'appareil perd la connexion, puis confirmation au retour. */
export function OfflineBanner() {
  const [online, setOnline] = useState(() => (typeof navigator === 'undefined' ? true : navigator.onLine))
  const [back, setBack] = useState(false)

  useEffect(() => {
    let timer: number | undefined
    const up = () => { setOnline(true); setBack(true); window.clearTimeout(timer); timer = window.setTimeout(() => setBack(false), 3000) }
    const down = () => { setOnline(false); setBack(false) }
    window.addEventListener('online', up)
    window.addEventListener('offline', down)
    return () => { window.removeEventListener('online', up); window.removeEventListener('offline', down); window.clearTimeout(timer) }
  }, [])

  if (online && !back) return null
  return (
    <div className={`offline-banner ${online ? 'is-back' : ''}`} role="status" aria-live="polite">
      {online
        ? 'Connexion rétablie.'
        : 'Vous êtes hors ligne. Ce que vous écrivez est conservé sur l’appareil ; vous pourrez l’envoyer au retour de la connexion.'}
    </div>
  )
}
