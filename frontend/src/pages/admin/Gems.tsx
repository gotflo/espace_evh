import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { invalidateReference } from '../../api/reference'
import { AppLayout } from '../../components/AppLayout'
import type { GemAdminItem, MemberListItem, Tribe } from '../../types'

export default function Gems() {
  const [gems, setGems] = useState<GemAdminItem[]>([])
  const [tribes, setTribes] = useState<Tribe[]>([])
  const [members, setMembers] = useState<MemberListItem[]>([])
  const [mode, setMode] = useState<'list' | 'form'>('list')
  const [error, setError] = useState('')

  const [editId, setEditId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [tribeId, setTribeId] = useState('')
  const [leaderId, setLeaderId] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api<{ gems: GemAdminItem[]; tribes: Tribe[] }>('/admin/gems')
      .then((r) => { setGems(r.gems); setTribes(r.tribes) }).catch(() => setGems([]))
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    api<{ members: MemberListItem[] }>('/admin/members').then((r) => setMembers(r.members)).catch(() => {})
  }, [])

  function openNew() { setEditId(null); setName(''); setTribeId(''); setLeaderId(''); setError(''); setMode('form') }
  function openEdit(g: GemAdminItem) {
    setEditId(g.id); setName(g.name); setTribeId(g.tribe_id.toString()); setLeaderId(g.leader_user_id?.toString() ?? '')
    setError(''); setMode('form')
  }

  async function save() {
    setError(''); setBusy(true)
    const body = { name, tribe_id: Number(tribeId), leader_user_id: leaderId ? Number(leaderId) : null }
    try {
      if (editId) await api(`/admin/gems/${editId}`, { method: 'PUT', body })
      else await api('/admin/gems', { method: 'POST', body })
      invalidateReference(); setMode('list'); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove(g: GemAdminItem) {
    if (!confirm(`Supprimer le GEM "${g.name}" ? Les membres n'y seront plus rattaches.`)) return
    setError('')
    try { await api(`/admin/gems/${g.id}`, { method: 'DELETE' }); invalidateReference(); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  const byTribe = tribes.map((t) => ({ tribe: t, gems: gems.filter((g) => g.tribe_id === t.id) }))

  return (
    <AppLayout title="GEMs" subtitle="Groupes de 3 à 5 membres, menés par un GAD"
      actions={mode === 'list' ? <button className="btn btn-primary small" onClick={openNew}>+ Nouveau GEM</button> : undefined}>
      {error && <div className="alert alert-error">{error}</div>}

      {mode === 'form' ? (
        <section className="panel form-panel">
          <div className="panel-head">
            <h3>{editId ? 'Modifier le GEM' : 'Nouveau GEM'}</h3>
            <button className="btn-link" onClick={() => setMode('list')}>Annuler</button>
          </div>
          <div className="field"><label>Nom du GEM</label>
            <input className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex. GEM Bethel" />
          </div>
          <div className="field"><label>Tribu</label>
            <select className="select" value={tribeId} onChange={(e) => setTribeId(e.target.value)}>
              <option value="">Choisir...</option>
              {tribes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>
          <div className="field"><label>Responsable (GAD)</label>
            <select className="select" value={leaderId} onChange={(e) => setLeaderId(e.target.value)}>
              <option value="">Aucun pour l'instant</option>
              {members.map((m) => <option key={m.user_id} value={m.user_id}>{m.full_name}</option>)}
            </select>
          </div>
          <button className="btn btn-primary mt" disabled={busy || !name.trim() || !tribeId} onClick={save}>
            {busy ? <span className="spinner" /> : editId ? 'Enregistrer' : 'Créer le GEM'}
          </button>
        </section>
      ) : (
        <div className="dash-col" style={{ gap: '1.2rem' }}>
          {byTribe.map(({ tribe, gems: tg }) => (
            <section key={tribe.id} className="panel">
              <div className="panel-head"><h3>Tribu {tribe.name}</h3><span className="helper">{tg.length} GEM(s)</span></div>
              <div className="gem-list">
                {tg.map((g) => (
                  <div key={g.id} className="gem-row">
                    <div className="gem-main">
                      <span className="gem-name">{g.name}</span>
                      <span className="gem-meta">{g.leader ? `GAD : ${g.leader}` : 'Pas de GAD'} · {g.members_count} membre(s)</span>
                    </div>
                    <button className="btn-link" onClick={() => openEdit(g)}>Modifier</button>
                    <button className="org-del" onClick={() => remove(g)} aria-label="Supprimer">×</button>
                  </div>
                ))}
                {tg.length === 0 && <p className="helper">Aucun GEM dans cette tribu.</p>}
              </div>
            </section>
          ))}
          {tribes.length === 0 && <p className="helper">Creez d'abord des tribus dans Organisation.</p>}
        </div>
      )}
    </AppLayout>
  )
}
