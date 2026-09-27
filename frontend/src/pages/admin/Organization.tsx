import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { invalidateReference } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import type { OrgItem } from '../../types'

interface OrgData { tribes: OrgItem[]; departments: OrgItem[] }

type Person = { user_id: number; name: string }
const mergePeople = (a: Person[], b: Person[]) => [...a, ...b.filter((p) => !a.some((x) => x.user_id === p.user_id))]

/**
 * Choix des responsables d'un departement (5 au maximum) : parmi ses membres, ou n'importe quel
 * membre de l'eglise trouve par la recherche (il est alors ajoute au departement).
 */
function LeadersEditor({ item, onDone }: { item: OrgItem; onDone: (saved: boolean) => void }) {
  const initial = (item.leaders ?? []).map((l) => l.user_id)
  const [members, setMembers] = useState<Person[] | null>(null)
  const [known, setKnown] = useState<Person[]>((item.leaders ?? []).map((l) => ({ user_id: l.user_id, name: l.name })))
  const [selected, setSelected] = useState<number[]>(initial)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<Person[]>([])
  const [rehearsal, setRehearsal] = useState(!!item.tracks_rehearsal)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api<{ members: Person[] }>(`/admin/departments/${item.id}/members`)
      .then((r) => { setMembers(r.members); setKnown((k) => mergePeople(k, r.members)) }).catch(() => setMembers([]))
  }, [item.id])

  // Recherche dans toute l'eglise (a partir de 2 lettres, apres une courte pause de frappe).
  useEffect(() => {
    const q = query.trim()
    if (q.length < 2) { setResults([]); return }
    const t = window.setTimeout(() => {
      api<{ members: { user_id: number; full_name: string }[] }>(`/admin/members?per_page=8&q=${encodeURIComponent(q)}`)
        .then((r) => setResults(r.members.map((m) => ({ user_id: m.user_id, name: m.full_name }))))
        .catch(() => setResults([]))
    }, 300)
    return () => window.clearTimeout(t)
  }, [query])

  const toggle = (p: Person) => {
    setKnown((k) => mergePeople(k, [p]))
    setSelected((s) => (s.includes(p.user_id) ? s.filter((x) => x !== p.user_id) : s.length >= 5 ? s : [...s, p.user_id]))
  }
  const leadersChanged = selected.length !== initial.length || selected.some((id) => !initial.includes(id))
  const changed = leadersChanged || rehearsal !== !!item.tracks_rehearsal
  const nameOf = (id: number) => known.find((p) => p.user_id === id)?.name ?? ''
  const memberIds = (members ?? []).map((m) => m.user_id)

  async function save() {
    setBusy(true)
    try {
      if (leadersChanged) await api(`/admin/departments/${item.id}/leaders`, { method: 'PUT', body: { user_ids: selected } })
      if (rehearsal !== !!item.tracks_rehearsal) await api(`/admin/departments/${item.id}`, { method: 'PUT', body: { tracks_rehearsal: rehearsal } })
      onDone(true)
    } catch { /* toast deja affiche */ } finally { setBusy(false) }
  }

  return (
    <div className="leaders-editor">
      <p className="helper">Jusqu'à 5 responsables. Ils peuvent suivre les membres du département, faire l'appel des répétitions et publier ses événements. Une personne choisie hors du département y est ajoutée.</p>
      {selected.length > 0 && (
        <div className="leaders-options">
          {selected.map((id) => (
            <button key={id} type="button" className="check-pill on" onClick={() => toggle({ user_id: id, name: nameOf(id) })}
              aria-label={`Retirer ${nameOf(id)}`}>{nameOf(id)} ×</button>
          ))}
        </div>
      )}
      {members === null ? <span className="spinner" /> : members.length > 0 && (
        <>
          <p className="helper mt-sm">Membres du département</p>
          <div className="leaders-options">
            {members.filter((m) => !selected.includes(m.user_id)).map((m) => (
              <button key={m.user_id} type="button" className="check-pill" disabled={selected.length >= 5} onClick={() => toggle(m)}>{m.name}</button>
            ))}
          </div>
        </>
      )}
      <input className="input mt-sm" type="search" aria-label="Rechercher un membre de l'église" placeholder="Rechercher un membre de l'église…"
        value={query} onChange={(e) => setQuery(e.target.value)} />
      {results.length > 0 && (
        <div className="leaders-options mt-sm">
          {results.filter((p) => !selected.includes(p.user_id) && !memberIds.includes(p.user_id)).map((p) => (
            <button key={p.user_id} type="button" className="check-pill" disabled={selected.length >= 5} onClick={() => { toggle(p); setQuery('') }}>
              {p.name} (sera ajouté)
            </button>
          ))}
        </div>
      )}
      <label className="check-line mt-sm">
        <input type="checkbox" checked={rehearsal} onChange={(e) => setRehearsal(e.target.checked)} />
        Suivre la ponctualité aux répétitions (retards, absences)
      </label>
      <div className="row-actions">
        <button className="btn btn-ghost small" onClick={() => onDone(false)}>Annuler</button>
        <button className="btn btn-primary small" disabled={busy || !changed} onClick={save}>{busy ? <span className="spinner" /> : 'Enregistrer'}</button>
      </div>
    </div>
  )
}

function OrgSection({ title, singular, items, endpoint, canManage, showLeaders, onChange, onError }: {
  title: string
  singular: string
  items: OrgItem[]
  endpoint: string
  canManage: boolean
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
          <input className="input" aria-label={`Nouveau ${singular}`} placeholder={`Nouveau ${singular}...`} value={name}
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
        {items.length === 0 && <p className="helper">Aucun élément.</p>}
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
          showLeaders onChange={load} onError={setError} />
      </div>
    </AppLayout>
  )
}
