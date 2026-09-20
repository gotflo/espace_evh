import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { invalidateReference } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import type { OrgItem } from '../../types'

interface OrgData { tribes: OrgItem[]; departments: OrgItem[] }

function OrgSection({ title, singular, items, endpoint, canManage, showRehearsal, onChange, onError }: {
  title: string
  singular: string
  items: OrgItem[]
  endpoint: string
  canManage: boolean
  showRehearsal?: boolean
  onChange: () => void
  onError: (msg: string) => void
}) {
  const [name, setName] = useState('')
  const [busy, setBusy] = useState(false)

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
            <span className="org-name">{it.name}</span>
            {showRehearsal && (
              <button type="button" className={`rehearsal-toggle ${it.tracks_rehearsal ? 'on' : ''}`}
                disabled={!canManage || busy}
                title="Suit la ponctualite aux repetitions (retards, absences)"
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
    <AppLayout title="Organisation" subtitle="Tribus et departements de l'eglise">
      {error && <div className="alert alert-error">{error}</div>}
      <div className="detail-grid">
        <OrgSection title="Tribus" singular="tribu" endpoint="tribes"
          items={data?.tribes ?? []} canManage={hasPermission('tribes.manage')}
          onChange={load} onError={setError} />
        <OrgSection title="Departements" singular="departement" endpoint="departments"
          items={data?.departments ?? []} canManage={hasPermission('departments.manage')}
          showRehearsal onChange={load} onError={setError} />
      </div>
    </AppLayout>
  )
}
