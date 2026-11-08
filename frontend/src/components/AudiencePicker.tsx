import { useEffect, useState } from 'react'
import { api, auth } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import type { AudienceOptions, AudienceScope } from '../types'
import { MultiSelect } from './MultiSelect'

// Memoire propre a la session : cle = jeton + roles + type de publication. Un autre compte (ou des roles
// modifies) ne recoit jamais les destinataires du precedent.
const cache = new Map<string, AudienceOptions>()

/** Type de publication : chaque droit vaut dans la portee du role qui le donne (rien ne se melange). */
export type AudienceKind = 'announcements' | 'events' | 'exercises'

/** Destinataires autorises pour ce type de publication (charges une fois par session et par jeu de roles). */
export function useAudienceOptions(kind: AudienceKind): AudienceOptions | null {
  const { roles } = useAuth()
  const roleSignature = roles.map((r) => [r.key, r.scope_kind, r.scope_id].join(':')).join(',')
  const cacheKey = (auth.get() ?? '') + '|' + roleSignature + '|' + kind
  const [opts, setOpts] = useState<AudienceOptions | null>(() => cache.get(cacheKey) ?? null)
  useEffect(() => {
    const known = cache.get(cacheKey)
    if (known) { setOpts(known); return }
    setOpts(null)
    api<AudienceOptions>(`/admin/audiences?for=${kind}`).then((o) => { cache.set(cacheKey, o); setOpts(o) }).catch(() => setOpts({ church: false, tribes: [], gems: [], departments: [] }))
  }, [cacheKey, kind])
  return opts
}

/**
 * Selection par defaut, seulement quand elle ne peut pas melanger deux groupes : toute l'eglise si autorise ; sa
 * tribu (ses GEMs en font partie) ; son seul GEM ; son seul departement. Responsable de plusieurs departements, ou
 * d'une tribu ET d'un service : rien n'est coche d'office, la personne choisit (meme regle que le serveur).
 */
export function defaultScopes(o: AudienceOptions): AudienceScope[] {
  if (o.church) return [{ type: 'church', id: null }]
  const tribe = o.tribes.length === 1 ? o.tribes[0] : null
  if (tribe && o.departments.length === 0 && o.gems.every((g) => g.tribe_id === tribe.id)) return [{ type: 'tribe', id: tribe.id }]
  if (o.tribes.length === 0 && o.gems.length === 1 && o.departments.length === 0) return [{ type: 'gem', id: o.gems[0].id }]
  if (o.tribes.length === 0 && o.gems.length === 0 && o.departments.length === 1) return [{ type: 'department', id: o.departments[0].id }]
  return []
}

const key = (s: AudienceScope) => `${s.type}:${s.id ?? ''}`

/**
 * Portee d'une publication : toute l'eglise (si permis) ou une selection de tribus, GEMs et
 * departements. Seules les cibles autorisees sont proposees (le serveur verifie aussi).
 */
export function AudiencePicker({ kind, value, onChange }: { kind: AudienceKind; value: AudienceScope[]; onChange: (s: AudienceScope[]) => void }) {
  const opts = useAudienceOptions(kind)

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
            onClick={() => onChange([{ type: 'church', id: null }])}>Toute l'église</button>
          <button type="button" role="radio" aria-checked={!church} className={!church ? 'on' : ''}
            onClick={() => onChange(church ? [] : value)}>Choisir des groupes</button>
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
      {!church && !nothing && value.length === 0 && (
        <p className="helper audience-choose">Choisissez à qui s’adresse cette publication : rien n’est coché d’office quand vous avez plusieurs groupes.</p>
      )}
      {!opts.church && !nothing && <p className="helper">Seuls les groupes de votre périmètre sont proposés. Plusieurs choix possibles.</p>}
    </div>
  )
}
