import { useState, type ReactNode } from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { NotificationBell } from './NotificationBell'
import { OfflineBanner } from './OfflineBanner'
import { useUnreadCount } from '../notifications'
import { Icon } from './Icon'
import { ICONS } from '../utils/icons'

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
              : <span className="sidebar-avatar">{initials || <Icon name="profile" size={18} />}</span>}
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
