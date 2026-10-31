import { useCallback, useEffect, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError, peek } from '../../api/client'
import { toast } from '../../toast'
import { usePulse } from '../../pulse'
import { getReference } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import { LoadError } from '../../components/LoadError'
import { useLatestRequest } from '../../utils/latestRequest'
import type { MemberListItem, Tribe } from '../../types'

interface MemberPage { members: MemberListItem[]; total: number; has_more: boolean }

const TABS = [
  { key: '', label: 'Tous' },
  { key: 'actif', label: 'Actifs' },
  { key: 'inactif', label: 'Inactifs' },
  { key: 'absents', label: 'Absents 7 j' },
  { key: 'incomplet', label: 'Profil incomplet' },
  { key: 'sans-fiss', label: 'FISS manquante' },
]
const API_FILTER: Record<string, string> = {
  actif: 'status=active', inactif: 'status=inactive', absents: 'absent=1', incomplet: 'incomplete=1', 'sans-fiss': 'fiss_missing=1',
}

/** Adresse d'une page de la liste (recherche, statut, tribu). */
function membersPath(p: number, q: string, statut: string, tribe: string) {
  const filter = API_FILTER[statut] ?? ''
  return `/admin/members?page=${p}&q=${encodeURIComponent(q)}${filter ? `&${filter}` : ''}${tribe ? `&tribe_id=${tribe}` : ''}`
}

export default function Members() {
  const [params, setParams] = useSearchParams()
  const statut = params.get('statut') ?? ''
  const tribe = params.get('tribu') ?? ''
  // Liste deja vue (memes filtres) : affichee tout de suite, puis mise a jour en silence.
  const [known] = useState(() => peek<MemberPage>(membersPath(1, '', statut, tribe)))
  const [members, setMembers] = useState<MemberListItem[]>(known?.members ?? [])
  const [total, setTotal] = useState(known?.total ?? 0)
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(known?.has_more ?? false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [tribes, setTribes] = useState<Tribe[]>([])
  const [q, setQ] = useState('')
  const [loading, setLoading] = useState(!known)
  const [loadError, setLoadError] = useState('')
  const { begin, follow } = useLatestRequest()
  // Adresse de la liste affichee : si sa relecture echoue, elle reste ; un autre filtre n'a rien a montrer.
  const shownUrl = useRef(known ? membersPath(1, '', statut, tribe) : '')
  const navigate = useNavigate()
  const { hasPermission, roles } = useAuth()
  const allTribes = hasPermission('members.view_all')
  // FISS : pasteurs et responsables de tribu (patriarche, AP) ; le serveur applique la meme regle.
  const seesFiss = hasPermission('spiritual.view') && (allTribes || roles.some((r) => r.scope_kind === 'tribe'))
  const tabs = seesFiss ? TABS : TABS.filter((t) => t.key !== 'sans-fiss')

  useEffect(() => {
    if (allTribes) getReference().then((r) => setTribes(r.tribes)).catch(() => {})
  }, [allTribes])

  const url = useCallback((p: number) => membersPath(p, q, statut, tribe), [q, statut, tribe])
  // Temps reel : un membre inscrit ou modifie ailleurs apparait en silence (premiere page seulement,
  // pour ne jamais faire perdre la position a quelqu'un qui a deja charge la suite).
  const [refresh, setRefresh] = useState(0)
  usePulse('members', () => { if (page === 1 && !loadingMore) setRefresh((n) => n + 1) })

  useEffect(() => {
    const first = url(1)
    const isLatest = begin()
    const cached = peek<MemberPage>(first)
    if (cached) { setMembers(cached.members); setTotal(cached.total); setHasMore(cached.has_more); setPage(1); setLoading(false); setLoadError(''); shownUrl.current = first }
    const t = setTimeout(() => {
      if (!cached) setLoading(true)
      api<MemberPage>(url(1))
        .then((r) => {
          if (!isLatest()) return
          setMembers(r.members); setTotal(r.total); setHasMore(r.has_more); setPage(1); setLoadError('')
          shownUrl.current = first
        })
        .catch((err) => {
          if (!isLatest() || shownUrl.current === first) return
          setMembers([]); setTotal(0); setHasMore(false); shownUrl.current = ''
          setLoadError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
        })
        .finally(() => { if (isLatest()) setLoading(false) })
    }, 250)
    return () => clearTimeout(t)
  }, [url, refresh, begin])

  function more() {
    const stillThisList = follow()
    setLoadingMore(true)
    api<MemberPage>(url(page + 1))
      .then((r) => { if (!stillThisList()) return; setMembers((prev) => [...prev, ...r.members]); setHasMore(r.has_more); setPage(page + 1) })
      .catch(() => toast.error('Impossible d’afficher la suite. Vérifiez votre réseau puis réessayez.'))
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
            {tabs.map((t) => (
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
            <input aria-label="Rechercher par nom ou téléphone"
              className="input search-input" type="search" placeholder="Rechercher par nom ou téléphone..."
              value={q} onChange={(e) => setQ(e.target.value)}
            />
          </div>
        </div>

        <p className="helper">{loading ? 'Chargement…' : loadError ? '' : total > members.length ? `${members.length} sur ${total} membres` : `${total} membre(s)`}</p>

        <div className="member-table">
          {members.map((m) => (
            <button key={m.user_id} className="member-row" onClick={() => navigate(`/admin/membres/${m.user_id}`)}>
              {m.photo_url
                ? <img className="member-avatar" src={m.photo_url} alt="" width={42} height={42} loading="lazy" decoding="async" />
                : <span className="member-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
              <span className="member-main">
                <span className="member-name">{m.full_name}</span>
                <span className="member-meta">{[m.tribe, ...m.departments].filter(Boolean).join(' · ') || 'Sans tribu ni département'}</span>
                <span className="member-flags">
                  {m.absent_days != null && m.activity === 'active' && <span className="flag warn" title="Aucune connexion depuis plus d'une semaine">Absent {m.absent_days} j</span>}
                  {m.completion < 100 && <span className="flag warn" title="Profil incomplet">Profil {m.completion} %</span>}
                  {m.fiss_current === false && m.activity === 'active' && <span className="flag" title="FISS du mois non remplie">FISS à remplir</span>}
                </span>
              </span>
              <span className="member-roles">
                {m.roles.slice(0, 2).map((r) => <span key={r} className="badge">{r}</span>)}
              </span>
              <span className={`status-dot ${m.activity === 'active' ? 'on' : 'off'}`} title={m.activity === 'active' ? 'Actif' : 'Inactif'} />
            </button>
          ))}
          {!loading && loadError && <LoadError compact message={loadError} onRetry={() => { setLoadError(''); setLoading(true); setRefresh((n) => n + 1) }} />}
          {!loading && !loadError && members.length === 0 && <p className="helper center">Aucun membre trouvé.</p>}
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
