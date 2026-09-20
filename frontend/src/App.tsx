import { Navigate, Route, Routes } from 'react-router-dom'
import { useAuth } from './auth/AuthContext'
import Login from './pages/Login'
import ProfileSetup from './pages/ProfileSetup'
import Dashboard from './pages/Dashboard'
import Members from './pages/admin/Members'
import MemberDetail from './pages/admin/MemberDetail'
import RolesAdmin from './pages/admin/RolesAdmin'
import Organization from './pages/admin/Organization'
import Gems from './pages/admin/Gems'
import Attendance from './pages/admin/Attendance'
import Exercises from './pages/admin/Exercises'
import Announcements from './pages/admin/Announcements'
import Events from './pages/admin/Events'
import Requests from './pages/admin/Requests'
import Contact from './pages/Contact'
import MySpiritual from './pages/MySpiritual'
import MyProfile from './pages/MyProfile'
import MyFiss from './pages/MyFiss'
import { InstallPrompt } from './components/InstallPrompt'
import type { ReactNode } from 'react'

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
    <InstallPrompt />
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
    </>
  )
}
