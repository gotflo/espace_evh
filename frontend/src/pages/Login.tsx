import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import type { AuthPayload } from '../types'
import { Brand } from '../components/Brand'
import { DEFAULT_COUNTRY, type Country } from '../data/countries'
import { CountrySelect } from '../components/CountrySelect'
import { AsYouType, isValidPhoneNumber, type CountryCode } from 'libphonenumber-js'

type VerifyResponse = AuthPayload & { token: string; is_new_account?: boolean }

export default function Login() {
  const [step, setStep] = useState<'phone' | 'code'>('phone')
  const [country, setCountry] = useState<Country>(DEFAULT_COUNTRY)
  const [national, setNational] = useState('')
  const [normalizedPhone, setNormalizedPhone] = useState('')
  const [code, setCode] = useState('')
  const [error, setError] = useState('')
  const [info, setInfo] = useState('')
  const [busy, setBusy] = useState(false)

  const { login } = useAuth()
  const navigate = useNavigate()

  const phoneValid = national.trim().length > 0 && isValidPhoneNumber(national, country.iso as CountryCode)

  function onPhoneChange(value: string) {
    // Formate le numero au fur et a mesure de la saisie, selon le pays choisi.
    setNational(new AsYouType(country.iso as CountryCode).input(value))
  }

  async function requestCode(e: FormEvent) {
    e.preventDefault()
    setError(''); setInfo(''); setBusy(true)
    try {
      const res = await api<{ phone: string; message: string; dev_code?: string }>('/auth/request-otp', {
        method: 'POST', body: { phone: national, country: country.iso }, auth: false,
      })
      setNormalizedPhone(res.phone)
      setStep('code')
      // Phase de test : le backend renvoie le code, on l'affiche et on le pre-remplit.
      if (res.dev_code) {
        setCode(res.dev_code)
        setInfo(`Code (mode test) : ${res.dev_code}`)
      } else {
        setInfo(res.message)
      }
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur reseau.')
    } finally {
      setBusy(false)
    }
  }

  async function verifyCode(e: FormEvent) {
    e.preventDefault()
    setError(''); setBusy(true)
    try {
      const res = await api<VerifyResponse>('/auth/verify-otp', {
        method: 'POST', body: { phone: normalizedPhone, code }, auth: false,
      })
      // Nouveau compte : on note le premier passage pour afficher le mot de bienvenue.
      if (res.is_new_account) {
        try { localStorage.setItem('evh_welcome', '1') } catch { /* ignore */ }
      }
      login(res.token, res)
      navigate(res.profile_completed ? '/tableau-de-bord' : '/profil', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur reseau.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="screen">
      <div className="card">
        <Brand subtitle="Espace membre" />

        {step === 'phone' && (
          <form onSubmit={requestCode}>
            <h2 className="section-title">Connexion</h2>
            <p className="section-sub">Entrez votre numero de telephone pour recevoir un code.</p>

            {error && <div className="alert alert-error">{error}</div>}

            <div className="field">
              <label htmlFor="phone">Numero de telephone</label>
              <div className="phone-input">
                <CountrySelect value={country} onChange={setCountry} />
                <input
                  id="phone" className="input" type="tel" inputMode="tel" autoComplete="tel"
                  placeholder={country.iso === 'CA' || country.iso === 'US' ? '418 123 4567' : 'Numero'}
                  value={national} onChange={(e) => onPhoneChange(e.target.value)} required autoFocus
                />
              </div>
              {national.trim() && !phoneValid
                ? <p className="helper" style={{ color: 'var(--red)' }}>Ce numero ne semble pas valide pour ce pays.</p>
                : <p className="helper">Un code de verification vous sera envoye par SMS.</p>}
            </div>

            <button className="btn btn-primary" disabled={busy || !phoneValid}>
              {busy ? <span className="spinner" /> : 'Recevoir mon code'}
            </button>
          </form>
        )}

        {step === 'code' && (
          <form onSubmit={verifyCode}>
            <h2 className="section-title">Verification</h2>
            <p className="section-sub">
              Code envoye au <strong>{normalizedPhone}</strong>.
            </p>

            {info && !error && <div className="alert alert-info">{info}</div>}
            {error && <div className="alert alert-error">{error}</div>}

            <div className="field">
              <label htmlFor="code">Code a 6 chiffres</label>
              <input
                id="code" className="input otp" inputMode="numeric" maxLength={6}
                placeholder="000000" value={code} autoFocus
                onChange={(e) => setCode(e.target.value.replace(/\D/g, ''))} required
              />
            </div>

            <button className="btn btn-primary" disabled={busy || code.length < 6}>
              {busy ? <span className="spinner" /> : 'Me connecter'}
            </button>

            <div className="center mt">
              <button type="button" className="btn-link" onClick={() => { setStep('phone'); setCode(''); setError('') }}>
                Modifier le numero
              </button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
