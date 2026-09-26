import { useState, type ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { NotificationBell } from './NotificationBell'
import { OfflineBanner } from './OfflineBanner'
import { useUnreadCount } from '../notifications'

function Icon({ path }: { path: string }) {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
      strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">{<path d={path} />}</svg>
  )
}

const ICONS = {
  dashboard: 'M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z',
  members: 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
  profile: 'M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8z',
  org: 'M3 21h18M5 21V7l8-4v18M19 21V11l-6-3',
  roles: 'M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z',
  attendance: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 16l2 2 4-4',
  exercises: 'M4 19.5A2.5 2.5 0 0 1 6.5 17H20M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z',
  announce: 'M3 11l18-5v12L3 14v-3zM11.6 16.8a3 3 0 1 1-5.8-1.6',
  events: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z',
  fiss: 'M9 11l3 3 8-8M20 12v6a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h9',
  requests: 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
  spiritual: 'M12 21s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 5.65-7 10-7 10z',
  calendar: 'M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM8 14h.01M12 14h.01M16 14h.01M8 18h.01M12 18h.01',
  serve: 'M11 14h2a2 2 0 1 0 0-4h-3c-.6 0-1.1.2-1.4.6L3 16M7 20l1.6-1.4c.3-.4.8-.6 1.4-.6h4c1.1 0 2.1-.4 2.8-1.2l4.6-4.4a2 2 0 0 0-2.75-2.91l-4.2 3.9M2 15l6 6M19.5 8.5c.7-.7 1.5-1.6 1.5-2.7A2.73 2.73 0 0 0 16 4a2.78 2.78 0 0 0-5 1.8c0 1.2.8 2 1.5 2.8L16 12Z',
  bell: 'M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 0 1-3.46 0',
  book: 'M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2zM22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z',
  play: 'M12 22c5.5 0 10-4.5 10-10S17.5 2 12 2 2 6.5 2 12s4.5 10 10 10zM10 8l6 4-6 4z',
  validate: 'M9 12l2 2 4-4M12 22c5.5 0 10-4.5 10-10S17.5 2 12 2 2 6.5 2 12s4.5 10 10 10z',
  reports: 'M3 3v18h18M7 15l4-4 3 3 5-6',
  audit: 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h5',
  gem: 'M12 12a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM5 20a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM19 20a3 3 0 1 0 0-6 3 3 0 0 0 0 6zM10.5 11.5l-4 3.5M13.5 11.5l4 3.5',
  menu: 'M4 6h16M4 12h16M4 18h16',
  logout: 'M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9',
}

