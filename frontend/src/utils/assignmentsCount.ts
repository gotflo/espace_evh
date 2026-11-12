import { useEffect, useState } from 'react'
import { api, auth } from '../api/client'
import { usePulse } from '../pulse'

let current = 0
let loadedFor: string | null = null
let pending: Promise<void> | null = null
const listeners = new Set<(n: number) => void>()

function publish(n: number) {
  current = n
  loadedFor = auth.get()
  listeners.forEach((l) => l(current))
}

/** Nombre de devoirs a faire, deja connu (la page « Mon devoir » le recoit avec la liste) : pas de lecture en plus. */
export function setAssignmentsPending(n: number) {
  publish(Math.max(0, n))
}

/** Relit le nombre de devoirs a faire (une seule lecture a la fois). */
export function refreshAssignmentsCount(): Promise<void> {
  if (pending) return pending
  pending = api<{ pending: number }>('/me/assignments/count')
    .then((r) => publish(r.pending ?? 0))
    .catch(() => { /* hors ligne : la pastille garde sa derniere valeur */ })
    .finally(() => { pending = null })
  return pending
}

/**
 * Pastille rouge du menu « Mon devoir (DS) » : devoirs pas encore faits (memes regles que la page). Lue une fois,
 * puis relue quand le pouls signale un devoir publie ou modifie. Rien n'est lu si le module est coupe.
 */
export function useAssignmentsCount(enabled: boolean): number {
  const [n, setN] = useState(current)
  useEffect(() => {
    if (!enabled) return
    listeners.add(setN)
    if (loadedFor !== auth.get()) void refreshAssignmentsCount()
    else setN(current)
    return () => { listeners.delete(setN) }
  }, [enabled])
  usePulse('assignments', () => { void refreshAssignmentsCount() }, enabled)
  return enabled ? n : 0
}
