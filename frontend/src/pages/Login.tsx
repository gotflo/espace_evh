import { useState, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import type { AuthPayload } from '../types'
import { Brand } from '../components/Brand'
import { takeDestination } from '../utils/afterLogin'
import { APP_NAME } from '../config'
import { DEFAULT_COUNTRY, type Country } from '../data/countries'
import { CountrySelect } from '../components/CountrySelect'
import { LoginBackdrop } from '../components/LoginBackdrop'
import { AsYouType, isValidPhoneNumber, type CountryCode } from 'libphonenumber-js'
import { useAppearance } from '../appearance'

type VerifyResponse = AuthPayload & { token: string; is_new_account?: boolean }
/**
 * Reponse a la saisie du numero : code SMS envoye ; ou numero de gestion : connexion directe (appareil de
 * confiance, jeton de session present) ou appareil a autoriser une seule fois.
 */
type RequestResponse = Partial<VerifyResponse> & { phone: string; message?: string; dev_code?: string; manager_device_required?: boolean }
type Step = 'phone' | 'code' | 'device'

/** Jeton de l'appareil de confiance du compte de gestion (garde apres la deconnexion). */
const DEVICE_KEY = 'evh_manager_device'
const readDevice = () => { try { return localStorage.getItem(DEVICE_KEY) } catch { return null } }

export default function Login() {
  // Message de l'administration (page Apparence), par exemple un culte special.
  const loginNotice = useAppearance(false).appearance.texts.login_notice
  const [step, setStep] = useState<Step>('phone')
  const [country, setCountry] = useState<Country>(DEFAULT_COUNTRY)
  const [national, setNational] = useState('')
  const [normalizedPhone, setNormalizedPhone] = useState('')
  const [code, setCode] = useState('')
  const [activationCode, setActivationCode] = useState('')
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

  const failure = (err: unknown) => setError(err instanceof ApiError ? err.firstMessage : 'Connexion impossible. Vérifiez votre réseau puis réessayez.')

  async function requestCode(e: FormEvent) {
    e.preventDefault()
    setError(''); setInfo(''); setBusy(true)
    try {
      const res = await api<RequestResponse>('/auth/request-otp', {
        method: 'POST', body: { phone: national, country: country.iso, device_token: readDevice() ?? undefined }, auth: false, toast: false,
      })
      setNormalizedPhone(res.phone)
      // Connexion directe : numero de gestion sur un appareil de confiance, ou serveur d'essai local.
      if (res.token && res.user) {
        const payload = res as VerifyResponse
        if (payload.is_new_account) {
          try { localStorage.setItem('evh_welcome', '1') } catch { /* ignore */ }
        }
        login(res.token, payload)
        navigate(payload.is_platform_manager ? '/admin/gestion' : payload.profile_completed ? (takeDestination() ?? '/tableau-de-bord') : '/profil', { replace: true })
        return
      }
      // Numero de gestion sur un nouvel appareil : autorisation unique.
      if (res.manager_device_required) {
        setActivationCode(''); setStep('device')
        return
      }
      setStep('code')
      // Phase de test : le backend renvoie le code, on l'affiche et on le pre-remplit.
      if (res.dev_code) {
        setCode(res.dev_code)
        setInfo(`Code (mode test) : ${res.dev_code}`)
      } else {
        setInfo(res.message ?? '')
      }
    } catch (err) {
      failure(err)
    } finally {
      setBusy(false)
    }
  }

  async function verifyCode(e: FormEvent) {
    e.preventDefault()
    setError(''); setBusy(true)
    try {
      const res = await api<VerifyResponse>('/auth/verify-otp', {
        method: 'POST', body: { phone: normalizedPhone, code }, auth: false, toast: false,
      })
      // Nouveau compte : on note le premier passage pour afficher le mot de bienvenue.
      if (res.is_new_account) {
        try { localStorage.setItem('evh_welcome', '1') } catch { /* ignore */ }
      }
      login(res.token, res)
      // Lien d'un courriel ouvert avant la connexion : on y retourne directement.
      navigate(res.profile_completed ? (takeDestination() ?? '/tableau-de-bord') : '/profil', { replace: true })
    } catch (err) {
      failure(err)
    } finally {
      setBusy(false)
    }
  }

  /** Autorise cet appareil pour le compte de gestion (une seule fois) : ensuite, le numero suffit ici. */
  async function authorizeDevice(e: FormEvent) {
    e.preventDefault()
    setError(''); setBusy(true)
    try {
      const res = await api<VerifyResponse & { device_token: string }>('/auth/manager-activation', {
        method: 'POST', auth: false, toast: false, body: { phone: normalizedPhone, activation_code: activationCode },
      })
      try { localStorage.setItem(DEVICE_KEY, res.device_token) } catch { /* appareil sans stockage : code a chaque fois */ }
      login(res.token, res)
      navigate('/admin/gestion', { replace: true })
    } catch (err) {
      failure(err)
    } finally {
      setBusy(false)
    }
  }

  function restart() {
    setStep('phone'); setCode(''); setActivationCode(''); setError(''); setInfo('')
  }

  return (
    <div className="screen login-screen">
      <LoginBackdrop />
      <div className="card login-card">
        <Brand subtitle={APP_NAME} />
        {loginNotice && <p className="login-notice" role="note">{loginNotice}</p>}

        {step === 'phone' && (
          <form onSubmit={requestCode}>
            <h2 className="section-title">Connexion</h2>
            <p className="section-sub">Entrez votre numéro de téléphone pour recevoir un code.</p>

            {error && <div className="alert alert-error">{error}</div>}

            <div className="field">
              <label htmlFor="phone">Numéro de téléphone</label>
              <div className="phone-input">
                <CountrySelect value={country} onChange={setCountry} />
                <input
                  id="phone" className="input" type="tel" inputMode="tel" autoComplete="tel"
                  placeholder={country.iso === 'CA' || country.iso === 'US' ? '418 123 4567' : 'Numéro'}
                  value={national} onChange={(e) => onPhoneChange(e.target.value)} required autoFocus
                />
              </div>
              {national.trim() && !phoneValid
                ? <p className="helper" style={{ color: 'var(--red)' }}>Ce numéro ne semble pas valide pour ce pays.</p>
                : <p className="helper">Un code de vérification vous sera envoyé par SMS.</p>}
            </div>

            <button className="btn btn-primary" disabled={busy || !phoneValid}>
              {busy ? <span className="spinner" /> : 'Recevoir mon code'}
            </button>
          </form>
        )}

        {step === 'code' && (
          <form onSubmit={verifyCode}>
            <h2 className="section-title">Vérification</h2>
            <p className="section-sub">
              Code envoyé au <strong>{normalizedPhone}</strong>.
            </p>

            {info && !error && <div className="alert alert-info">{info}</div>}
            {error && <div className="alert alert-error">{error}</div>}

            <div className="field">
              <label htmlFor="code">Code à 6 chiffres</label>
              <input
                id="code" className="input otp" inputMode="numeric" maxLength={6} autoComplete="one-time-code"
                placeholder="000000" value={code} autoFocus
                onChange={(e) => {
                  const digits = e.target.value.replace(/\D/g, '').slice(0, 6)
                  setCode(digits)
                  // Code complet (saisi ou propose par le telephone) : connexion sans appui supplementaire.
                  if (digits.length === 6 && !busy) window.setTimeout(() => e.target.form?.requestSubmit(), 0)
                }} required
              />
            </div>

            <button className="btn btn-primary" disabled={busy || code.length < 6}>
              {busy ? <span className="spinner" /> : 'Me connecter'}
            </button>

            <div className="center mt">
              <button type="button" className="btn-link" onClick={restart}>
                Modifier le numéro
              </button>
            </div>
          </form>
        )}

        {step === 'device' && (
          <form onSubmit={authorizeDevice}>
            <h2 className="section-title">Nouvel appareil</h2>
            <p className="section-sub">Première connexion sur cet appareil : saisissez le code d'autorisation remis par le propriétaire de la plateforme.</p>

            {error && <div className="alert alert-error">{error}</div>}

            <div className="field">
              <label htmlFor="activation">Code d'autorisation</label>
              <input
                id="activation" className="input otp" autoComplete="off" autoCapitalize="characters" spellCheck={false}
                placeholder="XXXX-XXXX-XXXX" maxLength={20} value={activationCode} autoFocus
                onChange={(e) => setActivationCode(e.target.value.toUpperCase())} required
              />
              <p className="helper">Demandé une seule fois : ensuite, votre numéro suffit sur cet appareil.</p>
            </div>

            <button className="btn btn-primary" disabled={busy || activationCode.replace(/[^A-Za-z0-9]/g, '').length < 12}>
              {busy ? <span className="spinner" /> : 'Autoriser'}
            </button>

            <div className="center mt">
              <button type="button" className="btn-link" onClick={restart}>Modifier le numéro</button>
            </div>
          </form>
        )}
      </div>
    </div>
  )
}
