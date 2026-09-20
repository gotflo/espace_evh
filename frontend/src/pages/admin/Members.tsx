import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import type { MemberListItem } from '../../types'

const TABS = [
  { key: '', label: 'Tous' },
  { key: 'actif', label: 'Actifs' },
  { key: 'inactif', label: 'Inactifs' },
]
const API_STATUS: Record<string, string> = { actif: 'active', inactif: 'inactive' }

export default function Members() {
  const [params, setParams] = useSearchParams()
  const statut = params.get('statut') ?? ''
  const [members, setMembers] = useState<MemberListItem[]>([])
  const [q, setQ] = useState('')
  const [loading, setLoading] = useState(true)
  const navigate = useNavigate()

  useEffect(() => {
    const t = setTimeout(() => {
      setLoading(true)
      const status = API_STATUS[statut] ?? ''
      api<{ members: MemberListItem[] }>(`/admin/members?q=${encodeURIComponent(q)}&status=${status}`)
        .then((r) => setMembers(r.members))
        .catch(() => setMembers([]))
        .finally(() => setLoading(false))
    }, 250)
    return () => clearTimeout(t)
  }, [q, statut])

  function setTab(key: string) {
    const next = new URLSearchParams(params)
    if (key) next.set('statut', key); else next.delete('statut')
    setParams(next, { replace: true })
  }

  return (
    <AppLayout title="Membres" subtitle="Voir, suivre et gerer les fideles">
      <section className="panel">
        <div className="toolbar">
          <div className="tabs">
            {TABS.map((t) => (
              <button key={t.key} className={`tab ${statut === t.key ? 'active' : ''}`} onClick={() => setTab(t.key)}>
                {t.label}
              </button>
            ))}
          </div>
          <input
            className="input search-input" placeholder="Rechercher par nom ou telephone..."
            value={q} onChange={(e) => setQ(e.target.value)}
          />
        </div>

        <p className="helper">{loading ? 'Chargement...' : `${members.length} membre(s)`}</p>

        <div className="member-table">
          {members.map((m) => (
            <button key={m.user_id} className="member-row" onClick={() => navigate(`/admin/membres/${m.user_id}`)}>
              {m.photo_url
                ? <img className="member-avatar" src={m.photo_url} alt="" />
                : <span className="member-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
              <span className="member-main">
                <span className="member-name">{m.full_name}</span>
                <span className="member-meta">{[m.tribe, ...m.departments].filter(Boolean).join(' · ') || 'Sans tribu ni departement'}</span>
              </span>
              <span className="member-roles">
                {m.roles.slice(0, 2).map((r) => <span key={r} className="badge">{r}</span>)}
              </span>
              <span className={`status-dot ${m.activity === 'active' ? 'on' : 'off'}`} title={m.activity === 'active' ? 'Actif' : 'Inactif'} />
            </button>
          ))}
          {!loading && members.length === 0 && <p className="helper center">Aucun membre trouve.</p>}
        </div>
      </section>
    </AppLayout>
  )
}
