import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import { currentEndpoint, disablePush, enablePush, getPushState, PushError, type PushState } from '../push'
import { toast } from '../toast'
import { Icon } from './Icon'

const DISMISS_KEY = 'evh_push_prompt_dismissed'

type TestReport = { message: string; devices: { device: string; this_device: boolean; ok: boolean; explanation: string }[] }

/**
 * Activation des notifications push sur cet appareil.
 * - variant "card" : reglage complet (page Notifications)
 * - variant "prompt" : invitation discrete sur le tableau de bord, masquee une fois traitee
 */
export function PushSettings({ variant = 'card' }: { variant?: 'card' | 'prompt' }) {
  const [state, setState] = useState<PushState | null>(null)
  const [busy, setBusy] = useState(false)
  const [report, setReport] = useState<TestReport | null>(null)
  const [dismissed, setDismissed] = useState(() => {
    try { return localStorage.getItem(DISMISS_KEY) === '1' } catch { return false }
  })

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
      // Le choix est fait : l'invitation du tableau de bord disparait (reglage dans Notifications).
      if (variant === 'prompt') dismiss()
    }
  }

  async function disable() {
    setBusy(true)
    setState(await disablePush())
    setBusy(false)
    toast.info('Cet appareil ne recevra plus de notifications push.', { title: 'Notifications désactivées' })
  }

  // Test reel : reenregistre cet appareil aupres du serveur, envoie tout de suite, puis affiche
  // ce que le service de notification a repondu pour chaque appareil du compte.
  async function test(delayed = false) {
    setBusy(true); setReport(null)
    try {
      await enablePush()
      const endpoint = await currentEndpoint()
      setReport(await api<TestReport>('/me/push/test', { method: 'POST', body: { endpoint, delayed }, toast: false }))
    } catch (e) {
      const message = e instanceof PushError ? e.message : e instanceof ApiError ? e.firstMessage : "Le test n'a pas pu être lancé."
      setReport({ message, devices: [] })
    } finally { setBusy(false) }
  }

  function dismiss() {
    setDismissed(true)
    try { localStorage.setItem(DISMISS_KEY, '1') } catch { /* ignore */ }
  }

  if (state === null) return null

  if (variant === 'prompt') {
    if (dismissed || state !== 'off') return null
    return (
      <div className="push-prompt" role="status">
        <span className="push-prompt-icon"><Icon name="bell" size={24} /></span>
        <div className="push-prompt-text">
          <strong>Ne manquez rien</strong>
          <span>Recevez les annonces, rappels d'événements et tâches directement sur votre téléphone.</span>
        </div>
        <div className="push-prompt-actions">
          <button className="btn btn-primary small" disabled={busy} onClick={enable}>
            {busy ? <span className="spinner" /> : 'Activer'}
          </button>
          <button className="btn-link" onClick={dismiss}>Plus tard</button>
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
        Quand l'application est ouverte, elles s'affichent dans l'application ; pour voir la notification du téléphone,
        choisissez « Tester dans 10 s » puis revenez à l'écran d'accueil.</p>}


      <div className="push-actions">
        {state === 'off' && <button className="btn btn-primary small" disabled={busy} onClick={enable}>{busy ? <span className="spinner" /> : 'Activer les notifications'}</button>}
        {state === 'on' && (
          <>
            <button className="btn btn-ghost small" disabled={busy} onClick={() => test()}>Envoyer un test</button>
            <button className="btn btn-ghost small" disabled={busy} onClick={() => test(true)}>Tester dans 10 s</button>
            <button className="btn-link" disabled={busy} onClick={disable}>Désactiver sur cet appareil</button>
          </>
        )}
      </div>
      {report && (
        <div className="push-report" role="status">
          <p>{report.message}</p>
          {report.devices.length > 0 && (
            <ul>
              {report.devices.map((d, i) => (
                <li key={i} className={d.ok ? 'ok' : 'ko'}>
                  <strong>{d.ok ? '✓' : '✗'} {d.device}{d.this_device ? ' (cet appareil)' : ''}</strong>
                  <span>{d.explanation}</span>
                </li>
              ))}
            </ul>
          )}
          {report.devices.some((d) => d.ok) && (
            <p className="helper">Acceptée mais rien ne s'affiche ? Vérifiez dans les réglages du téléphone que les notifications
              du navigateur (ou de l'application installée) sont autorisées, et que le mode « Ne pas déranger » est coupé.</p>
          )}
        </div>
      )}
    </section>
  )
}
