import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import type { ManagedRole, PermissionGroup } from '../../types'

const SCOPE_LABEL: Record<string, string> = {
  none: 'Sans portée',
  tribe: 'Par tribu',
  gem: 'Par GEM',
  department: 'Par département',
  member: 'Un fidèle précis',
}
type ScopeKind = 'none' | 'tribe' | 'gem' | 'department' | 'member'

type Editing = ManagedRole | 'new' | null

export default function RolesAdmin() {
  const [roles, setRoles] = useState<ManagedRole[]>([])
  const [groups, setGroups] = useState<PermissionGroup[]>([])
  const [editing, setEditing] = useState<Editing>(null)

  // Champs de l'editeur
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [scopeKind, setScopeKind] = useState<ScopeKind>('none')
  const [perms, setPerms] = useState<string[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const loadRoles = useCallback(() => {
    api<{ roles: ManagedRole[] }>('/admin/roles').then((r) => setRoles(r.roles)).catch(() => setRoles([]))
  }, [])

  useEffect(() => { loadRoles() }, [loadRoles])
  useEffect(() => {
    api<{ groups: PermissionGroup[] }>('/admin/permissions').then((r) => setGroups(r.groups)).catch(() => {})
  }, [])

  function openEditor(role: Editing) {
    setError('')
    if (role === 'new') {
      setName(''); setDescription(''); setScopeKind('none'); setPerms([])
    } else if (role) {
      setName(role.name); setDescription(role.description ?? '')
      setScopeKind(role.scope_kind); setPerms([...role.permission_keys])
    }
    setEditing(role)
  }

  function togglePerm(key: string) {
    setPerms((p) => (p.includes(key) ? p.filter((x) => x !== key) : [...p, key]))
  }

  async function save() {
    setError(''); setBusy(true)
    const body = { name, description, scope_kind: scopeKind, permission_keys: perms }
    try {
      if (editing === 'new') {
        await api('/admin/roles', { method: 'POST', body })
      } else if (editing) {
        await api(`/admin/roles/${editing.id}`, { method: 'PUT', body })
      }
      setEditing(null); loadRoles()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove() {
    if (editing === 'new' || !editing) return
    if (!confirm(`Supprimer le rôle "${editing.name}" ?`)) return
    setError('')
    try {
      await api(`/admin/roles/${editing.id}`, { method: 'DELETE' })
      setEditing(null); loadRoles()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    }
  }

  const isSystem = editing !== 'new' && editing !== null && editing.is_system

  const createBtn = <button className="btn btn-primary small" onClick={() => openEditor('new')}>+ Créer un role</button>

  return (
    <AppLayout title="Rôles et permissions" subtitle="Definir qui peut faire quoi" actions={editing ? undefined : createBtn}>
      {!editing ? (
        <div className="role-grid">
          {roles.map((r) => (
            <button key={r.id} className="role-card" onClick={() => openEditor(r)}>
              <div className="role-card-top">
                <span className="role-card-name">{r.name}</span>
                {r.is_system ? <span className="badge">Base</span> : <span className="badge badge-gold">Personnalise</span>}
              </div>
              {r.description && <p className="role-card-desc">{r.description}</p>}
              <div className="role-card-meta">
                <span>{SCOPE_LABEL[r.scope_kind]}</span>
                <span>{r.permission_keys.length} permission(s)</span>
              </div>
            </button>
          ))}
        </div>
      ) : (
        <section className="panel" style={{ maxWidth: 720 }}>
          <div className="panel-head">
            <h3>{editing === 'new' ? 'Nouveau rôle' : `Modifier : ${editing.name}`}</h3>
            <button className="btn-link" onClick={() => setEditing(null)}>Annuler</button>
          </div>

          {error && <div className="alert alert-error">{error}</div>}
          {isSystem && <div className="alert alert-info">Rôle de base : vous pouvez ajuster ses permissions, mais pas sa portee ni le supprimer.</div>}

          <div className="field">
            <label>Nom du rôle</label>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex. Coordinateur integration" />
          </div>
          <div className="field">
            <label>Description</label>
            <textarea className="input" rows={2} value={description} onChange={(e) => setDescription(e.target.value)} />
          </div>
          <div className="field">
            <label>Portee</label>
            <select className="select" value={scopeKind} disabled={isSystem}
              onChange={(e) => setScopeKind(e.target.value as ScopeKind)}>
              <option value="none">Sans portée (global)</option>
              <option value="tribe">Rattaché à une tribu</option>
              <option value="gem">Rattaché à un GEM</option>
              <option value="department">Rattaché à un département</option>
              <option value="member">Rattaché à un fidèle précis (agir sur ce fidèle)</option>
            </select>
          </div>

          <div className="field">
            <label>Permissions</label>
            <div className="perm-groups">
              {groups.map((g) => (
                <div key={g.group} className="perm-group">
                  <span className="perm-group-title">{g.group}</span>
                  {g.permissions.map((p) => (
                    <label key={p.key} className="perm-check">
                      <input type="checkbox" checked={perms.includes(p.key)} onChange={() => togglePerm(p.key)} />
                      <span>{p.name}</span>
                    </label>
                  ))}
                </div>
              ))}
            </div>
          </div>

          <div className="editor-actions">
            <button className="btn btn-primary" disabled={busy || !name.trim()} onClick={save}>
              {busy ? <span className="spinner" /> : 'Enregistrer'}
            </button>
            {editing !== 'new' && !editing.is_system && (
              <button className="btn btn-ghost" onClick={remove}>Supprimer</button>
            )}
          </div>
        </section>
      )}
    </AppLayout>
  )
}
