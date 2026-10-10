import { useCallback, useEffect, useState, type FormEvent } from 'react'

import { api, ApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { Brand } from '../components/Brand'
import { Icon } from '../components/Icon'
import { VerseBanner } from '../components/VerseBanner'

import type { Profile } from '../types'

/** Verification de la validation : toutes les 45 s quand l'ecran est visible, et au retour sur l'application. */
const CHECK_EVERY = 45_000

/**
 * Nouveau compte en attente du pasteur : accueil (verset du jour), statut clair, et l'adresse courriel pour
 * etre prevenu (conseillee, pas obligatoire). L'espace s'ouvre tout seul des que le compte est valide.
 */
export function AccountPending() {
  const { profile, setProfile, refresh, logout, membership } = useAuth()
  const [email, setEmail] = useState(profile?.email ?? '')
  const [editing, setEditing] = useState(!profile?.email)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const check = useCallback(() => { if (document.visibilityState === 'visible') void refresh() }, [refresh])
  useEffect(() => {
    const timer = window.setInterval(check, CHECK_EVERY)
    document.addEventListener('visibilitychange', check)
    window.addEventListener('focus', check)
    return () => { window.clearInterval(timer); document.removeEventListener('visibilitychange', check); window.removeEventListener('focus', check) }
  }, [check])


  async function save(e: FormEvent) {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      const r = await api<{ profile: Profile }>('/me/email', { method: 'PUT', body: { email: email.trim() }, toast: false })
      setProfile(r.profile)
      setEditing(false)
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Enregistrement impossible. Vérifiez votre réseau.')
    } finally { setBusy(false) }
  }

  if (membership.status === 'rejected') {
    return (
      <div className="screen">
        <div className="card pending-card">
          <Brand />
          <div className="pending-status is-closed" role="status">
            <span className="pending-status-icon"><Icon name="lock" size={20} /></span>
            <div>
              <strong>Demande non acceptée</strong>
              <p>Votre demande d'inscription n'a pas été acceptée. S'il s'agit d'une erreur, adressez-vous à un responsable de l'église.</p>
            </div>
          </div>
          <button type="button" className="btn btn-ghost mt" onClick={() => void logout()}>Se déconnecter</button>
        </div>
      </div>
    )
  }

  const first = profile?.first_name?.trim().split(/\s+/)[0]
  const valid = /^\S+@\S+\.\S+$/.test(email.trim())

  return (
    <div className="screen screen-top">
      <div className="pending-page">
        <header className="pending-head">
          <img src="/logo-vh.png" alt="" className="pending-logo" />
          <div>
            <p className="pending-app">My vasesdhonneur</p>
            <h1 className="pending-title">Bienvenue{first ? `, ${first}` : ''} !</h1>
          </div>
        </header>

        <VerseBanner />

        <section className="pending-status" role="status" aria-live="polite">
          <span className="pending-status-icon"><Icon name="clock" size={20} /></span>
          <div>
            <strong>Compte en attente de validation</strong>
            <p>Le pasteur va valider votre inscription. Votre espace s'ouvrira alors automatiquement.</p>
          </div>
        </section>

        <section className="pending-email" aria-label="Être prévenu par courriel">
          {editing ? (
            <form onSubmit={save} noValidate>
              <label htmlFor="pending-email" className="pending-email-label">
                Être prévenu par courriel <span className="pending-tag">Conseillé</span>
              </label>
              <p className="pending-email-help">Nous vous écrirons dès que votre compte sera validé.</p>
              <div className="pending-email-row">
                <input id="pending-email" className="input" type="email" inputMode="email" autoComplete="email" maxLength={150}
                  placeholder="exemple : marie@gmail.com" value={email} onChange={(e) => { setEmail(e.target.value); setError(null) }} />
                <button className="btn btn-primary small" disabled={busy || !valid}>{busy ? <span className="spinner" /> : 'Enregistrer'}</button>
              </div>
              {error && <p className="email-invite-error" role="alert">{error}</p>}
            </form>
          ) : (
            <div className="pending-email-done">
              <span className="pending-email-check"><Icon name="check" size={16} /></span>
              <p>Nous vous écrirons à <strong>{profile?.email}</strong>.</p>
              <button type="button" className="btn-link" onClick={() => setEditing(true)}>Modifier</button>
            </div>
          )}
        </section>

        <button type="button" className="btn-link pending-logout" onClick={() => void logout()}>Se déconnecter</button>
      </div>
    </div>
  )
}
