import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { useAutoRefresh } from '../hooks/useAutoRefresh'
import { AppLayout } from '../components/AppLayout'
import { MyExercises } from '../components/MyExercises'
import { AnnouncementsFeed } from '../components/AnnouncementsFeed'
import { EventsFeed } from '../components/EventsFeed'
import { DashRequests } from '../components/DashRequests'
import { FissReminder } from '../components/FissReminder'
import { FissSummary } from '../components/FissSummary'
import { Skeleton } from '../components/Skeleton'
import { NewMembersPanel } from '../components/NewMembersPanel'
import { PushSettings } from '../components/PushSettings'
import type { Stats } from '../types'

export default function Dashboard() {
  const { profile, roles, hasPermission } = useAuth()
  const navigate = useNavigate()
  const canViewMembers = hasPermission('members.view_all') || hasPermission('members.view_scope')
  const canViewAll = hasPermission('members.view_all')
  const canRequests = hasPermission('requests.handle')

  const [stats, setStats] = useState<Stats | null>(null)
  const [welcome, setWelcome] = useState(false)

  const loadStats = useCallback(() => {
    if (canViewMembers) api<Stats>('/admin/stats').then(setStats).catch(() => setStats(null))
  }, [canViewMembers])

  useEffect(() => { loadStats() }, [loadStats])
  useAutoRefresh(loadStats, 20000, canViewMembers)

  // Mot de bienvenue au tout premier passage, puis il disparait de lui-meme.
  useEffect(() => {
    let show = false
    try {
      show = localStorage.getItem('evh_welcome') === '1'
      if (show) localStorage.removeItem('evh_welcome')
    } catch { /* ignore */ }
    if (!show) return
    setWelcome(true)
    const timer = setTimeout(() => setWelcome(false), 9000)
    return () => clearTimeout(timer)
  }, [])

  const maxTribe = Math.max(1, ...(stats?.by_tribe.map((t) => t.total) ?? [1]))

  const tribePanel = (
    <section className="panel">
      <div className="panel-head"><h3>Répartition par tribu</h3></div>
      <div className="bar-list">
        {stats?.by_tribe.map((t) => (
          <div key={t.name} className="bar-row">
            <span className="bar-name">{t.name}</span>
            <div className="bar-track"><div className="bar-fill" style={{ width: `${(t.total / maxTribe) * 100}%` }} /></div>
            <span className="bar-value">{t.total}</span>
          </div>
        ))}
        {stats && stats.by_tribe.length === 0 && <p className="helper">Aucune tribu renseignée pour l'instant.</p>}
      </div>
    </section>
  )

  return (
    <AppLayout title="Tableau de bord" subtitle={`Bonjour ${profile?.first_name || ''}`.trim()}>
      {welcome && (
        <div className="welcome-banner" role="status">
          <div className="welcome-text">
            <strong>Bienvenue dans ta famille spirituelle{profile?.first_name ? `, ${profile.first_name}` : ''} !</strong>
            <span>Nous sommes heureux de t'accueillir dans l'espace Vases d'Honneur Chicoutimi.</span>
          </div>
          <button className="welcome-close" onClick={() => setWelcome(false)} aria-label="Fermer">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
          </button>
        </div>
      )}

      <div className="verse-banner">
        <span className="verse-label">Notre appel</span>
        <p className="verse-text">
          Prenez donc garde à vous-mêmes, et à tout le troupeau au sein duquel le Saint-Esprit
          vous a établis évêques, pour paître l'Église de Dieu, qu'il s'est acquise par son propre sang.
        </p>
        <span className="verse-ref">Actes 20.28</span>
      </div>

      <PushSettings variant="prompt" />
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
                <span className="stat-label">Fidèles</span>
              </button>
              <button className="stat-tile stat-ok" onClick={() => navigate('/admin/membres?statut=actif')}>
                <span className="stat-value">{stats?.active ?? '-'}</span>
                <span className="stat-label">Actifs</span>
              </button>
              <button className="stat-tile stat-warn" onClick={() => navigate('/admin/membres?statut=inactif')}>
                <span className="stat-value">{stats?.inactive ?? '-'}</span>
                <span className="stat-label">Inactifs</span>
              </button>
              <button className={`stat-tile ${stats.fiss_rate !== null && stats.fiss_rate < 50 ? 'stat-warn' : ''}`} onClick={() => navigate('/admin/membres?statut=sans-fiss')}>
                <span className="stat-value">{stats.fiss_rate !== null ? `${stats.fiss_rate} %` : '-'}</span>
                <span className="stat-label">FISS du mois ({stats.fiss_filled})</span>
              </button>
              <button className="stat-tile" onClick={() => navigate('/admin/membres?statut=incomplet')}>
                <span className="stat-value">{stats.incomplete_profiles}</span>
                <span className="stat-label">Profils incomplets</span>
              </button>
            </div>
          )}

          <div className="dash-main">
            <div className="dash-col dash-main-col">
              <NewMembersPanel />
              <EventsFeed />
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
            <EventsFeed />
            <AnnouncementsFeed />
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
                  <span>Départements</span>
                  <strong>{profile?.departments && profile.departments.length > 0
                    ? profile.departments.map((d) => d.name).join(', ')
                    : 'Aucun'}</strong>
                </div>
              </div>
              <div className="editor-actions mt">
                <button className="btn btn-primary small" onClick={() => navigate('/servir')}>Service : rejoindre un département</button>
                <button className="btn btn-ghost small" onClick={() => navigate('/mon-profil')}>Mon profil</button>
              </div>
            </section>
          </div>
        </div>
      )}
    </AppLayout>
  )
}
