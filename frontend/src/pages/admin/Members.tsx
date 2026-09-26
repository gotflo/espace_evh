import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../../api/client'
import { getReference } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import type { MemberListItem, Tribe } from '../../types'

interface MemberPage { members: MemberListItem[]; total: number; has_more: boolean }

const TABS = [
  { key: '', label: 'Tous' },
  { key: 'actif', label: 'Actifs' },
  { key: 'inactif', label: 'Inactifs' },
  { key: 'incomplet', label: 'Profil incomplet' },
  { key: 'sans-fiss', label: 'FISS manquante' },
]
const API_FILTER: Record<string, string> = {
  actif: 'status=active', inactif: 'status=inactive', incomplet: 'incomplete=1', 'sans-fiss': 'fiss_missing=1',
}

export default function Members() {
  const [params, setParams] = useSearchParams()
  const statut = params.get('statut') ?? ''
  const tribe = params.get('tribu') ?? ''
  const [members, setMembers] = useState<MemberListItem[]>([])
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [tribes, setTribes] = useState<Tribe[]>([])
  const [q, setQ] = useState('')
  const [loading, setLoading] = useState(true)
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const allTribes = hasPermission('members.view_all')

  useEffect(() => {
    if (allTribes) getReference().then((r) => setTribes(r.tribes)).catch(() => {})
  }, [allTribes])

  const url = useCallback((p: number) => {
    const filter = API_FILTER[statut] ?? ''
    return `/admin/members?page=${p}&q=${encodeURIComponent(q)}${filter ? `&${filter}` : ''}${tribe ? `&tribe_id=${tribe}` : ''}`
  }, [q, statut, tribe])

  useEffect(() => {
    const t = setTimeout(() => {
      setLoading(true)
      api<MemberPage>(url(1))
        .then((r) => { setMembers(r.members); setTotal(r.total); setHasMore(r.has_more); setPage(1) })
        .catch(() => { setMembers([]); setTotal(0); setHasMore(false) })
        .finally(() => setLoading(false))
    }, 250)
    return () => clearTimeout(t)
  }, [url])

  function more() {
    setLoadingMore(true)
    api<MemberPage>(url(page + 1))
      .then((r) => { setMembers((prev) => [...prev, ...r.members]); setHasMore(r.has_more); setPage(page + 1) })
      .catch(() => {})
      .finally(() => setLoadingMore(false))
  }

  function setParam(name: string, value: string) {
    const next = new URLSearchParams(params)
    if (value) next.set(name, value); else next.delete(name)
    setParams(next, { replace: true })
  }

  return (
    <AppLayout title="Membres" subtitle="Voir, suivre et gérer les fidèles">
      <section className="panel">
        <div className="toolbar">
          <div className="filter-chips" role="radiogroup" aria-label="Filtre">
            {TABS.map((t) => (
              <button key={t.key} role="radio" aria-checked={statut === t.key} className={`chip-toggle ${statut === t.key ? 'on' : ''}`} onClick={() => setParam('statut', t.key)}>
                {t.label}
              </button>
            ))}
          </div>
          <div className="toolbar-row">
            {tribes.length > 1 && (
              <select className="select" value={tribe} onChange={(e) => setParam('tribu', e.target.value)} aria-label="Tribu">
                <option value="">Toutes les tribus</option>
                {tribes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
            )}
            <input
              className="input search-input" type="search" placeholder="Rechercher par nom ou téléphone..."
              value={q} onChange={(e) => setQ(e.target.value)}
            />
          </div>
        </div>

        <p className="helper">{loading ? 'Chargement…' : total > members.length ? `${members.length} sur ${total} membres` : `${total} membre(s)`}</p>

        <div className="member-table">
          {members.map((m) => (
            <button key={m.user_id} className="member-row" onClick={() => navigate(`/admin/membres/${m.user_id}`)}>
              {m.photo_url
                ? <img className="member-avatar" src={m.photo_url} alt="" />
                : <span className="member-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
              <span className="member-main">
                <span className="member-name">{m.full_name}</span>
                <span className="member-meta">{[m.tribe, ...m.departments].filter(Boolean).join(' · ') || 'Sans tribu ni département'}</span>
                <span className="member-flags">
                  {m.completion < 100 && <span className="flag warn" title="Profil incomplet">Profil {m.completion} %</span>}
                  {!m.fiss_current && m.activity === 'active' && <span className="flag" title="FISS du mois non remplie">FISS à remplir</span>}
                </span>
              </span>
              <span className="member-roles">
                {m.roles.slice(0, 2).map((r) => <span key={r} className="badge">{r}</span>)}
              </span>
              <span className={`status-dot ${m.activity === 'active' ? 'on' : 'off'}`} title={m.activity === 'active' ? 'Actif' : 'Inactif'} />
            </button>
          ))}
          {!loading && members.length === 0 && <p className="helper center">Aucun membre trouvé.</p>}
        </div>
        {hasMore && !loading && (
          <div className="center mt">
            <button className="btn btn-ghost" disabled={loadingMore} onClick={more}>
              {loadingMore ? <span className="spinner" /> : `Afficher plus (${total - members.length} restant(s))`}
            </button>
          </div>
        )}
      </section>
    </AppLayout>
  )
}
