import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError, prefetch, peek } from '../api/client'
import { LoadError } from '../components/LoadError'
import { useAuth } from '../auth/AuthContext'
import { usePulse } from '../pulse'
import { AppLayout } from '../components/AppLayout'
import { MyExercises } from '../components/MyExercises'
import { AnnouncementsFeed } from '../components/AnnouncementsFeed'
import { EventsFeed } from '../components/EventsFeed'
import { ServiceSchedule } from '../components/ServiceSchedule'
import { dashboardData } from '../utils/routeData'
import { VerseBanner } from '../components/VerseBanner'
import { WelcomeBack } from '../components/WelcomeBack'
import { DashRequests } from '../components/DashRequests'
import { MonthJourney } from '../components/MonthJourney'
import { EmailInvite } from '../components/EmailInvite'
import { GemReportReminder, LeaderReportReminder } from '../components/LeaderReportReminder'
import { FissSummary } from '../components/FissSummary'
import { Skeleton } from '../components/Skeleton'
import { NewMembersPanel } from '../components/NewMembersPanel'
import type { Stats } from '../types'
import { useAppearance } from '../appearance'

/** Duree d'affichage du mot de bienvenue (ms). */
const WELCOME_MS = 6000

export default function Dashboard() {
  const { profile, roles, hasPermission, moduleOn } = useAuth()
  const texts = useAppearance().appearance.texts
  const navigate = useNavigate()
  const canViewMembers = hasPermission('members.view_all') || hasPermission('members.view_scope')
  const canViewAll = hasPermission('members.view_all')
  const canRequests = hasPermission('requests.handle')

  // Les lectures des blocs ci-dessous partent en un seul appel, avant leurs propres effets.
  useState(() => prefetch(dashboardData(moduleOn('exercises'))))

  const [stats, setStats] = useState<Stats | null>(() => (canViewMembers ? peek<Stats>('/admin/stats') ?? null : null))
  const [welcome, setWelcome] = useState(false)

  const [statsError, setStatsError] = useState('')
  // Echec : les derniers chiffres connus restent ; sinon message et « Réessayer » (pas de squelette sans fin).
  const loadStats = useCallback(() => {
    if (!canViewMembers) return
    setStatsError('')
    api<Stats>('/admin/stats').then(setStats).catch((err) => setStatsError(err instanceof ApiError ? err.firstMessage : 'Erreur.'))
  }, [canViewMembers])

  useEffect(() => { loadStats() }, [loadStats])
  usePulse(['members', 'fiss', 'attendance'], loadStats, canViewMembers)

  // Mot de bienvenue au tout premier passage, puis il disparait de lui-meme (fondu) apres quelques secondes.
  // Lecture du drapeau et minuterie separees : la minuterie ne peut pas etre annulee sans etre relancee.
  const [welcomeLeaving, setWelcomeLeaving] = useState(false)
  useEffect(() => {
    try {
      if (localStorage.getItem('evh_welcome') === '1') {
        localStorage.removeItem('evh_welcome')
        setWelcome(true)
      }
    } catch { /* ignore */ }
  }, [])
  useEffect(() => {
    if (!welcome) return
    const fade = window.setTimeout(() => setWelcomeLeaving(true), WELCOME_MS - 600)
    const hide = window.setTimeout(() => setWelcome(false), WELCOME_MS)
    return () => { window.clearTimeout(fade); window.clearTimeout(hide) }
  }, [welcome])

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
        <div className={`welcome-banner ${welcomeLeaving ? 'is-leaving' : ''}`} role="status">
          <div className="welcome-text">
            <strong>{texts.welcome_title}{profile?.first_name ? `, ${profile.first_name}` : ''} !</strong>
            <span>{texts.welcome_message}</span>
          </div>
          <button className="welcome-close" onClick={() => setWelcome(false)} aria-label="Fermer">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M18 6 6 18M6 6l12 12" /></svg>
          </button>
        </div>
      )}

      <WelcomeBack />
      <VerseBanner />
      <EmailInvite />
      <MonthJourney />

      <LeaderReportReminder />
      <GemReportReminder />
      <FissSummary />
      {canViewMembers ? (
        <>
          {stats === null && statsError ? (
            <LoadError message={statsError} onRetry={loadStats} compact />
          ) : stats === null ? (
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
              {stats.fiss_filled !== null && (
                <button className={`stat-tile ${stats.fiss_rate !== null && stats.fiss_rate < 50 ? 'stat-warn' : ''}`} onClick={() => navigate('/admin/membres?statut=sans-fiss')}>
                  <span className="stat-value">{stats.fiss_rate !== null ? `${stats.fiss_rate} %` : '-'}</span>
                  <span className="stat-label">FISS du mois ({stats.fiss_filled})</span>
                </button>
              )}
              <button className="stat-tile" onClick={() => navigate('/admin/membres?statut=incomplet')}>
                <span className="stat-value">{stats.incomplete_profiles}</span>
                <span className="stat-label">Profils incomplets</span>
              </button>
            </div>
          )}

          <div className="dash-main">
            <div className="dash-col dash-main-col">
              <NewMembersPanel />
              <EventsFeed limit={3} />
            </div>
            <div className="dash-col dash-side-col">
              <ServiceSchedule />
              {canViewAll && tribePanel}
              {canRequests && <DashRequests />}
              <AnnouncementsFeed />
              {moduleOn('exercises') && <MyExercises />}
            </div>
          </div>
        </>
      ) : (
        <div className="dash-main">
          <div className="dash-col dash-main-col">
            <EventsFeed limit={3} />
            <AnnouncementsFeed />
            {moduleOn('exercises') && <MyExercises />}
          </div>
          <div className="dash-col dash-side-col">
            <ServiceSchedule />
            <section className="panel">
              <div className="panel-head"><h3>Mes fonctions</h3></div>
              <div className="badges">
                {roles.map((r) => (
                  <span key={`${r.key}-${r.scope_kind ?? ''}-${r.scope_id ?? ''}`} className={`badge ${r.key === 'super_admin' ? 'badge-gold' : ''}`}>
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
                <button className="btn btn-primary small" onClick={() => navigate('/servir')}>Rejoindre un département</button>
                <button className="btn btn-ghost small" onClick={() => navigate('/mon-profil')}>Mon profil</button>
              </div>
            </section>
          </div>
        </div>
      )}
    </AppLayout>
  )
}
