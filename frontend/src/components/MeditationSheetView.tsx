import type { MeditationSheet } from '../types'
import { MEDITATION_FIELDS } from '../utils/meditation'

/** Contenu d'une fiche de méditation (lecture seule) : les cinq rubriques, textes tels qu'écrits. */
export function MeditationSheetView({ sheet }: { sheet: MeditationSheet }) {
  return (
    <ul className="rm-list medit-sheet">
      {MEDITATION_FIELDS.map(([key, label]) => (
        <li key={key} className="rm-item">
          <span className="rm-item-label">{label}</span>
          <p>{sheet[key]}</p>
        </li>
      ))}
    </ul>
  )
}
