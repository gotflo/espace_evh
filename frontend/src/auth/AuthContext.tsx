import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, ApiError, auth as tokenStore, onSessionExpired } from '../api/client'
import { forgetPushOnThisDevice } from '../push'
import { setUnread } from '../notifications'
import { rememberPreviousVisit } from '../utils/visit'
import type { AppSettings, AuthPayload, Membership, ModuleName, Profile, User, UserRole } from '../types'

interface AuthState {
  user: User | null
  profile: Profile | null
  roles: UserRole[]
  permissions: string[]
  isSuperAdmin: boolean
  /** Compte de gestion principal (numero reserve) : gestion seulement, pas d'espace membre. */
  isPlatformManager: boolean
  loading: boolean
  isAuthenticated: boolean
  profileCompleted: boolean
  /** Tribu obligatoire pour terminer l'inscription ; membre deja inscrit a qui il manque une information. */
  registration: { tribe_required: boolean; reopened: boolean }
  /** Nouveau compte : en attente de la validation du pasteur (ou rejete). */
  membership: Membership
  setMembership: (m: Membership) => void
  login: (token: string, payload: AuthPayload) => void
  logout: () => Promise<void>
  refresh: () => Promise<void>
  setProfile: (p: Profile) => void
  hasPermission: (key: string) => boolean
  /** Reglages de la plateforme utiles a l'application. */
  settings: AppSettings
  /** Module active dans les reglages (actif par defaut si inconnu). */
  moduleOn: (name: ModuleName) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [profile, setProfileState] = useState<Profile | null>(null)
  const [roles, setRoles] = useState<UserRole[]>([])
  const [permissions, setPermissions] = useState<string[]>([])
  const [isSuperAdmin, setIsSuperAdmin] = useState(false)
  const [isPlatformManager, setIsPlatformManager] = useState(false)
  const [registration, setRegistration] = useState({ tribe_required: true, reopened: false })
  const [membership, setMembership] = useState<Membership>({ status: 'approved', submitted: true })
  const [loading, setLoading] = useState(true)
  const [settings, setSettings] = useState<AppSettings>({ modules: {}, push_prompt_snooze_days: 3 })

  const apply = useCallback((p: AuthPayload) => {
    rememberPreviousVisit(p.user?.last_seen_at)
    setUser(p.user)
    setProfileState(p.profile)
    setRoles(p.roles ?? [])
    setPermissions(p.permissions ?? [])
    setIsSuperAdmin(!!p.is_super_admin)
    setIsPlatformManager(!!p.is_platform_manager)
    setRegistration({ tribe_required: p.registration?.tribe_required ?? false, reopened: p.registration?.reopened ?? false })
    setMembership(p.membership ?? { status: 'approved', submitted: true })
    if (p.settings) setSettings(p.settings)
  }, [])

  const reset = useCallback(() => {
    setUser(null); setProfileState(null); setRoles([]); setPermissions([]); setIsSuperAdmin(false); setIsPlatformManager(false)
    setMembership({ status: 'approved', submitted: true })
  }, [])

  // Session expiree pendant l'utilisation (401) : deconnecte ; la page protegee renvoie vers
  // /connexion en retenant la page demandee (ouverte de nouveau juste apres la connexion).
  useEffect(() => {
    onSessionExpired(() => { setUnread(0); reset() })
    return () => onSessionExpired(null)
  }, [reset])

  const refresh = useCallback(async () => {
    if (!tokenStore.get()) { setLoading(false); return }
    // Seule une session refusee (401) deconnecte. Une coupure reseau ou une limite de
    // requetes (429) est reessayee : le jeton est conserve.
    for (let attempt = 0; ; attempt++) {
      try {
        const me = await api<AuthPayload>('/me')
        apply(me)
        if (typeof me.unread === 'number') setUnread(me.unread)
        break
      } catch (err) {
        if (err instanceof ApiError && err.status === 401) { tokenStore.clear(); reset(); break }
        if (attempt >= 2) { reset(); break }
        await new Promise((r) => setTimeout(r, 1500 * (attempt + 1)))
      }
    }
    setLoading(false)
  }, [apply, reset])

  useEffect(() => { refresh() }, [refresh])

  const login = (token: string, payload: AuthPayload) => {
    tokenStore.set(token)
    apply(payload)
  }

  const logout = async () => {
    // Cet appareil ne recoit plus les notifications de ce compte.
    await forgetPushOnThisDevice()
    setUnread(0)
    try { await api('/auth/logout', { method: 'POST', toast: false }) } catch { /* ignore */ }
    tokenStore.clear()
    reset()
  }

  const hasPermission = (key: string) => isSuperAdmin || permissions.includes(key)

  const value: AuthState = {
    user, profile, roles, permissions, isSuperAdmin, isPlatformManager,
    loading,
    isAuthenticated: !!user,
    // Meme regle que le serveur : inscription terminee ET tribu choisie (si elle est requise).
    // Un compte en attente du pasteur n'a pas terminé son inscription (l'écran d'attente remplace le reste).
    // Compte de gestion : pas de fiche de membre a remplir (le serveur le considere comme inscrit).
    profileCompleted: isPlatformManager
      || (membership.status === 'approved' && !!profile?.is_completed && (!registration.tribe_required || !!profile?.tribe_id)),
    registration,
    membership,
    setMembership,
    login, logout, refresh,
    setProfile: setProfileState,
    hasPermission,
    settings,
    moduleOn: (name: ModuleName) => settings.modules[name] !== false,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth doit être utilise dans AuthProvider')
  return ctx
}