export function AppLayout({ title, subtitle, actions, children }: {
  title: string
  subtitle?: string
  actions?: ReactNode
  children: ReactNode
}) {
  const { profile, user, roles, hasPermission, logout } = useAuth()
  const navigate = useNavigate()
  const canViewMembers = hasPermission('members.view_all') || hasPermission('members.view_scope')
  const canManageRoles = hasPermission('roles.manage')
  const canManageOrg = hasPermission('tribes.manage') || hasPermission('departments.manage')
  const canManageGems = hasPermission('gems.manage')
  const canAttendance = hasPermission('attendance.view')
  const canExercises = hasPermission('exercises.assign')
  const canAnnounce = hasPermission('announcements.publish')
  const canEvents = hasPermission('events.manage')
  const canRequests = hasPermission('requests.handle')
  const canValidate = hasPermission('fiss.review') || hasPermission('tribes.transfer')
  const canReports = hasPermission('reports.view')
  const canAudit = hasPermission('audit.view')
  const canContent = hasPermission('content.manage')

  const isLeader = canViewMembers || canAttendance || canExercises || canAnnounce || canEvents
    || canRequests || canManageRoles || canManageGems || canManageOrg || canValidate || canReports || canAudit || canContent

  const initials = ((profile?.first_name?.[0] ?? '') + (profile?.last_name?.[0] ?? '')).toUpperCase()
  const mainRole = roles[0]?.name ?? 'Fidèle'
  const [menuOpen, setMenuOpen] = useState(false)
  const unread = useUnreadCount()

  async function onLogout() {
    await logout()
    navigate('/connexion', { replace: true })
  }

  return (
    <div className="app">
      <a className="skip-link" href="#contenu">Aller au contenu</a>
      <OfflineBanner />
      {menuOpen && <div className="menu-overlay" onClick={() => setMenuOpen(false)} />}
      <aside className={`sidebar ${menuOpen ? 'open' : ''}`}>
        <div className="sidebar-brand">
          <div className="sidebar-logo"><img src="/logo-vh.png" alt="" /></div>
          <div className="sidebar-brand-text">
            <span>Vases d'Honneur</span>
            <small>Chicoutimi</small>
          </div>
        </div>

        {/* Menu simplifie : « Mon espace » pour tous, « Gestion » pour les responsables. */}
        <nav className="sidebar-nav" onClick={() => setMenuOpen(false)}>
          {isLeader && <span className="nav-section">Mon espace</span>}
          <NavLink to="/tableau-de-bord" className="nav-item"><Icon path={ICONS.dashboard} /><span>Accueil</span></NavLink>
          <NavLink to="/calendrier" className="nav-item"><Icon path={ICONS.calendar} /><span>Calendrier</span></NavLink>
          <NavLink to="/servir" className="nav-item"><Icon path={ICONS.serve} /><span>Service</span></NavLink>
          <NavLink to="/ma-vie-spirituelle" className="nav-item"><Icon path={ICONS.spiritual} /><span>Ma vie spirituelle</span></NavLink>
          <NavLink to="/ma-fiche" className="nav-item"><Icon path={ICONS.fiss} /><span>Ma fiche (FISS)</span></NavLink>
          <NavLink to="/exercices" className="nav-item"><Icon path={ICONS.play} /><span>Mes exercices</span></NavLink>
          <NavLink to="/contact" className="nav-item"><Icon path={ICONS.requests} /><span>Nous contacter</span></NavLink>

          {isLeader && <span className="nav-section">Gestion</span>}
          {canViewMembers && <NavLink to="/admin/membres" className="nav-item"><Icon path={ICONS.members} /><span>Membres</span></NavLink>}
          {canReports && <NavLink to="/admin/rapports" className="nav-item"><Icon path={ICONS.reports} /><span>Rapports</span></NavLink>}
          {canValidate && <NavLink to="/admin/validations" className="nav-item"><Icon path={ICONS.validate} /><span>Validations</span></NavLink>}
          {canAttendance && <NavLink to="/admin/presences" className="nav-item"><Icon path={ICONS.attendance} /><span>Présences</span></NavLink>}
          {canRequests && <NavLink to="/admin/demandes" className="nav-item"><Icon path={ICONS.requests} /><span>Demandes</span></NavLink>}
          {canAnnounce && <NavLink to="/admin/annonces" className="nav-item"><Icon path={ICONS.announce} /><span>Annonces</span></NavLink>}
          {canEvents && <NavLink to="/admin/evenements" className="nav-item"><Icon path={ICONS.events} /><span>Événements</span></NavLink>}
          {canExercises && <NavLink to="/admin/exercices" className="nav-item"><Icon path={ICONS.exercises} /><span>Exercices</span></NavLink>}
          {canManageGems && <NavLink to="/admin/gems" className="nav-item"><Icon path={ICONS.gem} /><span>GEMs</span></NavLink>}
          {canManageOrg && <NavLink to="/admin/organisation" className="nav-item"><Icon path={ICONS.org} /><span>Organisation</span></NavLink>}
          {canManageRoles && <NavLink to="/admin/roles" className="nav-item"><Icon path={ICONS.roles} /><span>Rôles</span></NavLink>}
          {canContent && <NavLink to="/admin/versets" className="nav-item"><Icon path={ICONS.book} /><span>Versets</span></NavLink>}
          {canAudit && <NavLink to="/admin/journal" className="nav-item"><Icon path={ICONS.audit} /><span>Journal</span></NavLink>}
        </nav>

        <div className="sidebar-foot">
          {/* Carte utilisateur = acces a « Mon profil » (une entree de menu en moins). */}
          <NavLink to="/mon-profil" className="sidebar-user" onClick={() => setMenuOpen(false)} title="Mon profil">
            {profile?.photo_url
              ? <img className="sidebar-avatar" src={profile.photo_url} alt="" />
              : <span className="sidebar-avatar">{initials || '🙂'}</span>}
            <div className="sidebar-user-text">
              <span>{profile?.full_name || user?.phone}</span>
              <small>{mainRole} · Mon profil</small>
            </div>
          </NavLink>
          <button className="nav-item nav-logout" onClick={onLogout}><Icon path={ICONS.logout} /><span>Déconnexion</span></button>
        </div>
      </aside>

      <main className="main">
        <header className="topbar">
          <button className="menu-toggle" onClick={() => setMenuOpen(true)} aria-label="Ouvrir le menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
          </button>
          <div className="topbar-head">
            <h1 className="topbar-title">{title}</h1>
            {subtitle && <p className="topbar-sub">{subtitle}</p>}
          </div>
          <div className="topbar-right">
            {actions && <div className="topbar-actions">{actions}</div>}
            <NotificationBell />
          </div>
        </header>
        <div className="content" id="contenu" tabIndex={-1}>{children}</div>
      </main>

      {/* Telephone : barre d'onglets en bas pour l'essentiel, a portee de pouce. */}
      <nav className="bottom-nav" aria-label="Navigation principale">
        <NavLink to="/tableau-de-bord" className="bottom-item"><Icon path={ICONS.dashboard} /><span>Accueil</span></NavLink>
        <NavLink to="/calendrier" className="bottom-item"><Icon path={ICONS.calendar} /><span>Calendrier</span></NavLink>
        <NavLink to="/servir" className="bottom-item"><Icon path={ICONS.serve} /><span>Service</span></NavLink>
        <NavLink to="/notifications" className="bottom-item">
          <span className="bottom-icon"><Icon path={ICONS.bell} />{unread > 0 && <span className="bottom-badge">{unread > 9 ? '9+' : unread}</span>}</span>
          <span>Alertes</span>
        </NavLink>
        <button className={`bottom-item ${menuOpen ? 'active' : ''}`} onClick={() => setMenuOpen(true)}><Icon path={ICONS.menu} /><span>Menu</span></button>
      </nav>
    </div>
  )
}
