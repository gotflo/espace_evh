import { lazy, Suspense, useEffect, type ComponentType, type ReactNode } from 'react'
import { matchPath, Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './auth/AuthContext'
import { ShellLayout } from './components/AppLayout'
import type { ModuleName } from './types'
import { InstallPrompt } from './components/InstallPrompt'
import { PullToRefresh } from './components/PullToRefresh'
import { Toaster } from './components/Toaster'
import { toast } from './toast'
import { api, auth, prefetch } from './api/client'
import { dashboardData } from './utils/routeData'
import { syncPush } from './push'
import { setUnread, getUnread } from './notifications'
import { pollNow } from './pulse'
import { rememberDestination } from './utils/afterLogin'

// Chargement a la demande : chaque page arrive dans son propre paquet,
// le demarrage de l'application est donc beaucoup plus rapide.
// Une fois connecte, les pages sont prechargees en arriere-plan (PrefetchPages) :
// ouvrir une page du menu est ensuite immediat.
const loaders: { load: () => Promise<unknown>; admin: boolean; path?: string }[] = []
function page(load: () => Promise<{ default: ComponentType }>, admin = false, path?: string) {
  let pending: Promise<{ default: ComponentType }> | undefined
  // Meme chargement pour le prechargement et l'affichage ; un echec (reseau) pourra etre retente.
  const once = () => (pending ??= load().catch((err) => { pending = undefined; throw err }))
  loaders.push({ load: once, admin, path })
  return lazy(once)
}
const Login = lazy(() => import('./pages/Login'))
const ProfileSetup = lazy(() => import('./pages/ProfileSetup'))
const MyVertumetre = page(() => import('./pages/MyVertumetre'), false, '/vertumetre')
const Dashboard = page(() => import('./pages/Dashboard'), false, '/tableau-de-bord')
const Members = page(() => import('./pages/admin/Members'), true, '/admin/membres')
const MemberDetail = page(() => import('./pages/admin/MemberDetail'), true, '/admin/membres/:id')
const RolesAdmin = page(() => import('./pages/admin/RolesAdmin'), true, '/admin/roles')
const Organization = page(() => import('./pages/admin/Organization'), true, '/admin/organisation')
const Gems = page(() => import('./pages/admin/Gems'), true, '/admin/gems')
const Attendance = page(() => import('./pages/admin/Attendance'), true, '/admin/presences')
const Exercises = page(() => import('./pages/admin/Exercises'), true, '/admin/exercices')
const Announcements = page(() => import('./pages/admin/Announcements'), true, '/admin/annonces')
const Events = page(() => import('./pages/admin/Events'), true, '/admin/evenements')
const Requests = page(() => import('./pages/admin/Requests'), true, '/admin/demandes')
const Contact = page(() => import('./pages/Contact'), false, '/contact')
const MySpiritual = page(() => import('./pages/MySpiritual'), false, '/ma-vie-spirituelle')
const MyProfile = page(() => import('./pages/MyProfile'), false, '/mon-profil')
const MyFiss = page(() => import('./pages/MyFiss'), false, '/ma-fiche')
const Calendar = page(() => import('./pages/Calendar'), false, '/calendrier')
const Services = page(() => import('./pages/Services'), false, '/servir')
const Notifications = page(() => import('./pages/Notifications'), false, '/notifications')
const Validations = page(() => import('./pages/admin/Validations'), true, '/admin/validations')
const MyExercisesPage = page(() => import('./pages/Exercises'), false, '/exercices')
const ExerciseDetail = page(() => import('./pages/ExerciseDetail'), false, '/exercices/:id')
const Reports = page(() => import('./pages/admin/Reports'), true, '/admin/rapports')
const LeaderReports = page(() => import('./pages/admin/LeaderReports'), true, '/admin/rapports-mensuels')
const Leaders = page(() => import('./pages/admin/Leaders'), true, '/admin/responsables')
const GemReport = page(() => import('./pages/admin/GemReport'), true, '/admin/rapport-gem')
const AuditLog = page(() => import('./pages/admin/AuditLog'), true, '/admin/journal')
const Verses = page(() => import('./pages/admin/Verses'), true, '/admin/versets')
const MyResolutions = page(() => import('./pages/MyResolutions'), false, '/mes-resolutions')
const MyAssignments = page(() => import('./pages/MyAssignments'), false, '/mon-devoir')
const Appointments = page(() => import('./pages/Appointments'), false, '/rendez-vous')
const AdminAppointments = page(() => import('./pages/admin/Appointments'), true, '/admin/rendez-vous')
const ServiceTimes = page(() => import('./pages/admin/ServiceTimes'), true, '/admin/horaires')
const Growth = page(() => import('./pages/admin/Growth'), true, '/admin/devoirs-rm')
const PlatformHome = page(() => import('./pages/admin/PlatformHome'), true, '/admin/gestion')
const Accounts = page(() => import('./pages/admin/Accounts'), true, '/admin/comptes')
const Resources = page(() => import('./pages/admin/Resources'), true, '/admin/ressources')
const Settings = page(() => import('./pages/admin/Settings'), true, '/admin/reglages')

// Ouverture de l'application deja connecte : la page demandee se telecharge pendant la
// verification du compte (au lieu d'attendre la fin de celle-ci), et l'accueil lit aussi
// ses donnees en meme temps. Le compte de gestion (appareil de gestion) n'a pas d'accueil membre.
if (auth.get()) {
  // L'application installee s'ouvre sur « / », qui mene a l'accueil.
  const here = window.location.pathname === '/' ? '/tableau-de-bord' : window.location.pathname
  loaders.find((l) => l.path && matchPath(l.path, here))?.load().catch(() => {})
  let managerDevice = false
  try { managerDevice = !!localStorage.getItem('evh_manager_device') } catch { /* stockage indisponible */ }
  if (here === '/tableau-de-bord' && !managerDevice) prefetch(dashboardData())
}

function Loading() {
  return <div className="loading-screen"><span className="spinner" /></div>
}

/** Redirection expliquee : l'utilisateur sait pourquoi il n'est pas sur la page demandee. */
function RedirectWithMessage({ to, message }: { to: string; message: string }) {
  useEffect(() => { toast.info(message) }, [message])
  return <Navigate to={to} replace />
}

/** Accueil : redirige selon l'etat de connexion. */
function Home() {
  const { loading, isAuthenticated, profileCompleted, isPlatformManager } = useAuth()
  if (loading) return <Loading />
  if (!isAuthenticated) return <Navigate to="/connexion" replace />
  if (isPlatformManager) return <Navigate to="/admin/gestion" replace />
  return <Navigate to={profileCompleted ? '/tableau-de-bord' : '/profil'} replace />
}

/** Route reservee aux connectes, avec permission(s) optionnelle(s). */
function RequireAuth({ children, requireComplete = false, anyPermission, memberSpace = false, module }: {
  children: ReactNode
  requireComplete?: boolean
  anyPermission?: string[]
  /** Page de l'espace membre : le compte de gestion (sans fiche de membre) va a son tableau de bord. */
  memberSpace?: boolean
  /** Page d'un module activable (Gestion > Reglages) : fermee quand il est desactive. */
  module?: ModuleName
}) {
  const { loading, isAuthenticated, profileCompleted, hasPermission, isPlatformManager, moduleOn } = useAuth()
  const location = useLocation()
  if (loading) return <Loading />
  if (!isAuthenticated) {
    // Lien d'un courriel ouvert sans session : la page demandee s'ouvrira juste apres la connexion.
    rememberDestination(location.pathname + location.search)
    return <Navigate to="/connexion" replace />
  }
  if (memberSpace && isPlatformManager) return <Navigate to="/admin/gestion" replace />
  if (requireComplete && !profileCompleted) return <Navigate to="/profil" replace />
  if (module && !moduleOn(module)) {
    return <RedirectWithMessage to={isPlatformManager ? '/admin/gestion' : '/tableau-de-bord'} message="Ce module n'est pas activé sur la plateforme." />
  }
  if (anyPermission && !anyPermission.some((p) => hasPermission(p))) {
    return <RedirectWithMessage to={isPlatformManager ? '/admin/gestion' : '/tableau-de-bord'} message="Cette page est réservée aux responsables concernés." />
  }
  return <>{children}</>
}

/** Route reservee aux visiteurs non connectes. */
function PublicOnly({ children }: { children: ReactNode }) {
  const { loading, isAuthenticated, profileCompleted, isPlatformManager } = useAuth()
  if (loading) return <Loading />
  if (isAuthenticated) return <Navigate to={isPlatformManager ? '/admin/gestion' : profileCompleted ? '/tableau-de-bord' : '/profil'} replace />
  return <>{children}</>
}

const MEMBER_PERMS = ['members.view_all', 'members.view_scope']

/**
 * Effets globaux : re-synchronise l'abonnement push de l'appareil une fois connecte,
 * et ouvre la bonne page quand on clique sur une notification (message du service worker).
 */
function PushBridge() {
  const { isAuthenticated } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()

  // Lecture automatique : ouvrir la page visee par une notification la marque comme lue.
  useEffect(() => {
    if (!isAuthenticated || ['/', '/tableau-de-bord', '/connexion', '/profil'].includes(location.pathname)) return
    const url = location.pathname + location.search
    api<{ count: number }>('/me/notifications/read-url', { method: 'POST', body: { url }, toast: false })
      .then((r) => { if (r.count > 0) setUnread(Math.max(0, getUnread() - r.count)) })
      .catch(() => {})
  }, [isAuthenticated, location.pathname, location.search])

  useEffect(() => {
    if (isAuthenticated) syncPush()
  }, [isAuthenticated])

  useEffect(() => {
    if (!('serviceWorker' in navigator)) return
    const onMessage = (e: MessageEvent) => {
      if (e.data?.type === 'evh-navigate' && typeof e.data.url === 'string') {
        const url = new URL(e.data.url, window.location.origin)
        if (url.origin === window.location.origin) navigate(url.pathname + url.search + url.hash)
        void pollNow()
      } else if (e.data?.type === 'evh-push' && typeof e.data.title === 'string' && document.visibilityState === 'visible') {
        // Application ouverte : la notification s'affiche ici (l'iPhone ne la montre pas dans la barre).
        toast.info(typeof e.data.body === 'string' ? e.data.body : '', { title: e.data.title })
      } else if (e.data?.type === 'evh-push-resubscribe') {
        syncPush()
      }
    }
    navigator.serviceWorker.addEventListener('message', onMessage)
    return () => navigator.serviceWorker.removeEventListener('message', onMessage)
  }, [navigate])

  return null
}

/**
 * Prechargement des pages en arriere-plan, une a une, quand le navigateur est libre :
 * les pages de l'espace membre, et celles de la gestion pour les responsables.
 * Rien n'est precharge en mode « economie de donnees » ou sur une connexion tres lente.
 */
function PrefetchPages() {
  const { isAuthenticated, isPlatformManager, isSuperAdmin, roles } = useAuth()
  // Pages de gestion : seulement pour les comptes qui ont un role de responsable (pas le simple fidele).
  const leader = isSuperAdmin || isPlatformManager || roles.some((r) => r.key !== 'fidele')
  useEffect(() => {
    if (!isAuthenticated) return
    const conn = (navigator as Navigator & { connection?: { saveData?: boolean; effectiveType?: string } }).connection
    if (conn?.saveData || /(^|-)2g$/.test(conn?.effectiveType ?? '')) return
    const queue = loaders.filter((l) => (l.admin ? leader : !isPlatformManager))
    let cancelled = false
    const idle = (cb: () => void) => (typeof window.requestIdleCallback === 'function' ? window.requestIdleCallback(cb, { timeout: 4000 }) : globalThis.setTimeout(cb, 300))
    const next = () => {
      const item = queue.shift()
      if (cancelled || !item) return
      item.load().catch(() => {}).finally(() => idle(next))
    }
    const start = window.setTimeout(() => idle(next), 1500)
    return () => { cancelled = true; window.clearTimeout(start) }
  }, [isAuthenticated, isPlatformManager, leader])
  return null
}

export default function App() {
  return (
    <>
    <PullToRefresh />
    <PushBridge />
    <PrefetchPages />
    <Toaster />
    <InstallPrompt />
    <Suspense fallback={<Loading />}>
    <Routes>
      <Route path="/" element={<Home />} />
      <Route path="/connexion" element={<PublicOnly><Login /></PublicOnly>} />
      <Route path="/profil" element={<RequireAuth memberSpace><ProfileSetup /></RequireAuth>} />
      {/* Pages connectees : cadre persistant (menus affiches une fois, seul le contenu change). */}
      <Route element={<ShellLayout />}>
        <Route path="/vertumetre" element={<RequireAuth module="vertumetre" requireComplete memberSpace><MyVertumetre /></RequireAuth>} />
        <Route path="/tableau-de-bord" element={<RequireAuth requireComplete memberSpace><Dashboard /></RequireAuth>} />
        <Route path="/mon-profil" element={<RequireAuth requireComplete memberSpace><MyProfile /></RequireAuth>} />
        <Route path="/ma-vie-spirituelle" element={<RequireAuth module="spiritual" requireComplete memberSpace><MySpiritual /></RequireAuth>} />
        <Route path="/ma-fiche" element={<RequireAuth requireComplete memberSpace><MyFiss /></RequireAuth>} />
        <Route path="/mes-resolutions" element={<RequireAuth module="resolutions" requireComplete memberSpace><MyResolutions /></RequireAuth>} />
        <Route path="/mon-devoir" element={<RequireAuth module="assignments" requireComplete memberSpace><MyAssignments /></RequireAuth>} />
        <Route path="/rendez-vous" element={<RequireAuth module="appointments" requireComplete memberSpace><Appointments /></RequireAuth>} />
        <Route path="/admin/rendez-vous" element={<RequireAuth module="appointments" requireComplete anyPermission={['appointments.organize', 'appointments.host']}><AdminAppointments /></RequireAuth>} />
        {/* Devoirs et RM : publication (PR, PA), suivi (pasteurs, patriarches, AP) ; l'API verifie la tribu. */}
        <Route path="/admin/devoirs-rm" element={<RequireAuth requireComplete anyPermission={['assignments.publish', 'spiritual.view']}><Growth /></RequireAuth>} />
        <Route path="/admin/horaires" element={<RequireAuth requireComplete anyPermission={['schedule.view']}><ServiceTimes /></RequireAuth>} />
        <Route path="/contact" element={<RequireAuth requireComplete memberSpace><Contact /></RequireAuth>} />
        <Route path="/calendrier" element={<RequireAuth requireComplete memberSpace><Calendar /></RequireAuth>} />
        <Route path="/servir" element={<RequireAuth requireComplete memberSpace><Services /></RequireAuth>} />
        <Route path="/exercices" element={<RequireAuth module="exercises" requireComplete memberSpace><MyExercisesPage /></RequireAuth>} />
        <Route path="/exercices/:id" element={<RequireAuth module="exercises" requireComplete memberSpace><ExerciseDetail /></RequireAuth>} />
        <Route path="/notifications" element={<RequireAuth requireComplete><Notifications /></RequireAuth>} />
        <Route path="/admin/membres" element={<RequireAuth requireComplete anyPermission={MEMBER_PERMS}><Members /></RequireAuth>} />
        <Route path="/admin/membres/:id" element={<RequireAuth requireComplete anyPermission={MEMBER_PERMS}><MemberDetail /></RequireAuth>} />
        <Route path="/admin/roles" element={<RequireAuth requireComplete anyPermission={['roles.manage']}><RolesAdmin /></RequireAuth>} />
        <Route path="/admin/organisation" element={<RequireAuth requireComplete anyPermission={['tribes.manage', 'departments.manage']}><Organization /></RequireAuth>} />
        <Route path="/admin/gems" element={<RequireAuth requireComplete anyPermission={['gems.manage']}><Gems /></RequireAuth>} />
        <Route path="/admin/presences" element={<RequireAuth requireComplete anyPermission={['attendance.view']}><Attendance /></RequireAuth>} />
        <Route path="/admin/exercices" element={<RequireAuth module="exercises" requireComplete anyPermission={['exercises.assign']}><Exercises /></RequireAuth>} />
        <Route path="/admin/annonces" element={<RequireAuth requireComplete anyPermission={['announcements.publish']}><Announcements /></RequireAuth>} />
        <Route path="/admin/evenements" element={<RequireAuth requireComplete anyPermission={['events.manage']}><Events /></RequireAuth>} />
        <Route path="/admin/demandes" element={<RequireAuth requireComplete anyPermission={['requests.handle']}><Requests /></RequireAuth>} />
        <Route path="/admin/validations" element={<RequireAuth requireComplete anyPermission={['fiss.review', 'tribes.transfer', 'members.approve', 'departments.approve']}><Validations /></RequireAuth>} />
        <Route path="/admin/rapports" element={<RequireAuth requireComplete anyPermission={['reports.view']}><Reports /></RequireAuth>} />
        {/* Patriarches et responsables de departement n'ont pas de permission commune : l'API decide. */}
        <Route path="/admin/rapports-mensuels" element={<RequireAuth requireComplete><LeaderReports /></RequireAuth>} />
        <Route path="/admin/rapport-gem" element={<RequireAuth requireComplete><GemReport /></RequireAuth>} />
        <Route path="/admin/responsables" element={<RequireAuth requireComplete anyPermission={MEMBER_PERMS}><Leaders /></RequireAuth>} />
        <Route path="/admin/versets" element={<RequireAuth requireComplete anyPermission={['verses.view']}><Verses /></RequireAuth>} />
        <Route path="/admin/ressources" element={<RequireAuth requireComplete anyPermission={['resources.view']}><Resources /></RequireAuth>} />
        <Route path="/admin/reglages" element={<RequireAuth requireComplete anyPermission={['settings.manage']}><Settings /></RequireAuth>} />
        <Route path="/admin/gestion" element={<RequireAuth requireComplete anyPermission={['platform.manage']}><PlatformHome /></RequireAuth>} />
        <Route path="/admin/comptes" element={<RequireAuth requireComplete anyPermission={['accounts.view']}><Accounts /></RequireAuth>} />
        <Route path="/admin/journal" element={<RequireAuth requireComplete anyPermission={['audit.view']}><AuditLog /></RequireAuth>} />
      </Route>
      <Route path="*" element={<RedirectWithMessage to="/" message="Cette page n'existe pas (ou plus). Retour à l'accueil." />} />
    </Routes>
    </Suspense>
    </>
  )
}
