import { useEffect, useRef, useState } from 'react'

/** Vrai seulement quand l'app tourne en mode installe (ecran d'accueil). */
function isStandalone(): boolean {
  return window.matchMedia('(display-mode: standalone)').matches
    || (window.navigator as unknown as { standalone?: boolean }).standalone === true
}

/**
 * Tirer vers le bas pour recharger, comme les applications natives.
 * Actif uniquement en mode installe : dans un navigateur classique, le geste
 * natif existe deja, on ne le double pas.
 */
export function PullToRefresh() {
  const [pull, setPull] = useState(0)
  const [refreshing, setRefreshing] = useState(false)
  const pullRef = useRef(0)
  const startY = useRef(0)
  const active = useRef(false)
  const busy = useRef(false)

  useEffect(() => {
    if (!isStandalone()) return
    const THRESHOLD = 72
    const MAX = 110
    const set = (v: number) => { pullRef.current = v; setPull(v) }

    const onStart = (e: TouchEvent) => {
      if (window.scrollY > 3 || busy.current) { active.current = false; return }
      startY.current = e.touches[0].clientY
      active.current = true
    }
    const onMove = (e: TouchEvent) => {
      if (!active.current) return
      const dy = e.touches[0].clientY - startY.current
      if (dy <= 0) { if (pullRef.current) set(0); return }
      set(Math.min(MAX, dy * 0.5)) // resistance pour un rendu naturel
    }
    const onEnd = () => {
      if (!active.current) return
      active.current = false
      if (pullRef.current >= THRESHOLD) {
        busy.current = true
        setRefreshing(true)
        set(THRESHOLD)
        setTimeout(() => window.location.reload(), 350)
      } else {
        set(0)
      }
    }

    document.addEventListener('touchstart', onStart, { passive: true })
    document.addEventListener('touchmove', onMove, { passive: true })
    document.addEventListener('touchend', onEnd)
    return () => {
      document.removeEventListener('touchstart', onStart)
      document.removeEventListener('touchmove', onMove)
      document.removeEventListener('touchend', onEnd)
    }
  }, [])

  const progress = Math.min(1, pull / 72)
  const visible = pull > 4 || refreshing

  return (
    <div
      className="ptr"
      style={{ transform: `translateY(${pull - 46}px)`, opacity: visible ? 1 : 0 }}
      aria-hidden={!visible}
    >
      <div className={`ptr-circle ${refreshing ? 'spin' : ''}`}
        style={refreshing ? undefined : { transform: `rotate(${progress * 270}deg)` }}>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          strokeWidth="2.4" strokeLinecap="round" strokeLinejoin="round">
          <path d="M21 12a9 9 0 1 1-2.64-6.36" />
          {!refreshing && <path d="M21 3v6h-6" />}
        </svg>
      </div>
    </div>
  )
}
