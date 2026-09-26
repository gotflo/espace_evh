import { useEffect, useState } from 'react'
import { api } from '../api/client'
import type { AudienceOptions, AudienceScope } from '../types'
import { MultiSelect } from './MultiSelect'

let cache: AudienceOptions | null = null

/** Destinataires autorises pour l'utilisateur (charges une fois). */
export function useAudienceOptions(): AudienceOptions | null {
  const [opts, setOpts] = useState<AudienceOptions | null>(cache)
  useEffect(() => {
    if (cache) return
    api<AudienceOptions>('/admin/audiences').then((o) => { cache = o; setOpts(o) }).catch(() => setOpts({ church: false, tribes: [], gems: [], departments: [] }))
  }, [])
  return opts
}

/** Selection par defaut : toute l'eglise si autorise, sinon toutes ses tribus (ou GEMs / departements). */
export function defaultScopes(o: AudienceOptions): AudienceScope[] {
  if (o.church) return [{ type: 'church', id: null }]
  if (o.tribes.length) return o.tribes.map((t) => ({ type: 'tribe', id: t.id }))
  if (o.gems.length) return o.gems.map((g) => ({ type: 'gem', id: g.id }))
  return o.departments.map((d) => ({ type: 'department', id: d.id }))
}

const key = (s: AudienceScope) => `${s.type}:${s.id ?? ''}`

/**
 * Portee d'une publication : toute l'eglise (si permis) ou une selection de tribus, GEMs et
 * departements. Seules les cibles autorisees sont proposees (le serveur verifie aussi).
 */
export function AudiencePicker({ value, onChange }: { value: AudienceScope[]; onChange: (s: AudienceScope[]) => void }) {
  const opts = useAudienceOptions()

  useEffect(() => {
    if (opts && value.length === 0) onChange(defaultScopes(opts))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [opts])

  if (!opts) return <div className="field"><label>Destinataires</label><div className="skeleton" style={{ height: 46 }} /></div>

  const church = value.some((s) => s.type === 'church')
  const tribeName = (id: number) => opts.tribes.find((t) => t.id === id)?.name
  const options = [
    ...opts.tribes.map((t) => ({ value: `tribe:${t.id}`, label: t.name, group: 'Tribus' })),
    ...opts.gems.map((g) => ({ value: `gem:${g.id}`, label: g.name, group: 'GEMs', hint: tribeName(g.tribe_id) })),
    ...opts.departments.map((d) => ({ value: `department:${d.id}`, label: d.name, group: 'Départements' })),
  ]
  const nothing = !opts.church && options.length === 0

  return (
    <div className="field audience">
      <label>Destinataires</label>
      {opts.church && (
        <div className="seg small" role="radiogroup" aria-label="Portée">
          <button type="button" role="radio" aria-checked={church} className={church ? 'on' : ''}
            onClick={() => onChange([{ type: 'church', id: null }])}>⛪ Toute l'église</button>
          <button type="button" role="radio" aria-checked={!church} className={!church ? 'on' : ''}
            onClick={() => onChange(church ? [] : value)}>🎯 Choisir des groupes</button>
        </div>
      )}
      {!church && !nothing && (
        <MultiSelect
          label="Destinataires"
          placeholder="Tribus, GEMs ou départements…"
          searchPlaceholder="Rechercher une tribu, un GEM, un département…"
          options={options}
          value={value.filter((s) => s.type !== 'church').map(key)}
          onChange={(values) => onChange(values.map((v) => {
            const [type, id] = v.split(':')
            return { type: type as AudienceScope['type'], id: Number(id) }
          }))}
        />
      )}
      {nothing && <div className="scope-note">Vous n'avez pas de périmètre de diffusion. Demandez le rôle « Communication (toute l'église) ».</div>}
      {!opts.church && !nothing && <p className="helper">Seuls les groupes de votre périmètre sont proposés. Plusieurs choix possibles.</p>}
    </div>
  )
}
