import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { AppLayout } from '../components/AppLayout'
import { MyExercises } from '../components/MyExercises'
import { AnnouncementsFeed } from '../components/AnnouncementsFeed'
import { EventsFeed } from '../components/EventsFeed'
import { DashRequests } from '../components/DashRequests'
import { FissReminder } from '../components/FissReminder'
import { FissSummary } from '../components/FissSummary'
import { Skeleton } from '../components/Skeleton'
import type { Stats } from '../types'

export default function Dashboard() {
  const { profile, roles, hasPermission } = useAuth()
  const navigate = useNavigate()
  const canViewMembers = hasPermission('members.view_all') || hasPermission('members.view_scope')
  const canViewAll = hasPermission('members.view_all')
  const canRequests = hasPermission('requests.handle')

  const [stats, setStats] = useState<Stats | null>(null)

  useEffect(() => {
    if (canViewMembers) api<Stats>('/admin/stats').then(setStats).catch(() => setStats(null))
  }, [canViewMembers])

  const maxTribe = Math.max(1, ...(stats?.by_tribe.map((t) => t.total) ?? [1]))

  const recentPanel = (
    <section className="panel">
      <div className="panel-head">
        <h3>Derniers inscrits</h3>
        <button className="btn-link" onClick={() => navigate('/admin/membres')}>Tout voir</button>
      </div>
      <div className="mini-list">
        {stats?.recent.map((m) => (
          <button key={m.user_id} className="mini-row" onClick={() => navigate(`/admin/membres/${m.user_id}`)}>
            {m.photo_url
              ? <img className="mini-avatar" src={m.photo_url} alt="" />
              : <span className="mini-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
            <span>{m.full_name}</span>
          </button>
        ))}
        {stats && stats.recent.length === 0 && <p className="helper">Aucun membre.</p>}
      </div>
    </section>
  )

  const tribePanel = (
    <section className="panel">
      <div className="panel-head"><h3>Repartition par tribu</h3></div>
      <div className="bar-list">
        {stats?.by_tribe.map((t) => (
          <div key={t.name} className="bar-row">
            <span className="bar-name">{t.name}</span>
            <div className="bar-track"><div className="bar-fill" style={{ width: `${(t.total / maxTribe) * 100}%` }} /></div>
            <span className="bar-value">{t.total}</span>
          </div>
        ))}
        {stats && stats.by_tribe.length === 0 && <p className="helper">Aucune tribu renseignee pour l'instant.</p>}
      </div>
    </section>
  )

  return (
    <AppLayout title="Tableau de bord" subtitle={`Bonjour ${profile?.first_name || ''}`.trim()}>
      <FissReminder />
      <FissSummary />
      {canViewMembers ? (
        <>
          {stats === null ? (
            <div className="stat-row">
              {[0, 1, 2, 3].map((i) => <Skeleton key={i} className="skeleton-tile" />)}
            </div>
          ) : (
            <div className="stat-row">
              <button className="stat-tile" onClick={() => navigate('/admin/membres')}>
                <span className="stat-value">{stats?.total ?? '-'}</span>
                <span className="stat-label">Fideles</span>
              </button>
              <button className="stat-tile stat-ok" onClick={() => navigate('/admin/membres?statut=actif')}>
                <span className="stat-value">{stats?.active ?? '-'}</span>
                <span className="stat-label">Actifs</span>
              </button>
              <button className="stat-tile stat-warn" onClick={() => navigate('/admin/membres?statut=inactif')}>
                <span className="stat-value">{stats?.inactive ?? '-'}</span>
                <span className="stat-label">Inactifs</span>
              </button>
              <button className="stat-tile" onClick={() => navigate('/admin/membres')}>
                <span className="stat-value">{stats?.completed ?? '-'}</span>
                <span className="stat-label">Profils completes</span>
              </button>
            </div>
          )}

          <div className="dash-main">
            <div className="dash-col dash-main-col">
              <EventsFeed />
              {recentPanel}
            </div>
            <div className="dash-col dash-side-col">
              {canViewAll && tribePanel}
              {canRequests && <DashRequests />}
              <AnnouncementsFeed />
              <MyExercises />
            </div>
          </div>
        </>
      ) : (
        <div className="dash-main">
          <div className="dash-col dash-main-col">
            <AnnouncementsFeed />
            <EventsFeed />
            <MyExercises />
          </div>
          <div className="dash-col dash-side-col">
            <section className="panel">
              <div className="panel-head"><h3>Mes fonctions</h3></div>
              <div className="badges">
                {roles.map((r) => (
                  <span key={r.key} className={`badge ${r.key === 'super_admin' ? 'badge-gold' : ''}`}>
                    {r.name}{r.scope_name ? ` · ${r.scope_name}` : ''}
                  </span>
                ))}
                {roles.length === 0 && <span className="helper">Aucune fonction pour le moment.</span>}
              </div>
            </section>
            <section className="panel">
              <div className="panel-head"><h3>Mon appartenance</h3></div>
              <div className="detail-list">
                <div className="detail-line"><span>Tribu</span><strong>{profile?.tribe?.name ?? 'Aucune'}</strong></div>
                <div className="detail-line">
                  <span>Departements</span>
                  <strong>{profile?.departments && profile.departments.length > 0
                    ? profile.departments.map((d) => d.name).join(', ')
                    : 'Aucun'}</strong>
                </div>
              </div>
              <button className="btn btn-ghost mt" onClick={() => navigate('/profil')}>Modifier mon profil</button>
            </section>
          </div>
        </div>
      )}
    </AppLayout>
  )
}
