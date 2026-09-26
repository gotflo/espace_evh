import type { Department } from '../types'
import { MultiSelect } from './MultiSelect'

/** Selection des departements (liste deroulante avec recherche, pastilles, adaptee au mobile). */
export function DepartmentPicker({ departments, selected, onChange, disabled }: {
  departments: Department[]
  selected: number[]
  onChange: (ids: number[]) => void
  disabled?: boolean
}) {
  return (
    <MultiSelect
      label="Départements"
      placeholder="Choisir un ou plusieurs départements"
      searchPlaceholder="Rechercher un département…"
      emptyText="Aucun département trouvé"
      disabled={disabled}
      options={departments.map((d) => ({ value: String(d.id), label: d.name }))}
      value={selected.map(String)}
      onChange={(values) => onChange(values.map(Number))}
    />
  )
}
