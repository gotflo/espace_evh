import { useEffect, useState } from 'react'
import { useAuth } from '../auth/AuthContext'
import { disablePush, enablePush, getPushState, PushError, type PushState } from '../push'
import { toast } from '../toast'
import { Icon } from './Icon'

// « Plus tard » : date (ms) jusqu'a laquelle l'invitation reste cachee. Ensuite elle revient, tant que
// les notifications ne sont pas activees sur cet appareil (delai choisi dans Gestion > Reglages).
const SNOOZE_KEY = 'evh_push_prompt_until'
const readSnooze = () => { try { return Number(localStorage.getItem(SNOOZE_KEY)) || 0 } catch { return 0 } }

/**
 * Activation des notifications push sur cet appareil.
 * - variant "card" : reglage complet (page Notifications), sans envoi de test (diagnostic : php artisan app:push-check)
 * - variant "prompt" : invitation discrete sur le tableau de bord, masquee une fois traitee
 */
export function PushSettings({ variant = 'card' }: { variant?: 'card' | 'prompt' }) {
  const [state, setState] = useState<PushState | null>(null)
  const [busy, setBusy] = useState(false)
  const { settings } = useAuth()
  const [snoozedUntil, setSnoozedUntil] = useState(readSnooze)
  const [showIosSteps, setShowIosSteps] = useState(false)

  useEffect(() => { getPushState().then(setState) }, [])

  async function enable() {
    setBusy(true)
    try {
      const next = await enablePush()
      setState(next)
      if (next === 'on') toast.success('Vous recevrez les annonces, rappels et tâches sur cet appareil.', { title: 'Notifications activées' })
      else if (next === 'denied') toast.warning('Vous pourrez les autoriser plus tard dans les réglages du navigateur.', { title: 'Notifications refusées' })
    } catch (e) {
      toast.error(e instanceof PushError ? e.message : "Impossible d'activer les notifications sur cet appareil.", { title: 'Activation impossible' })
      setState(await getPushState())
    } finally {
      setBusy(false)
      // Refus dans la fenetre du navigateur : on ne redemande pas avant le delai choisi.
      if (variant === 'prompt') snooze()
    }
  }

  async function disable() {
    setBusy(true)
    setState(await disablePush())
    setBusy(false)
    toast.info('Cet appareil ne recevra plus de notifications push.', { title: 'Notifications désactivées' })
  }

  // Abonnement neuf : supprime celui de cet appareil (telephone et serveur) puis en recree un.
  // Regle le cas d'un abonnement devenu obsolete (reinstallation, changement de telephone...).
  async function reset() {
    setBusy(true)
    try {
      await disablePush()
      const next = await enablePush()
      setState(next)
      if (next === 'on') toast.success('Cet appareil est de nouveau abonné aux notifications.', { title: 'Notifications réinitialisées' })
      else toast.warning('Les notifications ne sont pas autorisées pour cette application.', { title: 'Réinitialisation incomplète' })
    } catch (e) {
      toast.error(e instanceof PushError ? e.message : 'La réinitialisation a échoué.', { title: 'Réinitialisation impossible' })
      setState(await getPushState())
    } finally { setBusy(false) }
  }

  function snooze() {
    const until = Date.now() + Math.max(1, settings.push_prompt_snooze_days) * 86400000
    setSnoozedUntil(until)
    try { localStorage.setItem(SNOOZE_KEY, String(until)) } catch { /* ignore */ }
  }

  if (state === null) return null

  if (variant === 'prompt') {
    // Invitation sur toutes les pages tant que les notifications ne sont pas activees (un seul clic).
    if (snoozedUntil > Date.now() || (state !== 'off' && state !== 'ios-install' && state !== 'denied')) return null
    if (state === 'denied') {
      return (
        <div className="push-prompt" role="status">
          <span className="push-prompt-icon"><Icon name="bell" size={24} /></span>
          <div className="push-prompt-text">
            <strong>Les notifications sont bloquées</strong>
            {showIosSteps
              ? <span>Touchez le cadenas (ou « ⓘ ») à côté de l'adresse du site, puis <strong>Notifications → Autoriser</strong>, et rechargez la page. Sur un téléphone : Réglages → Notifications → cette application → Autoriser.</span>
              : <span>Autorisez-les une fois pour recevoir les annonces et rappels.</span>}
          </div>
          <div className="push-prompt-actions">
            {!showIosSteps && <button className="btn btn-primary small" onClick={() => setShowIosSteps(true)}>Comment faire</button>}
            <button className="btn-link" onClick={snooze}>Plus tard</button>
          </div>
        </div>
      )
    }
    if (state === 'ios-install') {
      return (
        <div className="push-prompt" role="status">
          <span className="push-prompt-icon"><Icon name="bell" size={24} /></span>
          <div className="push-prompt-text">
            <strong>Recevez vos rappels sur l'iPhone</strong>
            {showIosSteps
              ? <span>Touchez <strong>Partager</strong> (carré avec une flèche), puis <strong>« Sur l'écran d'accueil »</strong>. Ouvrez ensuite l'application depuis son icône : un bouton vous proposera d'activer les notifications.</span>
              : <span>Ajoutez l'application à l'écran d'accueil pour recevoir les annonces et rappels.</span>}
          </div>
          <div className="push-prompt-actions">
            {!showIosSteps && <button className="btn btn-primary small" onClick={() => setShowIosSteps(true)}>Comment faire</button>}
            <button className="btn-link" onClick={snooze}>Plus tard</button>
          </div>
        </div>
      )
    }
    return (
      <div className="push-prompt" role="status">
        <span className="push-prompt-icon"><Icon name="bell" size={24} /></span>
        <div className="push-prompt-text">
          <strong>Activez les notifications</strong>
          <span>Un clic suffit : annonces, rappels et tâches arriveront directement sur votre téléphone.</span>
        </div>
        <div className="push-prompt-actions">
          <button className="btn btn-primary small" disabled={busy} onClick={enable}>
            {busy ? <span className="spinner" /> : 'Activer'}
          </button>
          <button className="btn-link" onClick={snooze}>Plus tard</button>
        </div>
      </div>
    )
  }

  return (
    <section className="panel push-card">
      <div className="panel-head"><h3>Notifications sur cet appareil</h3>
        <span className={`status-pill ${state === 'on' ? 'on' : ''}`}>{state === 'on' ? 'Activées' : 'Désactivées'}</span>
      </div>
      {state === 'unsupported' && <p className="helper">Ce navigateur ne permet pas les notifications push. Les notifications restent visibles dans l'application (cloche).</p>}
      {state === 'ios-install' && (
        <p className="helper">
          Sur iPhone, installez d'abord l'application : bouton <strong>Partager</strong> puis <strong>« Sur l'écran d'accueil »</strong>.
          Ouvrez ensuite l'application depuis son icône et revenez ici.
        </p>
      )}
      {state === 'denied' && (
        <p className="helper">
          Les notifications sont bloquées pour ce site. Autorisez-les dans les réglages du navigateur
          (cadenas à côté de l'adresse → Notifications → Autoriser), puis rechargez la page.
        </p>
      )}
      {state === 'off' && <p className="helper">Activez les notifications pour être prévenu même lorsque l'application est fermée.</p>}
      {state === 'on' && <p className="helper">Vous recevez les annonces, rappels d'événements, tâches et réponses sur cet appareil.
        Quand l'application est ouverte, elles s'affichent dans l'application. Si elles n'arrivent plus, choisissez « Réinitialiser ».</p>}


      <div className="push-actions">
        {state === 'off' && <button className="btn btn-primary small" disabled={busy} onClick={enable}>{busy ? <span className="spinner" /> : 'Activer les notifications'}</button>}
        {state === 'on' && (
          <>
            <button className="btn btn-ghost small" disabled={busy} onClick={reset}>Réinitialiser</button>
            <button className="btn-link" disabled={busy} onClick={disable}>Désactiver sur cet appareil</button>
          </>
        )}
      </div>
    </section>
  )
}
