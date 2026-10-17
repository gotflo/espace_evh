import type { MeditationField } from '../types'

/** Rubriques de la fiche « Méditation de la Parole de Dieu », dans l'ordre et avec les libellés demandés. */
export const MEDITATION_FIELDS: [MeditationField, string][] = [
  ['verse', 'Verset à méditer (écrire le ou les versets en entier)'],
  ['observation', 'Observation, interprétation et commentaires'],
  ['rhema', 'Rhéma reçu'],
  ['action', 'Action du jour à mener'],
  ['prayer', 'Ma prière'],
]

/** Longueur maximale d'une rubrique (même limite que le serveur). */
export const MEDITATION_MAX = 5000

/** « Fiche remplie le JJ/MM/AAAA » (date du téléphone, jamais le format AAAA-MM-JJ de fr-CA). */
export function filledOn(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return 'Fiche remplie'
  const pad = (n: number) => String(n).padStart(2, '0')
  return `Fiche remplie le ${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()}`
}
