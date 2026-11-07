import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, peek } from '../../api/client'
import { invalidateReference } from '../../api/reference'
import { AppLayout } from '../../components/AppLayout'
import type { GemAdminItem, Tribe } from '../../types'
import { useDirty } from '../../utils/dirty'

/** Membre de la tribu : peut etre nomme Garde ou place dans le GEM. */
interface Candidate { user_id: number; full_name: string; gem_id: number | null; gem: string | null; leads: string[]; lead_gem_ids?: number[] }

const sameSet = (a: number[], b: number[]) => a.length === b.length && a.every((x) => b.includes(x))

export default function Gems() {
  const [known] = useState(() => peek<{ gems: GemAdminItem[]; tribes: Tribe[] }>('/admin/gems'))
  const [gems, setGems] = useState<GemAdminItem[]>(known?.gems ?? [])
  const [tribes, setTribes] = useState<Tribe[]>(known?.tribes ?? [])
  const [candidates, setCandidates] = useState<Candidate[] | null>(null)
  const [mode, setMode] = useState<'list' | 'form'>('list')
  const [error, setError] = useState('')

  const [editId, setEditId] = useState<number | null>(null)
  const [name, setName] = useState('')
  const [tribeId, setTribeId] = useState('')
  const [leaderId, setLeaderId] = useState('')
  const [busy, setBusy] = useState(false)
  const formState = useDirty({ name, tribeId, leaderId })
  // Membres du GEM : seulement des membres de la tribu du GEM (celle ou l'on compose les GEMs).
  const [memberIds, setMemberIds] = useState<number[]>([])
  const [initialMembers, setInitialMembers] = useState<number[]>([])
  const [search, setSearch] = useState('')
  const membersDirty = !sameSet(memberIds, initialMembers)

  const load = useCallback(() => {
    api<{ gems: GemAdminItem[]; tribes: Tribe[] }>('/admin/gems')
      .then((r) => { setGems(r.gems); setTribes(r.tribes) }).catch(() => setGems([]))
  }, [])
  useEffect(() => { load() }, [load])
  // Le Garde se choisit uniquement parmi les membres de la tribu du GEM.
  useEffect(() => {
    if (!tribeId) { setCandidates(null); return }
    setCandidates(null)
    api<{ members: Candidate[] }>(`/admin/gems/candidates?tribe_id=${tribeId}`)
      .then((r) => {
        setCandidates(r.members)
        setLeaderId((cur) => (cur && !r.members.some((m) => String(m.user_id) === cur) ? '' : cur))
        const inGem = editId ? r.members.filter((m) => m.gem_id === editId).map((m) => m.user_id) : []
        setMemberIds(inGem); setInitialMembers(inGem)
      })
      .catch(() => setCandidates([]))
  }, [tribeId, editId])

  function openNew() {
    setEditId(null); setName(''); setLeaderId(''); setError(''); setSearch(''); setMemberIds([]); setInitialMembers([])
    // Une seule tribu possible (responsable de tribu) : preselectionnee.
    setTribeId(tribes.length === 1 ? String(tribes[0].id) : '')
    setMode('form'); formState.reset()
  }
  function openEdit(g: GemAdminItem) {
    setEditId(g.id); setName(g.name); setTribeId(g.tribe_id.toString()); setLeaderId(g.leader_user_id?.toString() ?? '')
    setError(''); setSearch(''); setMode('form'); formState.reset()
  }

  async function save() {
    setError(''); setBusy(true)
    const body = { name, tribe_id: Number(tribeId), leader_user_id: leaderId ? Number(leaderId) : null }
    try {
      let id = editId
      if (editId) {
        if (formState.dirty) await api(`/admin/gems/${editId}`, { method: 'PUT', body })
      } else {
        id = (await api<{ gem: GemAdminItem }>('/admin/gems', { method: 'POST', body })).gem.id
      }
      // Puis les membres (le Garde y est toujours).
      if (id && membersDirty) await api(`/admin/gems/${id}/members`, { method: 'PUT', body: { user_ids: memberIds } })
      invalidateReference(); setMode('list'); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove(g: GemAdminItem) {
    if (!confirm(`Supprimer le GEM « ${g.name} » ? Ses membres n'y seront plus rattachés.`)) return
    setError('')
    try { await api(`/admin/gems/${g.id}`, { method: 'DELETE' }); invalidateReference(); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  const byTribe = tribes.map((t) => ({ tribe: t, gems: gems.filter((g) => g.tribe_id === t.id) }))

  return (
    <AppLayout title="GEMs" subtitle="Groupes de 3 à 5 membres, menés par un Garde"
      actions={mode === 'list' ? <button className="btn btn-primary small" onClick={openNew}>+ Nouveau GEM</button> : undefined}>
      {error && <div className="alert alert-error">{error}</div>}

      {mode === 'form' ? (
        <section className="panel form-panel">
          <div className="panel-head">
            <h3>{editId ? 'Modifier le GEM' : 'Nouveau GEM'}</h3>
            <button className="btn-link" onClick={() => setMode('list')}>Annuler</button>
          </div>
          <div className="field"><label>Nom du GEM</label>
            <input aria-label="Nom du GEM" className="input" value={name} onChange={(e) => setName(e.target.value)} placeholder="Ex. GEM Bethel" />
          </div>
          <div className="field"><label>Tribu</label>
            <select aria-label="Tribu" className="select" value={tribeId} onChange={(e) => setTribeId(e.target.value)}>
              <option value="">Choisir...</option>
              {tribes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>
          <div className="field"><label>Garde (responsable du GEM)</label>
            <select aria-label="Garde (responsable du GEM)" className="select" value={leaderId} disabled={!tribeId || candidates === null} onChange={(e) => setLeaderId(e.target.value)}>
              <option value="">{!tribeId ? "Choisissez d'abord la tribu" : candidates === null ? 'Chargement…' : "Aucun pour l'instant"}</option>
              {candidates?.map((m) => (
                <option key={m.user_id} value={m.user_id}>
                  {m.full_name}{m.leads.length ? ` · Garde de ${m.leads.join(', ')}` : m.gem ? ` · membre de ${m.gem}` : ''}
                </option>
              ))}
            </select>
            {tribeId && candidates !== null && (
              <p className="helper">
                {candidates.length === 0
                  ? "Aucun membre dans cette tribu pour l'instant : le Garde pourra être nommé plus tard."
                  : 'Seuls les membres de cette tribu peuvent être Garde. Le Garde rejoint automatiquement son GEM.'}
              </p>
            )}
          </div>
          {tribeId && candidates !== null && candidates.length > 0 && (() => {
            const leader = Number(leaderId) || null
            const q = search.trim().toLowerCase()
            const shown = candidates.filter((m) => !q || m.full_name.toLowerCase().includes(q))
            const count = new Set([...memberIds, ...(leader ? [leader] : [])]).size
            return (
              <div className="field">
                <label>Membres du GEM <span className="helper" style={{ display: 'inline' }}>({count} · de 3 à 5 conseillé)</span></label>
                {candidates.length > 8 && (
                  <input aria-label="Chercher un membre" className="input gem-members-search" value={search}
                    onChange={(e) => setSearch(e.target.value)} placeholder="Chercher un membre de la tribu" />
                )}
                <ul className="gem-members">
                  {shown.map((m) => {
                    const isLeader = m.user_id === leader
                    const leadsOther = (m.lead_gem_ids ?? []).some((gid) => gid !== editId)
                    const checked = isLeader || memberIds.includes(m.user_id)
                    const inThis = !!editId && m.gem_id === editId
                    const status = isLeader ? 'Garde de ce GEM'
                      : leadsOther ? `Garde de ${m.leads.join(', ')}`
                        : checked ? (inThis ? 'Dans ce GEM' : m.gem ? `Sera déplacé(e) depuis ${m.gem}` : 'Sera ajouté(e)')
                          : inThis ? 'Sera retiré(e) du GEM' : m.gem ? `Dans ${m.gem}` : 'Sans GEM'
                    return (
                      <li key={m.user_id}>
                        <label className={`gem-member ${isLeader || leadsOther ? 'is-locked' : ''}`}>
                          <input type="checkbox" checked={checked} disabled={isLeader || leadsOther}
                            onChange={() => setMemberIds((ids) => (ids.includes(m.user_id) ? ids.filter((x) => x !== m.user_id) : [...ids, m.user_id]))} />
                          <span className="gem-member-text">
                            <strong>{m.full_name}</strong>
                            <small>{status}</small>
                          </span>
                        </label>
                      </li>
                    )
                  })}
                  {shown.length === 0 && <li className="helper">Aucun membre ne correspond.</li>}
                </ul>
                <p className="helper">Seuls les membres de la tribu de ce GEM apparaissent. Un membre n'est que dans un GEM.</p>
              </div>
            )
          })()}
          <button className="btn btn-primary mt" disabled={busy || !name.trim() || !tribeId || (!!editId && !formState.dirty && !membersDirty)} onClick={save}>
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
                      <span className="gem-meta">{g.leader ? `Garde : ${g.leader}` : 'Pas de Garde'} · {g.members_count} membre(s)</span>
                    </div>
                    <button className="btn-link" onClick={() => openEdit(g)}>Modifier</button>
                    <button className="org-del" onClick={() => remove(g)} aria-label="Supprimer">×</button>
                  </div>
                ))}
                {tg.length === 0 && <p className="helper">Aucun GEM dans cette tribu.</p>}
              </div>
            </section>
          ))}
          {tribes.length === 0 && <p className="helper">Créez d'abord des tribus dans Organisation.</p>}
        </div>
      )}
    </AppLayout>
  )
}
