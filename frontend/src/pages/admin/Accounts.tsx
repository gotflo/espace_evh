import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { getReference, type ReferenceData } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import { Icon } from '../../components/Icon'
import { SkeletonCard } from '../../components/Skeleton'
import { toast } from '../../toast'
import type { ManagedRole } from '../../types'

interface AccountRole { assignment_id: number; key: string; name: string; scope_kind: string | null; scope_name: string | null }
interface Account {
  id: number
  name: string | null
  first_name: string | null
  last_name: string | null
  photo_url: string | null
  tribe: string | null
  tribe_id: number | null
  phone: string | null
  status: 'active' | 'inactive' | 'blocked'
  roles: AccountRole[]
  led_departments: string[]
}
interface AccountsPage { accounts: Account[]; total: number; page: number; has_more: boolean }

const SCOPE_FIELD: Record<string, string> = { tribe: 'Tribu', gem: 'GEM', department: 'Département', member: 'Fidèle confié' }
const initials = (a: Account) => ((a.first_name?.[0] ?? '') + (a.last_name?.[0] ?? '')).toUpperCase() || '?'

/** Attribution d'un role a un compte (avec la portee si le role l'exige). */
function AssignRole({ account, roles, reference, onDone }: { account: Account; roles: ManagedRole[]; reference: ReferenceData | null; onDone: () => void }) {
  const [roleKey, setRoleKey] = useState('')
  const [scopeId, setScopeId] = useState('')
  const [memberQuery, setMemberQuery] = useState('')
  const [members, setMembers] = useState<Account[] | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const role = roles.find((r) => r.key === roleKey)
  const scope = role && role.scope_kind !== 'none' ? role.scope_kind : null

  // Fidele confie : recherche cote serveur (jamais toute la liste).
  useEffect(() => {
    if (scope !== 'member') return
    const t = window.setTimeout(() => {
      setMembers(null)
      api<AccountsPage>(`/admin/accounts?q=${encodeURIComponent(memberQuery.trim())}`)
        .then((r) => setMembers(r.accounts.filter((m) => m.id !== account.id))).catch(() => setMembers([]))
    }, 250)
    return () => window.clearTimeout(t)
  }, [scope, memberQuery, account.id])

  const options = scope === 'tribe' ? reference?.tribes ?? []
    : scope === 'gem' ? (reference?.gems ?? []).filter((g) => g.tribe_id === account.tribe_id)
      : scope === 'department' ? reference?.departments ?? [] : []

  async function assign() {
    setError(''); setBusy(true)
    try {
      await api(`/admin/members/${account.id}/roles`, { method: 'POST', body: { role_key: roleKey, scope_id: scope ? Number(scopeId) : null }, toast: false })
      toast.success(`${role?.name} attribué à ${account.name}.`)
      setRoleKey(''); setScopeId('')
      onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  return (
    <div className="assign-box acc-assign">
      {error && <div className="alert alert-error" role="alert">{error}</div>}
      <div className="field-row">
        <div className="field">
          <label htmlFor={`acc-role-${account.id}`}>Ajouter un rôle</label>
          <select id={`acc-role-${account.id}`} className="select" value={roleKey} onChange={(e) => { setRoleKey(e.target.value); setScopeId('') }}>
            <option value="">Choisir un rôle…</option>
            {roles.filter((r) => r.can_assign !== false).map((r) => <option key={r.key} value={r.key}>{r.name}</option>)}
          </select>
        </div>
        {scope && (
          <div className="field">
            <label htmlFor={`acc-scope-${account.id}`}>{SCOPE_FIELD[scope]}</label>
            {scope === 'member' ? (
              <>
                <input className="input" aria-label="Rechercher un fidèle" placeholder="Rechercher un fidèle…" value={memberQuery} onChange={(e) => setMemberQuery(e.target.value)} />
                <select id={`acc-scope-${account.id}`} className="select mt-sm" value={scopeId} onChange={(e) => setScopeId(e.target.value)}>
                  <option value="">{members === null ? 'Chargement…' : 'Choisir le fidèle…'}</option>
                  {(members ?? []).map((m) => <option key={m.id} value={m.id}>{m.name}{m.tribe ? ` · ${m.tribe}` : ''}</option>)}
                </select>
              </>
            ) : (
              <select id={`acc-scope-${account.id}`} className="select" value={scopeId} onChange={(e) => setScopeId(e.target.value)}>
                <option value="">Choisir…</option>
                {options.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
              </select>
            )}
            {scope === 'gem' && !account.tribe_id && <p className="helper">Ce membre doit d’abord appartenir à une tribu.</p>}
          </div>
        )}
      </div>
      <button className="btn btn-primary small" disabled={busy || !roleKey || (!!scope && !scopeId)} onClick={assign}>
        {busy ? <span className="spinner" /> : 'Attribuer'}
      </button>
    </div>
  )
}

/**
 * Comptes : nom, tribu, telephone masque et fonctions de chaque membre inscrit, pour attribuer ou retirer
 * des roles sans ouvrir la fiche du membre (aucune donnee personnelle au-dela de l'identite).
 */
export default function Accounts() {
  const { hasPermission } = useAuth()
  const canAssign = hasPermission('roles.assign')
  const [query, setQuery] = useState('')
  const [roleFilter, setRoleFilter] = useState('')
  const [data, setData] = useState<AccountsPage | null>(null)
  const [loadingMore, setLoadingMore] = useState(false)
  const [roles, setRoles] = useState<ManagedRole[]>([])
  const [reference, setReference] = useState<ReferenceData | null>(null)
  const [openId, setOpenId] = useState<number | null>(null)

  const url = useCallback((page: number) =>
    `/admin/accounts?page=${page}&q=${encodeURIComponent(query.trim())}${roleFilter ? `&role=${encodeURIComponent(roleFilter)}` : ''}`, [query, roleFilter])

  const load = useCallback(() => {
    api<AccountsPage>(url(1)).then(setData).catch(() => setData({ accounts: [], total: 0, page: 1, has_more: false }))
  }, [url])

  useEffect(() => {
    const t = window.setTimeout(load, 250)
    return () => window.clearTimeout(t)
  }, [load])

  useEffect(() => {
    api<{ roles: ManagedRole[] }>('/admin/roles').then((r) => setRoles(r.roles)).catch(() => {})
    if (canAssign) getReference().then(setReference).catch(() => {})
  }, [canAssign])

  function more() {
    if (!data) return
    setLoadingMore(true)
    api<AccountsPage>(url(data.page + 1))
      .then((r) => setData({ ...r, accounts: [...data.accounts, ...r.accounts] }))
      .catch(() => {}).finally(() => setLoadingMore(false))
  }

  async function removeRole(a: Account, r: AccountRole) {
    if (!confirm(`Retirer le rôle « ${r.name}${r.scope_name ? ` · ${r.scope_name}` : ''} » à ${a.name} ?`)) return
    try {
      await api(`/admin/members/${a.id}/roles/${r.assignment_id}`, { method: 'DELETE' })
      load()
    } catch { /* message affiche par le client */ }
  }

  const canRemove = (r: AccountRole) => canAssign && roles.find((x) => x.key === r.key)?.can_assign !== false

  return (
    <AppLayout title="Comptes" subtitle="Fonctions et rôles des membres inscrits">
      <section className="panel acc-toolbar">
        <input className="input" type="search" aria-label="Rechercher un membre" placeholder="Rechercher un nom…" value={query} onChange={(e) => setQuery(e.target.value)} />
        <select className="select" aria-label="Filtrer par rôle" value={roleFilter} onChange={(e) => setRoleFilter(e.target.value)}>
          <option value="">Tous les rôles</option>
          {roles.map((r) => <option key={r.key} value={r.key}>{r.name}</option>)}
        </select>
      </section>
      <p className="helper acc-note"><Icon name="lock" size={13} /> Seuls le nom, la tribu, la fin du numéro et les fonctions sont affichés : les fiches des membres restent privées.</p>

      {!data ? <SkeletonCard /> : (
        <>
          <p className="helper">{data.total} compte{data.total > 1 ? 's' : ''}</p>
          <ul className="acc-list">
            {data.accounts.map((a) => (
              <li key={a.id} className="panel acc-row">
                <div className="acc-main">
                  {a.photo_url ? <img className="mini-avatar" src={a.photo_url} alt="" /> : <span className="mini-avatar">{initials(a)}</span>}
                  <div className="acc-id">
                    <strong className="acc-name">{a.name ?? 'Sans nom'}</strong>
                    <small>{[a.tribe ? `Tribu ${a.tribe}` : null, a.phone, a.status === 'blocked' ? 'Suspendu' : a.status === 'inactive' ? 'Inactif' : null].filter(Boolean).join(' · ')}</small>
                  </div>
                  {canAssign && (
                    <button className="btn btn-ghost small acc-open" aria-expanded={openId === a.id} onClick={() => setOpenId(openId === a.id ? null : a.id)}>
                      <Icon name={openId === a.id ? 'check' : 'plus'} size={15} /> {openId === a.id ? 'Fermer' : 'Rôle'}
                    </button>
                  )}
                </div>
                <div className="role-chips acc-roles">
                  {a.roles.map((r) => (
                    <span key={r.assignment_id} className={`role-chip ${r.key === 'super_admin' ? 'badge-gold' : ''}`}>
                      {r.name}{r.scope_name ? ` · ${r.scope_name}` : ''}
                      {canRemove(r) && <button className="role-chip-x" onClick={() => removeRole(a, r)} aria-label={`Retirer ${r.name}`}>×</button>}
                    </span>
                  ))}
                  {a.led_departments.map((d) => <span key={d} className="role-chip">Responsable · {d}</span>)}
                  {a.roles.length === 0 && a.led_departments.length === 0 && <span className="helper">Aucune fonction.</span>}
                </div>
                {openId === a.id && <AssignRole account={a} roles={roles} reference={reference} onDone={load} />}
              </li>
            ))}
            {data.accounts.length === 0 && (
              <li className="panel empty-state"><span><Icon name="profile" size={32} /></span><h3>Aucun compte</h3><p>Aucun membre ne correspond à cette recherche.</p></li>
            )}
          </ul>
          {data.has_more && (
            <div className="center mt">
              <button className="btn btn-ghost" disabled={loadingMore} onClick={more}>{loadingMore ? <span className="spinner" /> : 'Voir plus'}</button>
            </div>
          )}
        </>
      )}
    </AppLayout>
  )
}
