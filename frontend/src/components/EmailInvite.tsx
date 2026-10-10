import { useEffect, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import type { Profile } from '../types'
import { Icon } from './Icon'

const LATER_KEY = 'evh:email-invite-later'
const LATER_DAYS = 7

function laterActive(): boolean {
  try {
    const until = Number(localStorage.getItem(LATER_KEY) || 0)
    return until > Date.now()
  } catch { return false }
}

/**
 * Invitation a ajouter son adresse courriel (devoirs, rappels, messages importants) : l'adresse se
 * saisit ici, sans passer par le profil. « Plus tard » la masque 7 jours. Ouverte depuis la
 * notification (?courriel=1), elle s'affiche meme si elle avait ete remise a plus tard.
 */
export function EmailInvite({ compact = false }: { compact?: boolean }) {
  const { profile, setProfile } = useAuth()
  const [params] = useSearchParams()
  const forced = params.get('courriel') === '1'
  const [hidden, setHidden] = useState(() => !forced && !compact && laterActive())
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [done, setDone] = useState(false)
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => { if (forced) input.current?.focus() }, [forced])

  if (!profile || (profile.email && !done) || (hidden && !done)) return null

  async function save(e: React.FormEvent) {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      const r = await api<{ profile: Profile }>('/me/email', { method: 'PUT', body: { email: email.trim() }, toast: false })
      setProfile(r.profile)
      setDone(true)
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Enregistrement impossible. Vérifiez votre réseau.')
    } finally { setBusy(false) }
  }

  function later() {
    try { localStorage.setItem(LATER_KEY, String(Date.now() + LATER_DAYS * 86400000)) } catch { /* navigation privee */ }
    setHidden(true)
  }

  if (done) {
    return (
      <section className={`email-invite is-done ${compact ? 'compact' : ''}`} role="status">
        <span className="email-invite-icon"><Icon name="check" size={18} /></span>
        <p><strong>C'est noté !</strong> Vos rappels arriveront aussi à {profile.email}.</p>
      </section>
    )
  }

  return (
    <section className={`email-invite ${compact ? 'compact' : ''}`} aria-label="Ajouter votre adresse courriel">
      <div className="email-invite-head">
        <span className="email-invite-icon"><Icon name="mail" size={18} /></span>
        <div>
          <strong>Recevez vos rappels par courriel</strong>
          <p>Votre devoir, vos rappels de fiche et les messages importants arriveront aussi dans votre boîte courriel.</p>
        </div>
      </div>
      <form className="email-invite-form" onSubmit={save} noValidate>
        <input ref={input} className="input" type="email" inputMode="email" autoComplete="email" placeholder="exemple : marie@gmail.com"
          aria-label="Votre adresse courriel" value={email} onChange={(e) => { setEmail(e.target.value); setError(null) }} maxLength={150} />
        <button className="btn btn-primary small" disabled={busy || !/^\S+@\S+\.\S+$/.test(email.trim())}>
          {busy ? <span className="spinner" /> : 'Ajouter'}
        </button>
      </form>
      {error && <p className="email-invite-error" role="alert">{error}</p>}
      {!compact && <button type="button" className="btn-link email-invite-later" onClick={later}>Plus tard</button>}
    </section>
  )
}
