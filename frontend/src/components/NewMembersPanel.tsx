import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { usePulse } from '../pulse'
import type { NewMember, NewMemberCounts } from '../types'
import { Skeleton } from './Skeleton'

function ago(days: number | null): string {
  if (days === null) return ''
  if (days === 0) return "Inscrit aujourd'hui"
  if (days === 1) return 'Inscrit hier'
  return `Inscrit il y a ${days} jours`
}

/**
 * Nouveaux inscrits : liste de suivi d'accueil. Un inscrit reste « a accueillir » tant
 * qu'un responsable ne l'a pas marque comme accueilli ; seules les inscriptions des
 * 30 derniers jours sont gardees, la liste se vide donc d'elle-meme.
 */
export function NewMembersPanel() {
  const navigate = useNavigate()
  const [filter, setFilter] = useState<'to_welcome' | 'all'>('to_welcome')
  const [items, setItems] = useState<NewMember[] | null>(null)
  const [counts, setCounts] = useState<NewMemberCounts | null>(null)
  const [busy, setBusy] = useState<number | null>(null)
  const [windowDays, setWindowDays] = useState(30)

  const load = useCallback(() => {
    api<{ members: NewMember[]; counts: NewMemberCounts; window_days: number }>(`/admin/new-members?filter=${filter}`)
      .then((r) => { setItems(r.members); setCounts(r.counts); setWindowDays(r.window_days) })
      .catch(() => setItems((p) => p ?? []))
  }, [filter])

  useEffect(() => { setItems(null); load() }, [load])
  usePulse('members', load)

  async function toggleWelcome(m: NewMember) {
    setBusy(m.user_id)
    try {
      const r = m.welcomed
        ? await api<{ counts: NewMemberCounts }>(`/admin/members/${m.user_id}/welcome`, { method: 'DELETE' })
        : await api<{ counts: NewMemberCounts }>(`/admin/members/${m.user_id}/welcome`, { method: 'POST' })
      setCounts(r.counts)
      setItems((prev) => {
        if (!prev) return prev
        if (filter === 'to_welcome' && !m.welcomed) return prev.filter((x) => x.user_id !== m.user_id)
        return prev.map((x) => x.user_id === m.user_id ? { ...x, welcomed: !m.welcomed, welcomed_by: m.welcomed ? null : 'vous' } : x)
      })
    } finally { setBusy(null) }
  }

  const shown = (items ?? []).slice(0, 8)

  return (
    <section className="panel newcomers">
      <div className="panel-head">
        <div>
          <h3>Nouveaux inscrits</h3>
          <p className="panel-sub">{windowDays} derniers jours · à accueillir et intégrer</p>
        </div>
        <button className="btn-link" onClick={() => navigate('/admin/membres')}>Tous les membres</button>
      </div>

      <div className="tabs small">
        <button className={`tab ${filter === 'to_welcome' ? 'active' : ''}`} onClick={() => setFilter('to_welcome')}>
          À accueillir{counts && counts.to_welcome > 0 && <span className="count-pill">{counts.to_welcome}</span>}
        </button>
        <button className={`tab ${filter === 'all' ? 'active' : ''}`} onClick={() => setFilter('all')}>
          Tous{counts ? ` (${counts.recent})` : ''}
        </button>
      </div>

      <div className="mini-list mt">
        {items === null && [0, 1, 2].map((i) => <Skeleton key={i} className="skeleton-row" />)}
        {shown.map((m) => (
          <div key={m.user_id} className="newcomer-row">
            <button className="mini-row" onClick={() => navigate(`/admin/membres/${m.user_id}`)}>
              {m.photo_url
                ? <img className="mini-avatar" src={m.photo_url} alt="" />
                : <span className="mini-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
              <span className="newcomer-text">
                <strong>{m.full_name}{m.days_ago !== null && m.days_ago <= 2 && <span className="new-tag">Nouveau</span>}</strong>
                <small>
                  {ago(m.days_ago)}{m.tribe ? ` · ${m.tribe}` : ''}
                  {!m.is_completed && ' · profil incomplet'}
                  {m.welcomed && ` · accueilli${m.welcomed_by ? ` par ${m.welcomed_by}` : ''}`}
                </small>
              </span>
            </button>
            <button className={`welcome-btn ${m.welcomed ? 'done' : ''}`} disabled={busy === m.user_id}
              onClick={() => toggleWelcome(m)} title={m.welcomed ? 'Annuler' : 'Marquer comme accueilli'}>
              {busy === m.user_id ? <span className="spinner" /> : m.welcomed ? '✓ Accueilli' : 'Accueillir'}
            </button>
          </div>
        ))}
        {items && items.length === 0 && (
          <div className="empty-state compact">
            <span aria-hidden>{filter === 'to_welcome' ? '🎉' : '👋'}</span>
            <p>{filter === 'to_welcome' ? 'Tous les nouveaux inscrits ont été accueillis.' : `Aucune inscription ces ${windowDays} derniers jours.`}</p>
          </div>
        )}
        {items && items.length > shown.length && (
          <p className="helper">+ {items.length - shown.length} autre(s)</p>
        )}
      </div>
    </section>
  )
}
