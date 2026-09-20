import { lazy, Suspense, type ReactNode } from 'react'
import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth/AuthContext'
import { InstallPrompt } from './components/InstallPrompt'
import { PullToRefresh } from './components/PullToRefresh'

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

export default function App() {
  return (
    <>
    <PullToRefresh />
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
      <Route path="*" element={<Navigate to="/" replace />} />
    </Routes>
    </Suspense>
    </>
  )
}
