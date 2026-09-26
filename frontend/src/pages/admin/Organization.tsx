import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { invalidateReference } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import type { OrgItem } from '../../types'

interface OrgData { tribes: OrgItem[]; departments: OrgItem[] }

/** Choix des responsables d'un departement parmi ses membres (5 maximum). */
function LeadersEditor({ item, onDone }: { item: OrgItem; onDone: (saved: boolean) => void }) {
  const [members, setMembers] = useState<{ user_id: number; name: string }[] | null>(null)
  const [selected, setSelected] = useState<number[]>((item.leaders ?? []).map((l) => l.user_id))
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api<{ members: { user_id: number; name: string }[] }>(`/admin/departments/${item.id}/members`)
      .then((r) => setMembers(r.members)).catch(() => setMembers([]))
  }, [item.id])

  const toggle = (id: number) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : s.length >= 5 ? s : [...s, id]))

  async function save() {
    setBusy(true)
    try {
      await api(`/admin/departments/${item.id}/leaders`, { method: 'PUT', body: { user_ids: selected } })
      onDone(true)
    } catch { /* toast deja affiche */ } finally { setBusy(false) }
  }

  return (
    <div className="leaders-editor">
      <p className="helper">Les responsables doivent être membres du département (5 au maximum). Ils peuvent suivre ses membres, faire l'appel des répétitions et publier ses événements.</p>
      {members === null ? <span className="spinner" /> : members.length === 0 ? (
        <p className="helper">Ce département n'a encore aucun membre.</p>
      ) : (
        <div className="leaders-options">
          {members.map((m) => (
            <label key={m.user_id} className={`check-pill ${selected.includes(m.user_id) ? 'on' : ''}`}>
              <input type="checkbox" checked={selected.includes(m.user_id)} onChange={() => toggle(m.user_id)}
                disabled={!selected.includes(m.user_id) && selected.length >= 5} />
              {m.name}
            </label>
          ))}
        </div>
      )}
      <div className="row-actions">
        <button className="btn btn-ghost small" onClick={() => onDone(false)}>Annuler</button>
        <button className="btn btn-primary small" disabled={busy || members === null} onClick={save}>{busy ? <span className="spinner" /> : 'Enregistrer'}</button>
      </div>
    </div>
  )
}

function OrgSection({ title, singular, items, endpoint, canManage, showRehearsal, showLeaders, onChange, onError }: {
  title: string
  singular: string
  items: OrgItem[]
  endpoint: string
  canManage: boolean
  showRehearsal?: boolean
  showLeaders?: boolean
  onChange: () => void
  onError: (msg: string) => void
}) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)
  const [editing, setEditing] = useState<number | null>(null)

  async function call(fn: () => Promise<unknown>) {
    onError(''); setBusy(true)
    try { await fn(); onChange() }
    catch (err) { onError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
    finally { setBusy(false) }
  }

  const add = () => name.trim() && call(async () => {
    await api(`/admin/${endpoint}`, { method: 'POST', body: { name: name.trim() } })
    setName('')
  })

  const rename = (it: OrgItem) => {
    const next = window.prompt(`Renommer "${it.name}"`, it.name)
    if (next && next.trim() && next.trim() !== it.name) {
      call(() => api(`/admin/${endpoint}/${it.id}`, { method: 'PUT', body: { name: next.trim() } }))
    }
  }

  const remove = (it: OrgItem) => {
    if (window.confirm(`Supprimer "${it.name}" ? Les membres n'y seront plus rattaches.`)) {
      call(() => api(`/admin/${endpoint}/${it.id}`, { method: 'DELETE' }))
    }
  }

  return (
    <section className="panel">
      <div className="panel-head"><h3>{title}</h3></div>

      {canManage && (
        <div className="org-add">
          <input className="input" placeholder={`Nouveau ${singular}...`} value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => { if (e.key === 'Enter') add() }} />
          <button className="btn btn-primary small" disabled={busy || !name.trim()} onClick={add}>Ajouter</button>
        </div>
      )}

      <div className="org-list">
        {items.map((it) => (
          <div key={it.id} className="org-row">
            <span className="org-name">
              {it.name}
              {showLeaders && (
                <small className="org-leaders">
                  {it.leaders && it.leaders.length > 0 ? `Responsable(s) : ${it.leaders.map((l) => l.name).join(', ')}` : 'Aucun responsable'}
                  {canManage && <button className="btn-link" onClick={() => setEditing(editing === it.id ? null : it.id)}>Modifier</button>}
                </small>
              )}
            </span>
            {showRehearsal && (
              <button type="button" className={`rehearsal-toggle ${it.tracks_rehearsal ? 'on' : ''}`}
                disabled={!canManage || busy}
                title="Suit la ponctualité aux repetitions (retards, absences)"
                onClick={() => call(() => api(`/admin/${endpoint}/${it.id}`, { method: 'PUT', body: { tracks_rehearsal: !it.tracks_rehearsal } }))}>
                {it.tracks_rehearsal ? '🎵 Repetitions' : 'Repetitions ?'}
              </button>
            )}
            <span className="org-count">{it.members_count} membre(s)</span>
            {canManage && (
              <span className="org-actions">
                <button className="btn-link" onClick={() => rename(it)}>Renommer</button>
                <button className="org-del" onClick={() => remove(it)} aria-label="Supprimer">×</button>
              </span>
            )}
            {editing === it.id && <LeadersEditor item={it} onDone={(saved) => { setEditing(null); if (saved) onChange() }} />}
          </div>
        ))}
        {items.length === 0 && <p className="helper">Aucun element.</p>}
      </div>
    </section>
  )
}

export default function Organization() {
  const { hasPermission } = useAuth()
  const [data, setData] = useState<OrgData | null>(null)
  const [error, setError] = useState('')

  const load = useCallback(() => {
    invalidateReference() // les listes tribus/departements ont pu changer
    api<OrgData>('/admin/organization').then(setData).catch(() => setData(null))
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <AppLayout title="Organisation" subtitle="Tribus et départements de l'église">
      {error && <div className="alert alert-error">{error}</div>}
      <div className="detail-grid">
        <OrgSection title="Tribus" singular="tribu" endpoint="tribes"
          items={data?.tribes ?? []} canManage={hasPermission('tribes.manage')}
          onChange={load} onError={setError} />
        <OrgSection title="Départements" singular="departement" endpoint="departments"
          items={data?.departments ?? []} canManage={hasPermission('departments.manage')}
          showRehearsal showLeaders onChange={load} onError={setError} />
      </div>
    </AppLayout>
  )
}
