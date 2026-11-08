import { useEffect, useState } from 'react'
import { api, auth } from '../api/client'
import { usePulse } from '../pulse'

/** Demandes que l'utilisateur peut traiter, en tout et par sorte (services, fiss, vertumetre, tribes, deletions, memberships). */
export interface ValidationsCount { count: number; by: Record<string, number> }

const EMPTY: ValidationsCount = { count: 0, by: {} }

/** Meme regle que le lien « Validations » du menu : quelqu'un qui peut traiter au moins une sorte de demande. */
export function canValidateWith(hasPermission: (key: string) => boolean): boolean {
  return hasPermission('fiss.review') || hasPermission('tribes.transfer') || hasPermission('members.approve') || hasPermission('departments.approve')
}
let current: ValidationsCount = EMPTY
let loadedFor: string | null = null
let pending: Promise<void> | null = null
const listeners = new Set<(c: ValidationsCount) => void>()

/** Relit le nombre de demandes en attente (une seule lecture a la fois, partagee par le menu et l'accueil). */
export function refreshValidationsCount(): Promise<void> {
  if (pending) return pending
  const token = auth.get()
  pending = api<ValidationsCount>('/admin/validations/count')
    .then((c) => { current = { count: c.count ?? 0, by: c.by ?? {} }; loadedFor = token; listeners.forEach((l) => l(current)) })
    .catch(() => { /* hors ligne : la pastille garde sa derniere valeur */ })
    .finally(() => { pending = null })
  return pending
}

/**
 * Pastille rouge du menu « Validations » et bandeau de l'accueil : lu une fois, puis relu des que le pouls
 * signale une demande nouvelle ou traitee (par n'importe qui). Rien n'est lu pour qui ne valide rien.
 */
export function useValidationsCount(enabled: boolean): ValidationsCount {
  const [c, setC] = useState<ValidationsCount>(current)
  useEffect(() => {
    if (!enabled) return
    listeners.add(setC)
    if (loadedFor !== auth.get()) void refreshValidationsCount()
    else setC(current)
    return () => { listeners.delete(setC) }
  }, [enabled])
  usePulse('validations', () => { void refreshValidationsCount() }, enabled)
  return enabled ? c : EMPTY
}

const LABELS: Record<string, [string, string]> = {
  services: ['inscription à un service', 'inscriptions aux services'],
  memberships: ['nouveau compte', 'nouveaux comptes'],
  tribes: ['changement de tribu', 'changements de tribu'],
  fiss: ['modification de FISS', 'modifications de FISS'],
  vertumetre: ['modification du Vertumètre', 'modifications du Vertumètre'],
  deletions: ['suppression de compte', 'suppressions de compte'],
}

/** « 3 inscriptions aux services · 1 changement de tribu » */
export function validationsSummary(c: ValidationsCount): string {
  return Object.entries(LABELS)
    .filter(([key]) => (c.by[key] ?? 0) > 0)
    .map(([key, [one, many]]) => `${c.by[key]} ${c.by[key] > 1 ? many : one}`)
    .join(' · ')
}
