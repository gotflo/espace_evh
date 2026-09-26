import { lazy, Suspense, useEffect, type ReactNode } from 'react'
import { Navigate, Route, Routes, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from './auth/AuthContext'
import { InstallPrompt } from './components/InstallPrompt'
import { PullToRefresh } from './components/PullToRefresh'
import { Toaster } from './components/Toaster'
import { api } from './api/client'
import { syncPush } from './push'
import { setUnread, getUnread } from './notifications'
import { pollNow } from './pulse'

// Chargement a la demande : chaque page arrive dans son propre paquet,
// le demarrage de l'application est donc beaucoup plus rapide.
const Login = lazy(() => import('./pages/Login'))
const ProfileSetup = lazy(() => import('./pages/ProfileSetup'))
const Dashboard = lazy(() => import('./pages/Dashboard'))
const Members = lazy(() => import('./pages/admin/Members'))
const MemberDetail = lazy(() => import('./pages/admin/MemberDetail'))
const RolesAdmin = lazy(() => import('./pages/admin/RolesAdmin'))
const Organization = lazy(() => import('./pages/admin/Organization'))
const Gems = lazy(() => import('./pages/admin/Gems'))
const Attendance = lazy(() => import('./pages/admin/Attendance'))
const Exercises = lazy(() => import('./pages/admin/Exercises'))
const Announcements = lazy(() => import('./pages/admin/Announcements'))
const Events = lazy(() => import('./pages/admin/Events'))
const Requests = lazy(() => import('./pages/admin/Requests'))
const Contact = lazy(() => import('./pages/Contact'))
const MySpiritual = lazy(() => import('./pages/MySpiritual'))
const MyProfile = lazy(() => import('./pages/MyProfile'))
const MyFiss = lazy(() => import('./pages/MyFiss'))
const Calendar = lazy(() => import('./pages/Calendar'))
const Services = lazy(() => import('./pages/Services'))
const Notifications = lazy(() => import('./pages/Notifications'))
const Validations = lazy(() => import('./pages/admin/Validations'))
const MyExercisesPage = lazy(() => import('./pages/Exercises'))
const ExerciseDetail = lazy(() => import('./pages/ExerciseDetail'))
const Reports = lazy(() => import('./pages/admin/Reports'))
const AuditLog = lazy(() => import('./pages/admin/AuditLog'))
const Verses = lazy(() => import('./pages/admin/Verses'))

function Loading() {
  return <div className="loading-screen"><span className="spinner" /></div>
}

/** Accueil : redirige selon l'etat de connexion. */
function Home() {
  const { loading, isAuthenticated, profileCompleted } = useAuth()
  if (loading) return <Loading />
  if (!isAuthenticated) return <Navigate to="/connexion" replace />
  return <Navigate to={profileCompleted ? '/tableau-de-bord' : '/profil'} replace />
}

/** Route reservee aux connectes, avec permission(s) optionnelle(s). */
function RequireAuth({ children, requireComplete = false, anyPermission }: {
  children: ReactNode
  requireComplete?: boolean
  anyPermission?: string[]
}) {
  const { loading, isAuthenticated, profileCompleted, hasPermission } = useAuth()
  if (loading) return <Loading />
  if (!isAuthenticated) return <Navigate to="/connexion" replace />
  if (requireComplete && !profileCompleted) return <Navigate to="/profil" replace />
  if (anyPermission && !anyPermission.some((p) => hasPermission(p))) {
    return <Navigate to="/tableau-de-bord" replace />
  }
  return <>{children}</>
}

/** Route reservee aux visiteurs non connectes. */
function PublicOnly({ children }: { children: ReactNode }) {
  const { loading, isAuthenticated, profileCompleted } = useAuth()
  if (loading) return <Loading />
  if (isAuthenticated) return <Navigate to={profileCompleted ? '/tableau-de-bord' : '/profil'} replace />
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
      } else if (e.data?.type === 'evh-push-resubscribe') {
        syncPush()
      }
    }
    navigator.serviceWorker.addEventListener('message', onMessage)
    return () => navigator.serviceWorker.removeEventListener('message', onMessage)
  }, [navigate])

  return null
}

