import { createContext, Suspense, useContext, useEffect, useState, type MouseEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { NavLink, Outlet, useLocation, useNavigate, useNavigationType } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { NotificationBell } from './NotificationBell'
import { OfflineBanner } from './OfflineBanner'
import { useUnreadCount } from '../notifications'
import { Icon } from './Icon'
import { ICONS } from '../utils/icons'
import { APP_NAME } from '../config'
import { warmRoute } from '../utils/routeData'
import { PushSettings } from './PushSettings'
import { usePulse } from '../pulse'

/**
 * Cadre persistant : menu lateral, en-tete et barre du bas sont affiches une seule fois pour
 * toutes les pages connectees. Changer de page ne remplace que le contenu (et le titre de
 * l'en-tete) : les menus ne se rechargent plus et ne clignotent plus.
 */
const ShellSlots = createContext<{ head: HTMLElement | null; actions: HTMLElement | null } | null>(null)

/** Route parente des pages connectees (voir App.tsx). */
export function ShellLayout() {
  const { loading, isAuthenticated } = useAuth()
  const [head, setHead] = useState<HTMLElement | null>(null)
  const [actions, setActions] = useState<HTMLElement | null>(null)
  const { pathname } = useLocation()
  const navigationType = useNavigationType()

  // Reglages de la plateforme modifies (modules actives...) : le compte est relu, menus et pages suivent.
  const { refresh } = useAuth()
  usePulse('settings', () => { void refresh() }, isAuthenticated)

  // Nouvelle page : on repart du haut (le retour arriere garde la position du navigateur).
  useEffect(() => {
    if (navigationType !== 'POP') window.scrollTo(0, 0)
  }, [pathname, navigationType])

  // Session en cours de verification ou absente : les gardes des routes (RequireAuth) decident.
  if (loading || !isAuthenticated) {
    return <Suspense fallback={<div className="loading-screen"><span className="spinner" /></div>}><Outlet /></Suspense>
  }
  return (
    <ShellSlots.Provider value={{ head, actions }}>
      <Frame head={<div ref={setHead} className="topbar-slot" />} actions={<div ref={setActions} className="topbar-slot" />}>
        {/* Premier affichage d'une page jamais ouverte : petit indicateur dans le contenu, le cadre reste. */}
        {/* Notifications pas encore activees sur cet appareil : invitation en un clic, sur toutes les pages. */}
        <PushSettings variant="prompt" />
        <Suspense fallback={<div className="content-loading" role="status" aria-label="Chargement"><span className="spinner" /></div>}>
          <Outlet />
        </Suspense>
      </Frame>
    </ShellSlots.Provider>
  )
}

/** Mise en page d'une page : son titre et ses boutons vont dans l'en-tete du cadre persistant. */
export function AppLayout({ title, subtitle, actions, children }: {
  title: string
  subtitle?: string
  actions?: ReactNode
  children: ReactNode
}) {
  const slots = useContext(ShellSlots)
  const headNode = (
    <div className="topbar-head">
      <h1 className="topbar-title">{title}</h1>
      {subtitle && <p className="topbar-sub">{subtitle}</p>}
    </div>
  )
  const actionsNode = actions ? <div className="topbar-actions">{actions}</div> : null
  if (slots) {
    return (
      <>
        {slots.head && createPortal(headNode, slots.head)}
        {slots.actions && actionsNode && createPortal(actionsNode, slots.actions)}
        {children}
      </>
    )
  }
  // Hors du cadre persistant (cas de secours) : cadre complet.
  return <Frame head={headNode} actions={actionsNode}>{children}</Frame>
}

function Frame({ head, actions, children }: { head: ReactNode; actions: ReactNode; children: ReactNode }) {
  const { profile, user, roles, hasPermission, isPlatformManager, logout, moduleOn } = useAuth()
  const navigate = useNavigate()
  const canViewMembers = hasPermission('members.view_all') || hasPermission('members.view_scope')
  const canManageRoles = hasPermission('roles.manage')
  const canAccounts = hasPermission('accounts.view')
  const canPlatform = hasPermission('platform.manage')
  const canSettings = hasPermission('settings.manage')
  const canManageOrg = hasPermission('tribes.manage') || hasPermission('departments.manage')
  const canManageGems = hasPermission('gems.manage')
  const canAttendance = hasPermission('attendance.view')
  const canExercises = hasPermission('exercises.assign') && moduleOn('exercises')
  const canAnnounce = hasPermission('announcements.publish')
  const canEvents = hasPermission('events.manage')
  const canRequests = hasPermission('requests.handle')
  const canValidate = hasPermission('fiss.review') || hasPermission('tribes.transfer') || hasPermission('members.approve') || hasPermission('departments.approve')
  const canReports = hasPermission('reports.view')
  const canAudit = hasPermission('audit.view')
  const canVerses = hasPermission('verses.view')
  const canSchedule = hasPermission('schedule.view')
  const canResources = hasPermission('resources.view')
  // Rapports mensuels : a remplir (patriarche, responsable de departement) ou a lire (rapports).
  const canLeaderReports = canReports
    || roles.some((r) => (r.key === 'patriarche' && r.scope_kind === 'tribe') || r.key === 'department_leader')
  // Annuaire des responsables : pasteurs (toute l'eglise), AP et patriarches (leurs tribus).
  const canLeaders = hasPermission('members.view_all')
    || (hasPermission('members.view_scope') && roles.some((r) => r.scope_kind === 'tribe' && r.key !== 'department_leader'))
  // Rapport hebdomadaire : le Garde d'un GEM.
  const isGarde = roles.some((r) => r.key === 'garde' && r.scope_kind === 'gem')
  // Suivi du mois : publication des devoirs, et suivi (pasteurs, patriarches et AP de leurs tribus).
  const canTrack = hasPermission('spiritual.view') && (hasPermission('members.view_all') || roles.some((r) => r.scope_kind === 'tribe'))
  const canGrowth = (hasPermission('assignments.publish') || canTrack) && (moduleOn('assignments') || moduleOn('resolutions'))

  // Rendez-vous : le pasteur les recoit (il n'en prend pas) ; le protocole les organise.
  const isPastorHost = hasPermission('appointments.host')
  const canAppointments = (isPastorHost || hasPermission('appointments.organize')) && moduleOn('appointments')
  const isLeader = canAppointments || canViewMembers || canAttendance || canExercises || canAnnounce || canEvents
    || canRequests || canManageRoles || canAccounts || canPlatform || canSettings || canManageGems || canManageOrg || canValidate || canReports
    || canLeaderReports || canAudit || canVerses || canSchedule || canResources || canGrowth

  const initials = ((profile?.first_name?.[0] ?? '') + (profile?.last_name?.[0] ?? '')).toUpperCase()
  const mainRole = roles[0]?.name ?? 'Fidèle'
  const [menuOpen, setMenuOpen] = useState(false)
  const unread = useUnreadCount()
  // Lien clique : fine barre de progression tant que la page suivante n'est pas affichee
  // (l'ancienne reste visible pendant ce temps, sans ecran blanc).
  const location = useLocation()
  const [leavingKey, setLeavingKey] = useState<string | null>(null)
  const navigating = leavingKey === location.key
  function onLinkClick(e: MouseEvent<HTMLDivElement>) {
    const link = (e.target as Element).closest('a[href]')
    const href = link?.getAttribute('href') ?? ''
    if (!link || e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || link.getAttribute('target')) return
    if (href.startsWith('/') && !href.startsWith('//')) setLeavingKey(location.key)
  }
  // Intention d'ouvrir une page (survol, toucher, clavier) : ses donnees partent tout de suite.
  function onLinkIntent(e: { target: EventTarget }) {
    const href = (e.target as Element).closest?.('a[href]')?.getAttribute('href')
    if (href?.startsWith('/')) warmRoute(href)
  }

  async function onLogout() {
    await logout()
    navigate('/connexion', { replace: true })
  }

  return (
    <div className="app" onClickCapture={onLinkClick} onMouseOver={onLinkIntent} onTouchStart={onLinkIntent} onFocus={onLinkIntent}>
      {navigating && <div className="nav-progress" aria-hidden />}
      <a className="skip-link" href="#contenu">Aller au contenu</a>
      <OfflineBanner />
      {menuOpen && <div className="menu-overlay" onClick={() => setMenuOpen(false)} />}
      <aside className={`sidebar ${menuOpen ? 'open' : ''}`}>
        <div className="sidebar-brand">
          <div className="sidebar-logo"><img src="/logo-vh.png" alt="" /></div>
          <div className="sidebar-brand-text">
            <span>{APP_NAME}</span>
            <small>Chicoutimi</small>
          </div>
        </div>

        {/* Menu simplifie : « Mon espace » pour les membres, « Gestion » pour les responsables. */}
        <nav className="sidebar-nav" onClick={() => setMenuOpen(false)}>
          {!isPlatformManager && (
            <>
              {isLeader && <span className="nav-section">Mon espace</span>}
              <NavLink to="/tableau-de-bord" className="nav-item"><Icon path={ICONS.dashboard} /><span>Accueil</span></NavLink>
              <NavLink to="/calendrier" className="nav-item"><Icon path={ICONS.calendar} /><span>Calendrier</span></NavLink>
              <NavLink to="/servir" className="nav-item"><Icon path={ICONS.serve} /><span>Service</span></NavLink>
              {moduleOn('spiritual') && <NavLink to="/ma-vie-spirituelle" className="nav-item"><Icon path={ICONS.spiritual} /><span>Ma vie spirituelle</span></NavLink>}
              <NavLink to="/ma-fiche" className="nav-item"><Icon path={ICONS.fiss} /><span>Ma fiche (FISS)</span></NavLink>
              {moduleOn('vertumetre') && <NavLink to="/vertumetre" className="nav-item"><Icon path={ICONS.reports} /><span>Mon Vertumètre</span></NavLink>}
              {moduleOn('resolutions') && <NavLink to="/mes-resolutions" className="nav-item"><Icon path={ICONS.target} /><span>Mes RM</span></NavLink>}
              {moduleOn('assignments') && <NavLink to="/mon-devoir" className="nav-item"><Icon path={ICONS.devoir} /><span>Mon devoir (DS)</span></NavLink>}
              {moduleOn('exercises') && <NavLink to="/exercices" className="nav-item"><Icon path={ICONS.play} /><span>Mes exercices</span></NavLink>}
              {!isPastorHost && moduleOn('appointments') && <NavLink to="/rendez-vous" className="nav-item"><Icon path={ICONS.clock} /><span>Prendre rendez-vous</span></NavLink>}
              <NavLink to="/contact" className="nav-item"><Icon path={ICONS.requests} /><span>Nous contacter</span></NavLink>
            </>
          )}

          {isLeader && <span className="nav-section">Gestion</span>}
          {canPlatform && <NavLink to="/admin/gestion" className="nav-item"><Icon path={ICONS.dashboard} /><span>Tableau de gestion</span></NavLink>}
          {canViewMembers && <NavLink to="/admin/membres" className="nav-item"><Icon path={ICONS.members} /><span>Membres</span></NavLink>}
          {canLeaders && <NavLink to="/admin/responsables" className="nav-item"><Icon path={ICONS.roles} /><span>Responsables</span></NavLink>}
          {isGarde && <NavLink to="/admin/rapport-gem" className="nav-item"><Icon path={ICONS.gem} /><span>Rapport de GEM</span></NavLink>}
          {canReports && <NavLink to="/admin/rapports" className="nav-item"><Icon path={ICONS.reports} /><span>Rapports</span></NavLink>}
          {canLeaderReports && <NavLink to="/admin/rapports-mensuels" className="nav-item"><Icon path={ICONS.clipboard} /><span>Rapports mensuels</span></NavLink>}
          {canGrowth && <NavLink to="/admin/devoirs-rm" className="nav-item"><Icon path={ICONS.target} /><span>{canTrack ? 'Suivi du mois' : 'Devoirs (DS)'}</span></NavLink>}
          {canAppointments && <NavLink to="/admin/rendez-vous" className="nav-item"><Icon path={ICONS.clock} /><span>Rendez-vous</span></NavLink>}
          {canValidate && <NavLink to="/admin/validations" className="nav-item"><Icon path={ICONS.validate} /><span>Validations</span></NavLink>}
          {canAttendance && <NavLink to="/admin/presences" className="nav-item"><Icon path={ICONS.attendance} /><span>Présences</span></NavLink>}
          {canRequests && <NavLink to="/admin/demandes" className="nav-item"><Icon path={ICONS.requests} /><span>Demandes</span></NavLink>}
          {canAnnounce && <NavLink to="/admin/annonces" className="nav-item"><Icon path={ICONS.announce} /><span>Annonces</span></NavLink>}
          {canEvents && <NavLink to="/admin/evenements" className="nav-item"><Icon path={ICONS.events} /><span>Événements</span></NavLink>}
          {canExercises && <NavLink to="/admin/exercices" className="nav-item"><Icon path={ICONS.exercises} /><span>Exercices</span></NavLink>}
          {canResources && <NavLink to="/admin/ressources" className="nav-item"><Icon path={ICONS.link} /><span>Ressources</span></NavLink>}
          {canVerses && <NavLink to="/admin/versets" className="nav-item"><Icon path={ICONS.book} /><span>Versets</span></NavLink>}
          {canSchedule && <NavLink to="/admin/horaires" className="nav-item"><Icon path={ICONS.clock} /><span>Horaires des cultes</span></NavLink>}
          {canManageGems && <NavLink to="/admin/gems" className="nav-item"><Icon path={ICONS.gem} /><span>GEMs</span></NavLink>}
          {canManageOrg && <NavLink to="/admin/organisation" className="nav-item"><Icon path={ICONS.org} /><span>Organisation</span></NavLink>}
          {canAccounts && <NavLink to="/admin/comptes" className="nav-item"><Icon path={ICONS.profile} /><span>Comptes</span></NavLink>}
          {canManageRoles && <NavLink to="/admin/roles" className="nav-item"><Icon path={ICONS.roles} /><span>Rôles</span></NavLink>}
          {canAudit && <NavLink to="/admin/journal" className="nav-item"><Icon path={ICONS.audit} /><span>Journal</span></NavLink>}
          {canSettings && <NavLink to="/admin/apparence" className="nav-item"><Icon path={ICONS.image} /><span>Apparence</span></NavLink>}
          {canSettings && <NavLink to="/admin/reglages" className="nav-item"><Icon path={ICONS.settings} /><span>Réglages</span></NavLink>}
        </nav>

        <div className="sidebar-foot">
          {/* Carte utilisateur = acces a « Mon profil » (une entree de menu en moins). Compte de gestion : pas de profil. */}
          {isPlatformManager ? (
            <div className="sidebar-user">
              <span className="sidebar-avatar"><Icon path={ICONS.roles} size={18} /></span>
              <div className="sidebar-user-text">
                <span>Administration</span>
                <small>Compte de gestion</small>
              </div>
            </div>
          ) : (
            <NavLink to="/mon-profil" className="sidebar-user" onClick={() => setMenuOpen(false)} title="Mon profil">
              {profile?.photo_url
                ? <img className="sidebar-avatar" src={profile.photo_url} alt="" />
                : <span className="sidebar-avatar">{initials || <Icon name="profile" size={18} />}</span>}
              <div className="sidebar-user-text">
                <span>{profile?.full_name || user?.phone}</span>
                <small>{mainRole} · Mon profil</small>
              </div>
            </NavLink>
          )}
          <button className="nav-item nav-logout" onClick={onLogout}><Icon path={ICONS.logout} /><span>Déconnexion</span></button>
        </div>
      </aside>

      <main className="main">
        <header className="topbar">
          <button className="menu-toggle" onClick={() => setMenuOpen(true)} aria-label="Ouvrir le menu">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
          </button>
          {head}
          <div className="topbar-right">
            {actions}
            <NotificationBell />
          </div>
        </header>
        <div className="content" id="contenu" tabIndex={-1}>{children}</div>
      </main>

      {/* Telephone : barre d'onglets en bas pour l'essentiel, a portee de pouce. */}
      <nav className="bottom-nav" aria-label="Navigation principale">
        {isPlatformManager ? (
          <>
            <NavLink to="/admin/gestion" className="bottom-item"><Icon path={ICONS.dashboard} /><span>Gestion</span></NavLink>
            <NavLink to="/admin/comptes" className="bottom-item"><Icon path={ICONS.profile} /><span>Comptes</span></NavLink>
            <NavLink to="/admin/ressources" className="bottom-item"><Icon path={ICONS.link} /><span>Ressources</span></NavLink>
          </>
        ) : (
          <>
            <NavLink to="/tableau-de-bord" className="bottom-item"><Icon path={ICONS.dashboard} /><span>Accueil</span></NavLink>
            <NavLink to="/calendrier" className="bottom-item"><Icon path={ICONS.calendar} /><span>Calendrier</span></NavLink>
            <NavLink to="/servir" className="bottom-item"><Icon path={ICONS.serve} /><span>Service</span></NavLink>
          </>
        )}
        <NavLink to="/notifications" className="bottom-item">
          <span className="bottom-icon"><Icon path={ICONS.bell} />{unread > 0 && <span className="bottom-badge">{unread > 9 ? '9+' : unread}</span>}</span>
          <span>Alertes</span>
        </NavLink>
        <button className={`bottom-item ${menuOpen ? 'active' : ''}`} onClick={() => setMenuOpen(true)}><Icon path={ICONS.menu} /><span>Menu</span></button>
      </nav>
    </div>
  )
}
