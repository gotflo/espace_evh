import { api } from './client'
import type { Department, Gem, Tribe } from '../types'

export type ReferenceData = { tribes: Tribe[]; departments: Department[]; gems: Gem[] }

let cache: Promise<ReferenceData> | null = null

/**
 * Charge les tribus/departements une seule fois par session (memoise).
 * Evite de re-taper l'API a chaque ouverture d'un formulaire.
 */
export function getReference(): Promise<ReferenceData> {
  if (!cache) {
    cache = api<ReferenceData>('/reference').catch((err) => {
      cache = null // permet de reessayer apres un echec
      throw err
    })
  }
  return cache
}

/** A appeler apres une modification de l'organisation (tribus/departements). */
export function invalidateReference(): void {
  cache = null
}
