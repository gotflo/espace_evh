import type { Department } from '../types'

/** Selection de plusieurs departements sous forme de pastilles a cocher. */
export function DepartmentPicker({ departments, selected, onChange }: {
  departments: Department[]
  selected: number[]
  onChange: (ids: number[]) => void
}) {
  const toggle = (id: number) =>
    onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id])

  return (
    <div className="chip-picker">
      {departments.map((d) => (
        <button
          type="button" key={d.id}
          className={`chip-toggle ${selected.includes(d.id) ? 'on' : ''}`}
          onClick={() => toggle(d.id)}
        >
          {d.name}
        </button>
      ))}
      {departments.length === 0 && <p className="helper">Aucun departement.</p>}
    </div>
  )
}