export default function App() {
  return (
    <>
    <PullToRefresh />
    <PushBridge />
    <Toaster />
    <InstallPrompt />
    <Suspense fallback={<Loading />}>
    <Routes>
      <Route path="/" element={<Home />} />
      <Route path="/connexion" element={<PublicOnly><Login /></PublicOnly>} />
      <Route path="/profil" element={<RequireAuth><ProfileSetup /></RequireAuth>} />
      <Route path="/tableau-de-bord" element={<RequireAuth requireComplete><Dashboard /></RequireAuth>} />
      <Route path="/mon-profil" element={<RequireAuth requireComplete><MyProfile /></RequireAuth>} />
      <Route path="/ma-vie-spirituelle" element={<RequireAuth requireComplete><MySpiritual /></RequireAuth>} />
      <Route path="/ma-fiche" element={<RequireAuth requireComplete><MyFiss /></RequireAuth>} />
      <Route path="/contact" element={<RequireAuth requireComplete><Contact /></RequireAuth>} />
      <Route path="/calendrier" element={<RequireAuth requireComplete><Calendar /></RequireAuth>} />
      <Route path="/servir" element={<RequireAuth requireComplete><Services /></RequireAuth>} />
      <Route path="/exercices" element={<RequireAuth requireComplete><MyExercisesPage /></RequireAuth>} />
      <Route path="/exercices/:id" element={<RequireAuth requireComplete><ExerciseDetail /></RequireAuth>} />
      <Route path="/notifications" element={<RequireAuth requireComplete><Notifications /></RequireAuth>} />
      <Route path="/admin/membres" element={<RequireAuth requireComplete anyPermission={MEMBER_PERMS}><Members /></RequireAuth>} />
      <Route path="/admin/membres/:id" element={<RequireAuth requireComplete anyPermission={MEMBER_PERMS}><MemberDetail /></RequireAuth>} />
      <Route path="/admin/roles" element={<RequireAuth requireComplete anyPermission={['roles.manage']}><RolesAdmin /></RequireAuth>} />
      <Route path="/admin/organisation" element={<RequireAuth requireComplete anyPermission={['tribes.manage', 'departments.manage']}><Organization /></RequireAuth>} />
      <Route path="/admin/gems" element={<RequireAuth requireComplete anyPermission={['gems.manage']}><Gems /></RequireAuth>} />
      <Route path="/admin/presences" element={<RequireAuth requireComplete anyPermission={['attendance.view']}><Attendance /></RequireAuth>} />
      <Route path="/admin/exercices" element={<RequireAuth requireComplete anyPermission={['exercises.assign']}><Exercises /></RequireAuth>} />
      <Route path="/admin/annonces" element={<RequireAuth requireComplete anyPermission={['announcements.publish']}><Announcements /></RequireAuth>} />
      <Route path="/admin/evenements" element={<RequireAuth requireComplete anyPermission={['events.manage']}><Events /></RequireAuth>} />
      <Route path="/admin/demandes" element={<RequireAuth requireComplete anyPermission={['requests.handle']}><Requests /></RequireAuth>} />
      <Route path="/admin/validations" element={<RequireAuth requireComplete anyPermission={['fiss.review', 'tribes.transfer']}><Validations /></RequireAuth>} />
      <Route path="/admin/rapports" element={<RequireAuth requireComplete anyPermission={['reports.view']}><Reports /></RequireAuth>} />
      <Route path="/admin/versets" element={<RequireAuth requireComplete anyPermission={['content.manage']}><Verses /></RequireAuth>} />
      <Route path="/admin/journal" element={<RequireAuth requireComplete anyPermission={['audit.view']}><AuditLog /></RequireAuth>} />
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
    </Suspense>
    </>
  )
}
