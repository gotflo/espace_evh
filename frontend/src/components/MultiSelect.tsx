import { useEffect, useId, useMemo, useRef, useState, type KeyboardEvent } from 'react'

export interface MultiOption { value: string; label: string; group?: string; hint?: string }

/**
 * Selection multiple moderne : pastilles, recherche, groupes, clavier (fleches, Entree, Echap).
 * Sur telephone, la liste s'ouvre en panneau par le bas (plein ecran utile, gros boutons).
 */
export function MultiSelect({ options, value, onChange, placeholder = 'Choisir…', searchPlaceholder = 'Rechercher…', disabled, emptyText = 'Aucun résultat', label }: {
  options: MultiOption[]
  value: string[]
  onChange: (values: string[]) => void
  placeholder?: string
  searchPlaceholder?: string
  disabled?: boolean
  emptyText?: string
  label?: string
}) {
  const [open, setOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [active, setActive] = useState(0)
  const rootRef = useRef<HTMLDivElement>(null)
  const searchRef = useRef<HTMLInputElement>(null)
  const listId = useId()

  const selected = useMemo(() => new Set(value), [value])
  const q = query.trim().toLowerCase()
  const filtered = useMemo(() => options.filter((o) => !q || o.label.toLowerCase().includes(q) || (o.group ?? '').toLowerCase().includes(q)), [options, q])

  useEffect(() => {
    if (!open) return
    setActive(0)
    const t = window.setTimeout(() => searchRef.current?.focus(), 30)
    const onDoc = (e: MouseEvent) => { if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', onDoc)
    // Telephone : pas de defilement de la page derriere le panneau.
    const mobile = window.matchMedia('(max-width: 640px)').matches
    if (mobile) document.body.style.overflow = 'hidden'
    return () => { window.clearTimeout(t); document.removeEventListener('mousedown', onDoc); if (mobile) document.body.style.overflow = '' }
  }, [open])

  function toggle(v: string) {
    onChange(selected.has(v) ? value.filter((x) => x !== v) : [...value, v])
  }

  function onKey(e: KeyboardEvent) {
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive((a) => Math.min(filtered.length - 1, a + 1)) }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive((a) => Math.max(0, a - 1)) }
    else if (e.key === 'Enter') { e.preventDefault(); if (filtered[active]) toggle(filtered[active].value) }
    else if (e.key === 'Escape') { e.preventDefault(); setOpen(false) }
  }

  const byValue = useMemo(() => new Map(options.map((o) => [o.value, o])), [options])
  let lastGroup: string | undefined

  return (
    <div className={`ms ${open ? 'is-open' : ''} ${disabled ? 'is-disabled' : ''}`} ref={rootRef}>
      <div className="ms-trigger" role="combobox" aria-expanded={open} aria-controls={listId} aria-haspopup="listbox"
        aria-label={label} tabIndex={disabled ? -1 : 0}
        onClick={() => !disabled && setOpen(true)}
        onKeyDown={(e) => { if (!disabled && (e.key === 'Enter' || e.key === ' ' || e.key === 'ArrowDown')) { e.preventDefault(); setOpen(true) } }}>
        {value.length === 0 && <span className="ms-placeholder">{placeholder}</span>}
        {value.map((v) => (
          <span key={v} className="ms-chip">
            {byValue.get(v)?.label ?? v}
            {!disabled && (
              <button type="button" aria-label={`Retirer ${byValue.get(v)?.label ?? ''}`}
                onClick={(e) => { e.stopPropagation(); toggle(v) }}>×</button>
            )}
          </span>
        ))}
        <svg className="ms-chevron" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.2" strokeLinecap="round"><path d="m6 9 6 6 6-6" /></svg>
      </div>

      {open && (
        <>
          <div className="ms-backdrop" onClick={() => setOpen(false)} aria-hidden />
          <div className="ms-panel">
            <div className="ms-sheet-head">
              <strong>{label ?? placeholder}</strong>
              <button type="button" className="btn btn-primary small" onClick={() => setOpen(false)}>Terminé</button>
            </div>
            <div className="ms-search">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.5-3.5" /></svg>
              <input ref={searchRef} value={query} onChange={(e) => { setQuery(e.target.value); setActive(0) }} onKeyDown={onKey}
                placeholder={searchPlaceholder} aria-controls={listId} aria-autocomplete="list" />
              {value.length > 0 && <button type="button" className="btn-link" onClick={() => onChange([])}>Effacer</button>}
            </div>
            <ul className="ms-list" id={listId} role="listbox" aria-multiselectable="true">
              {filtered.map((o, i) => {
                const header = o.group && o.group !== lastGroup ? o.group : null
                lastGroup = o.group
                const on = selected.has(o.value)
                return (
                  <li key={o.value} role="presentation">
                    {header && <div className="ms-group">{header}</div>}
                    <div role="option" aria-selected={on} className={`ms-option ${on ? 'on' : ''} ${i === active ? 'active' : ''}`}
                      onMouseEnter={() => setActive(i)} onClick={() => toggle(o.value)}>
                      <span className="ms-check" aria-hidden>{on ? '✓' : ''}</span>
                      <span className="ms-option-label">{o.label}</span>
                      {o.hint && <span className="ms-hint">{o.hint}</span>}
                    </div>
                  </li>
                )
              })}
              {filtered.length === 0 && <li className="ms-empty">{emptyText}</li>}
            </ul>
            <div className="ms-foot">{value.length} sélectionné(s)</div>
          </div>
        </>
      )}
    </div>
  )
}
