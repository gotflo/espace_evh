import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, ApiError, peek } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { Icon } from '../../components/Icon'
import { SkeletonCard } from '../../components/Skeleton'
import type { ManagedRole, PermissionGroup, PermissionItem } from '../../types'
import { useDirty } from '../../utils/dirty'

const SCOPE_LABEL: Record<string, string> = {
  none: 'Sans portée',
  tribe: 'Par tribu',
  gem: 'Par GEM',
  department: 'Par département',
  member: 'Un fidèle précis',
}
type ScopeKind = 'none' | 'tribe' | 'gem' | 'department' | 'member'

type Editing = ManagedRole | 'new' | null

/** Recherche sans accents ni majuscules. */
const fold = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

export default function RolesAdmin() {
  const [roles, setRoles] = useState<ManagedRole[] | null>(() => peek<{ roles: ManagedRole[] }>('/admin/roles')?.roles ?? null)
  const [groups, setGroups] = useState<PermissionGroup[]>(() => peek<{ groups: PermissionGroup[] }>('/admin/permissions')?.groups ?? [])
  const [editing, setEditing] = useState<Editing>(null)

  // Champs de l'editeur
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [scopeKind, setScopeKind] = useState<ScopeKind>('none')
  const [perms, setPerms] = useState<string[]>([])
  const [query, setQuery] = useState('')
  const [open, setOpen] = useState<Record<string, boolean>>({})
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const formState = useDirty({ name, description, scopeKind, perms })

  const loadRoles = useCallback(() => {
    api<{ roles: ManagedRole[] }>('/admin/roles').then((r) => setRoles(r.roles)).catch(() => setRoles((p) => p ?? []))
  }, [])

  useEffect(() => { loadRoles() }, [loadRoles])
  useEffect(() => {
    api<{ groups: PermissionGroup[] }>('/admin/permissions').then((r) => setGroups(r.groups)).catch(() => {})
  }, [])

  const readOnly = editing !== null && editing !== 'new' && editing.can_edit === false
  const isSystem = editing !== 'new' && editing !== null && editing.is_system

  function openEditor(role: Editing) {
    setError(''); setNotice(''); setQuery('')
    if (role === 'new') {
      setName(''); setDescription(''); setScopeKind('none'); setPerms([])
    } else if (role) {
      setName(role.name); setDescription(role.description ?? '')
      setScopeKind(role.scope_kind); setPerms([...role.permission_keys])
    }
    // Modules ouverts : ceux qui ont deja des permissions (tous pour un nouveau role).
    const initial = role === 'new' ? [] : role?.permission_keys ?? []
    setOpen(Object.fromEntries(groups.map((g) => [g.key ?? g.group, role === 'new' || g.permissions.some((p) => initial.includes(p.key))])))
    setEditing(role)
    formState.reset()
  }

  const visibleGroups = useMemo(() => {
    const q = fold(query.trim())
    if (!q) return groups
    return groups
      .map((g) => ({ ...g, permissions: fold(g.group).includes(q) ? g.permissions : g.permissions.filter((p) => fold(p.name).includes(q) || fold(p.key).includes(q)) }))
      .filter((g) => g.permissions.length > 0)
  }, [groups, query])

  const confidentialCount = useMemo(() => {
    const confidential = new Set(groups.flatMap((g) => g.permissions.filter((p) => p.confidential).map((p) => p.key)))
    return perms.filter((p) => confidential.has(p)).length
  }, [groups, perms])

  function togglePerm(p: PermissionItem) {
    if (readOnly || p.grantable === false) return
    setPerms((list) => (list.includes(p.key) ? list.filter((x) => x !== p.key) : [...list, p.key]))
  }

  /** Tout cocher / tout decocher dans un module (seulement les permissions que l'on peut donner). */
  function setModule(g: PermissionGroup, on: boolean) {
    if (readOnly) return
    const keys = g.permissions.filter((p) => p.grantable !== false).map((p) => p.key)
    setPerms((list) => (on ? Array.from(new Set([...list, ...keys])) : list.filter((k) => !keys.includes(k))))
  }

  async function save() {
    setError(''); setBusy(true)
    const body = { name: name.trim(), description: description.trim(), scope_kind: scopeKind, permission_keys: perms }
    try {
      if (editing === 'new') {
        await api('/admin/roles', { method: 'POST', body, toast: false })
        setNotice('Rôle créé.')
      } else if (editing) {
        await api(`/admin/roles/${editing.id}`, { method: 'PUT', body, toast: false })
        setNotice('Rôle enregistré. Les personnes qui l’ont reçoivent ces droits dès leur prochaine action.')
      }
      setEditing(null); loadRoles()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove() {
    if (editing === 'new' || !editing) return
    const holders = editing.users_count ?? 0
    if (!confirm(`Supprimer le rôle « ${editing.name} » ?${holders ? ` ${holders} personne(s) le perdront.` : ''}`)) return
    setError('')
    try {
      await api(`/admin/roles/${editing.id}`, { method: 'DELETE', toast: false })
      setNotice('Rôle supprimé.')
      setEditing(null); loadRoles()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    }
  }

  const createBtn = <button className="btn btn-primary small" onClick={() => openEditor('new')}>+ Créer un rôle</button>

  return (
    <AppLayout title="Rôles et permissions" subtitle="Définir qui peut voir et faire quoi, module par module" actions={editing ? undefined : createBtn}>
      {notice && !editing && <div className="alert alert-ok" role="status">{notice}</div>}
      {!editing ? (
        roles === null ? <SkeletonCard /> : (
          <div className="role-grid">
            {roles.map((r) => (
              <button key={r.id} className="role-card" onClick={() => openEditor(r)}>
                <div className="role-card-top">
                  <span className="role-card-name">{r.name}</span>
                  {r.is_system ? <span className="badge">Base</span> : <span className="badge badge-gold">Personnalisé</span>}
                </div>
                {r.description && <p className="role-card-desc">{r.description}</p>}
                <div className="role-card-meta">
                  <span>{SCOPE_LABEL[r.scope_kind]}</span>
                  <span>{r.permission_keys.length} permission{r.permission_keys.length > 1 ? 's' : ''}</span>
                </div>
                <div className="role-card-meta">
                  <span>{r.users_count ?? 0} personne{(r.users_count ?? 0) > 1 ? 's' : ''}</span>
                  {r.can_edit === false && <span className="role-lock"><Icon name="lock" size={13} /> Lecture seule</span>}
                </div>
              </button>
            ))}
          </div>
        )
      ) : (
        <section className="panel role-editor">
          <div className="panel-head">
            <h3>{editing === 'new' ? 'Nouveau rôle' : readOnly ? editing.name : `Modifier : ${editing.name}`}</h3>
            <button className="btn-link" onClick={() => setEditing(null)}>{readOnly ? 'Fermer' : 'Annuler'}</button>
          </div>

          {error && <div className="alert alert-error" role="alert">{error}</div>}
          {readOnly && <div className="alert alert-info">{(editing as ManagedRole).key === 'super_admin'
            ? 'Le Pasteur Résident a toujours toutes les permissions : ce rôle n’est pas modifiable.'
            : 'Ce rôle est d’un niveau supérieur ou égal au vôtre : vous pouvez le consulter, pas le modifier.'}</div>}
          {isSystem && !readOnly && <div className="alert alert-info">Rôle de base : vous pouvez ajuster ses permissions, mais pas sa portée ni le supprimer.</div>}

          <div className="role-editor-head">
            <div className="field">
              <label htmlFor="role-name">Nom du rôle</label>
              <input id="role-name" className="input" maxLength={60} value={name} disabled={readOnly} onChange={(e) => setName(e.target.value)} placeholder="Ex. Coordinateur intégration" />
            </div>
            <div className="field">
              <label htmlFor="role-scope">Portée</label>
              <select id="role-scope" className="select" value={scopeKind} disabled={isSystem || readOnly}
                onChange={(e) => setScopeKind(e.target.value as ScopeKind)}>
                <option value="none">Sans portée (global)</option>
                <option value="tribe">Rattaché à une tribu</option>
                <option value="gem">Rattaché à un GEM</option>
                <option value="department">Rattaché à un département</option>
                <option value="member">Rattaché à un fidèle précis</option>
              </select>
            </div>
          </div>
          <div className="field">
            <label htmlFor="role-desc">Description</label>
            <textarea id="role-desc" className="input" rows={2} maxLength={500} value={description} disabled={readOnly} onChange={(e) => setDescription(e.target.value)} />
          </div>

          <div className="perm-toolbar">
            <div className="perm-summary">
              <strong>{perms.length}</strong> permission{perms.length > 1 ? 's' : ''}
              {confidentialCount > 0 && <span className="perm-flag"><Icon name="lock" size={12} /> {confidentialCount} sur des données personnelles</span>}
            </div>
            <input className="input perm-search" type="search" aria-label="Chercher une permission" placeholder="Chercher une permission…"
              value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>

          <div className="perm-modules">
            {visibleGroups.map((g) => {
              const id = g.key ?? g.group
              const selected = g.permissions.filter((p) => perms.includes(p.key)).length
              const isOpen = !!query.trim() || open[id]
              return (
                <div key={id} className={`perm-module ${selected ? 'has' : ''}`}>
                  <div className="perm-module-head">
                    <button type="button" className="perm-module-toggle" aria-expanded={isOpen} onClick={() => setOpen((o) => ({ ...o, [id]: !isOpen }))}>
                      <Icon name="chevron" size={15} />
                      <span className="perm-module-name">{g.group}</span>
                      <span className="perm-module-count">{selected}/{g.permissions.length}</span>
                    </button>
                    {!readOnly && (
                      <span className="perm-module-actions">
                        <button type="button" className="btn-link" onClick={() => setModule(g, true)}>Tout</button>
                        <button type="button" className="btn-link" onClick={() => setModule(g, false)}>Aucun</button>
                      </span>
                    )}
                  </div>
                  {isOpen && (
                    <div className="perm-list">
                      {g.permissions.map((p) => {
                        const locked = readOnly || p.grantable === false
                        return (
                          <label key={p.key} className={`perm-check ${locked ? 'locked' : ''}`}
                            title={p.grantable === false && !readOnly ? 'Vous ne pouvez pas donner une permission que vous n’avez pas.' : undefined}>
                            <input type="checkbox" checked={perms.includes(p.key)} disabled={locked} onChange={() => togglePerm(p)} />
                            <span className="perm-label">{p.name}</span>
                            <span className="perm-tags">
                              {p.kind_label && <span className={`perm-kind kind-${p.kind}`}>{p.kind_label}</span>}
                              {p.confidential && <span className="perm-kind confidential">Données personnelles</span>}
                            </span>
                          </label>
                        )
                      })}
                    </div>
                  )}
                </div>
              )
            })}
            {visibleGroups.length === 0 && <p className="helper">Aucune permission ne correspond à « {query} ».</p>}
          </div>

          {!readOnly && (
            <div className="editor-actions">
              <button className="btn btn-primary" disabled={busy || !name.trim() || (editing !== 'new' && !formState.dirty)} onClick={save}>
                {busy ? <span className="spinner" /> : 'Enregistrer'}
              </button>
              {editing !== 'new' && !editing.is_system && (
                <button className="btn btn-ghost" onClick={remove}>Supprimer</button>
              )}
            </div>
          )}
        </section>
      )}
    </AppLayout>
  )
}
