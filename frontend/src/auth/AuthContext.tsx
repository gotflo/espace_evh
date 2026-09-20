import { createContext, useCallback, useContext, useEffect, useState, type ReactNode } from 'react'
import { api, auth as tokenStore } from '../api/client'
import type { AuthPayload, Profile, User, UserRole } from '../types'

interface AuthState {
  user: User | null
  profile: Profile | null
  roles: UserRole[]
  permissions: string[]
  isSuperAdmin: boolean
  loading: boolean
  isAuthenticated: boolean
  profileCompleted: boolean
  login: (token: string, payload: AuthPayload) => void
  logout: () => Promise<void>
  refresh: () => Promise<void>
  setProfile: (p: Profile) => void
  hasPermission: (key: string) => boolean
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [profile, setProfileState] = useState<Profile | null>(null)
  const [roles, setRoles] = useState<UserRole[]>([])
  const [permissions, setPermissions] = useState<string[]>([])
  const [isSuperAdmin, setIsSuperAdmin] = useState(false)
  const [loading, setLoading] = useState(true)

  const apply = useCallback((p: AuthPayload) => {
    setUser(p.user)
    setProfileState(p.profile)
    setRoles(p.roles ?? [])
    setPermissions(p.permissions ?? [])
    setIsSuperAdmin(!!p.is_super_admin)
  }, [])

  const reset = () => {
    setUser(null); setProfileState(null); setRoles([]); setPermissions([]); setIsSuperAdmin(false)
  }

  const refresh = useCallback(async () => {
    if (!tokenStore.get()) { setLoading(false); return }
    try {
      apply(await api<AuthPayload>('/me'))
    } catch {
      tokenStore.clear()
      reset()
    } finally {
      setLoading(false)
    }
  }, [apply])

  useEffect(() => { refresh() }, [refresh])

  const login = (token: string, payload: AuthPayload) => {
    tokenStore.set(token)
    apply(payload)
  }

  const logout = async () => {
    try { await api('/auth/logout', { method: 'POST' }) } catch { /* ignore */ }
    tokenStore.clear()
    reset()
  }

  const hasPermission = (key: string) => isSuperAdmin || permissions.includes(key)

  const value: AuthState = {
    user, profile, roles, permissions, isSuperAdmin,
    loading,
    isAuthenticated: !!user,
    profileCompleted: !!profile?.is_completed,
    login, logout, refresh,
    setProfile: setProfileState,
    hasPermission,
  }

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth(): AuthState {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth doit être utilise dans AuthProvider')
  return ctx
}
