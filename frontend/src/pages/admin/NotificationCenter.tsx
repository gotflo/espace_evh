import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { LoadError } from '../../components/LoadError'
import { MultiSelect } from '../../components/MultiSelect'
import { SkeletonCard } from '../../components/Skeleton'

interface Automation {
  key: string; step: string; group: string; label: string; help: string | null
  enabled: boolean; module_on: boolean; paused_from: string | null; paused_until: string | null; paused_now: boolean; reason: string | null
}
interface CenterEvent {
  id: number; title: string; category: string; starts_at: string | null; recurrence: string; recurrence_label: string | null
  regular: boolean; muted: boolean; muted_from: string | null; muted_until: string | null; muted_today: boolean
}
interface Launcher { key: string; label: string; help: string; targets: number; sent_today: boolean }
interface Scope { type: 'church' | 'tribe' | 'gem' | 'department'; id: number | null }
interface Message {
  id: number; title: string; body: string | null; scopes: Scope[]; urgency: string; send_at: string | null
  status: 'scheduled' | 'sent' | 'cancelled'; recipients_count: number | null; sent_at: string | null; author: string | null
}
interface Audiences { tribes: { id: number; name: string }[]; gems: { id: number; name: string; tribe_id: number }[]; departments: { id: number; name: string }[] }
interface CenterData { automations: Automation[]; events: CenterEvent[]; launchers: Launcher[]; scheduled: Message[]; audiences: Audiences }

type Tab = 'auto' | 'events' | 'send' | 'launch'
const TABS: { key: Tab; label: string }[] = [
  { key: 'auto', label: 'Rappels automatiques' },
  { key: 'events', label: 'Cultes et événements' },
  { key: 'send', label: 'Envoyer' },
  { key: 'launch', label: 'Relances' },
]

const day = (d: string | null) => (d ? new Date(`${d.slice(0, 10)}T12:00`).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }) : '')
const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fr-CA', { day: 'numeric', month: 'long', hour: '2-digit', minute: '2-digit' }) : '')
const today = () => new Date().toLocaleDateString('en-CA')

/** Periode (du / au) saisie en ligne : suspension d'un rappel automatique ou coupure des rappels d'un evenement. */
function PeriodEditor({ withReason, onSave, onCancel }: { withReason?: boolean; onSave: (from: string, until: string, reason: string) => Promise<void>; onCancel: () => void }) {
  const [from, setFrom] = useState(today())
  const [until, setUntil] = useState('')
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const ok = !!from && !!until && until >= from
  return (
    <div className="nc-period">
      <div className="nc-period-dates">
        <label>Du<input className="input" type="date" value={from} min={today()} onChange={(e) => setFrom(e.target.value)} /></label>
        <label>Au (inclus)<input className="input" type="date" value={until} min={from || today()} onChange={(e) => setUntil(e.target.value)} /></label>
      </div>
      {withReason && <input className="input" maxLength={160} placeholder="Motif (facultatif), par exemple : jeûne de 40 jours" value={reason} onChange={(e) => setReason(e.target.value)} />}
      <div className="nc-period-actions">
        <button type="button" className="btn btn-ghost small" onClick={onCancel}>Annuler</button>
        <button type="button" className="btn btn-primary small" disabled={!ok || busy}
          onClick={async () => { setBusy(true); try { await onSave(from, until, reason.trim()) } finally { setBusy(false) } }}>
          {busy ? <span className="spinner" /> : 'Enregistrer'}
        </button>
      </div>
    </div>
  )
}

/**
 * Centre de notifications : activer, couper ou suspendre pour une periode les rappels automatiques et ceux de
 * chaque culte ou evenement ; envoyer une notification personnalisee tout de suite ou a l'heure choisie ;
 * relancer a la main les membres qui n'ont pas encore rempli leur fiche, leur Vertumetre ou leurs resolutions.
 */
export default function NotificationCenter() {
  const [data, setData] = useState<CenterData | null>(null)
  const [error, setError] = useState('')
  const [tab, setTab] = useState<Tab>('auto')
  const [editing, setEditing] = useState<string | null>(null)
  const [confirm, setConfirm] = useState<Launcher | null>(null)

  const load = useCallback(() => {
    setError('')
    api<CenterData>('/admin/notification-center').then(setData).catch((e) => setError(e instanceof ApiError ? e.firstMessage : 'Erreur.'))
  }, [])
  useEffect(() => { load() }, [load])

  const patch = (part: Partial<CenterData>) => setData((d) => (d ? { ...d, ...part } : d))

  async function toggle(a: Automation) {
    try { patch(await api<Pick<CenterData, 'automations'>>(`/admin/notification-center/automations/${a.key}`, { method: 'PUT', body: { enabled: !a.enabled } })) } catch { /* toast */ }
  }
  async function pause(a: Automation, from: string | null, until: string | null, reason = '') {
    try {
      patch(await api<Pick<CenterData, 'automations'>>(`/admin/notification-center/automations/${a.key}/pause`, { method: 'PUT', body: { paused_from: from, paused_until: until, reason: reason || null } }))
      setEditing(null)
    } catch { /* toast */ }
  }
  async function muteEvent(e: CenterEvent, muted: boolean, from: string | null = null, until: string | null = null) {
    try {
      patch(await api<Pick<CenterData, 'events'>>(`/admin/notification-center/events/${e.id}`, { method: 'PUT', body: { muted, paused_from: from, paused_until: until } }))
      setEditing(null)
    } catch { /* toast */ }
  }
  async function launch(l: Launcher) {
    try { patch(await api<Pick<CenterData, 'launchers'>>(`/admin/notification-center/launch/${l.key}`, { method: 'POST' })) } catch { /* toast */ }
    setConfirm(null)
  }

  const groups = useMemo(() => {
    const out = new Map<string, Automation[]>()
    for (const a of data?.automations ?? []) out.set(a.group, [...(out.get(a.group) ?? []), a])
    return [...out.entries()]
  }, [data])

  return (
    <AppLayout title="Notifications" subtitle="Rappels automatiques, coupures et envois personnalisés">
      <div className="filter-chips nc-tabs" role="tablist" aria-label="Rubriques">
        {TABS.map((t) => (
          <button key={t.key} role="tab" aria-selected={tab === t.key} className={`chip-toggle ${tab === t.key ? 'on' : ''}`} onClick={() => { setTab(t.key); setEditing(null) }}>{t.label}</button>
        ))}
      </div>

      {error && <LoadError message={error} onRetry={load} />}
      {!data && !error && <SkeletonCard />}

      {data && tab === 'auto' && groups.map(([group, items]) => (
        <section key={group} className="panel nc-section">
          <div className="panel-head"><h3>{group}</h3></div>
          <ul className="nc-list">
            {items.map((a) => (
              <li key={a.key} className="nc-row">
                <div className="nc-row-main">
                  <strong>{a.label}</strong>
                  {a.help && <small>{a.help}</small>}
                  <span className={`nc-state ${!a.enabled || !a.module_on ? 'off' : a.paused_now ? 'paused' : 'on'}`}>
                    {!a.module_on ? 'Module désactivé dans Réglages'
                      : !a.enabled ? 'Désactivé'
                        : a.paused_from ? `Suspendu du ${day(a.paused_from)} au ${day(a.paused_until)}${a.reason ? ` · ${a.reason}` : ''}`
                          : 'Actif'}
                  </span>
                </div>
                <div className="nc-row-actions">
                  <button type="button" role="switch" aria-checked={a.enabled} aria-label={a.label} className={`toggle-switch ${a.enabled ? 'on' : ''}`} onClick={() => void toggle(a)}>
                    <span className="toggle-knob" aria-hidden />
                    <span className="toggle-text">{a.enabled ? 'Activé' : 'Désactivé'}</span>
                  </button>
                  {a.paused_from
                    ? <button type="button" className="btn-link" onClick={() => void pause(a, null, null)}>Retirer la suspension</button>
                    : a.enabled && <button type="button" className="btn-link" onClick={() => setEditing(`a:${a.key}`)}>Suspendre une période</button>}
                </div>
                {editing === `a:${a.key}` && <PeriodEditor withReason onCancel={() => setEditing(null)} onSave={(f, u, r) => pause(a, f, u, r)} />}
              </li>
            ))}
          </ul>
        </section>
      ))}

      {data && tab === 'events' && (
        <section className="panel nc-section">
          <div className="panel-head"><h3>Rappels des cultes et événements</h3></div>
          <p className="helper">Couper les rappels d’un culte ou d’un événement : pour toujours, ou pendant une période (ils reprennent d’eux-mêmes après la date de fin).</p>
          {data.events.length === 0 && <p className="helper">Aucun culte ni événement à venir.</p>}
          <ul className="nc-list">
            {data.events.map((e) => (
              <li key={e.id} className="nc-row">
                <div className="nc-row-main">
                  <strong>{e.title}</strong>
                  <small>{e.recurrence_label && e.recurrence !== 'none' ? e.recurrence_label : when(e.starts_at)}{e.regular ? ' · culte régulier' : ''}</small>
                  <span className={`nc-state ${e.muted ? 'off' : e.muted_from ? 'paused' : 'on'}`}>
                    {e.muted ? 'Rappels coupés' : e.muted_from ? `Rappels coupés du ${day(e.muted_from)} au ${day(e.muted_until)}` : 'Rappels actifs'}
                  </span>
                </div>
                <div className="nc-row-actions">
                  {e.muted || e.muted_from
                    ? <button type="button" className="btn btn-ghost small" onClick={() => void muteEvent(e, false)}>Rétablir les rappels</button>
                    : <>
                        <button type="button" className="btn-link" onClick={() => setEditing(`e:${e.id}`)}>Couper une période</button>
                        <button type="button" className="btn btn-ghost small" onClick={() => void muteEvent(e, true)}>Couper</button>
                      </>}
                </div>
                {editing === `e:${e.id}` && <PeriodEditor onCancel={() => setEditing(null)} onSave={(f, u) => muteEvent(e, false, f, u)} />}
              </li>
            ))}
          </ul>
        </section>
      )}

      {data && tab === 'send' && <Compose audiences={data.audiences} messages={data.scheduled} onChanged={load} />}

      {data && tab === 'launch' && (
        <section className="panel nc-section">
          <div className="panel-head"><h3>Relancer maintenant</h3></div>
          <p className="helper">Une notification aux membres actifs qui n’ont pas encore fait le geste du mois. Une fois par jour au plus.</p>
          <ul className="nc-list">
            {data.launchers.map((l) => (
              <li key={l.key} className="nc-row">
                <div className="nc-row-main">
                  <strong>{l.label}</strong>
                  <small>{l.help}</small>
                  <span className={`nc-state ${l.sent_today ? 'paused' : 'on'}`}>{l.sent_today ? 'Déjà relancé aujourd’hui' : `${l.targets} membre(s) concerné(s)`}</span>
                </div>
                <div className="nc-row-actions">
                  <button type="button" className="btn btn-primary small" disabled={l.sent_today || l.targets === 0} onClick={() => setConfirm(l)}>Relancer</button>
                </div>
              </li>
            ))}
          </ul>
        </section>
      )}

      {confirm && (
        <ConfirmDialog title={`Relancer : ${confirm.label}`} confirmLabel="Relancer"
          text={`${confirm.targets} membre(s) recevront une notification maintenant.`}
          onConfirm={() => launch(confirm)} onClose={() => setConfirm(null)} />
      )}
    </AppLayout>
  )
}

/** Notification personnalisee : a qui, quoi, maintenant ou a l'heure choisie ; liste des programmees et envoyees. */
function Compose({ audiences, messages, onChanged }: { audiences: Audiences; messages: Message[]; onChanged: () => void }) {
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [church, setChurch] = useState(true)
  const [groups, setGroups] = useState<string[]>([])
  const [important, setImportant] = useState(false)
  const [later, setLater] = useState(false)
  const [at, setAt] = useState('')
  const [count, setCount] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)

  const scopes: Scope[] = useMemo(() => (church ? [{ type: 'church', id: null }] : groups.map((g) => {
    const [type, id] = g.split(':')
    return { type: type as Scope['type'], id: Number(id) }
  })), [church, groups])

  useEffect(() => {
    if (scopes.length === 0) { setCount(null); return }
    const t = setTimeout(() => {
      api<{ recipients: number }>('/admin/notification-center/preview', { method: 'POST', body: { scopes }, toast: false })
        .then((r) => setCount(r.recipients)).catch(() => setCount(null))
    }, 300)
    return () => clearTimeout(t)
  }, [scopes])

  const options = [
    ...audiences.tribes.map((t) => ({ value: `tribe:${t.id}`, label: t.name, group: 'Tribus' })),
    ...audiences.gems.map((g) => ({ value: `gem:${g.id}`, label: g.name, group: 'GEMs' })),
    ...audiences.departments.map((d) => ({ value: `department:${d.id}`, label: d.name, group: 'Départements' })),
  ]
  const ok = title.trim().length > 0 && scopes.length > 0 && (!later || !!at)

  async function send() {
    setBusy(true)
    try {
      await api('/admin/notification-center/messages', { method: 'POST', body: {
        title: title.trim(), body: body.trim() || null, scopes, urgency: important ? 'high' : 'normal', send_at: later ? at : null,
      } })
      setTitle(''); setBody(''); setImportant(false); setLater(false); setAt('')
      onChanged()
    } catch { /* toast */ } finally { setBusy(false) }
  }
  async function cancel(m: Message) {
    try { await api(`/admin/notification-center/messages/${m.id}`, { method: 'DELETE' }); onChanged() } catch { /* toast */ }
  }

  return (
    <>
      <section className="panel nc-section">
        <div className="panel-head"><h3>Envoyer une notification</h3></div>
        <div className="field"><label htmlFor="nc-title">Titre</label>
          <input id="nc-title" className="input" maxLength={120} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Ex. Prière du matin à 6 h" />
        </div>
        <div className="field"><label htmlFor="nc-body">Message (facultatif)</label>
          <textarea id="nc-body" className="input" rows={3} maxLength={500} value={body} onChange={(e) => setBody(e.target.value)} />
        </div>
        <div className="field"><label>Destinataires</label>
          <div className="seg small" role="radiogroup" aria-label="Destinataires">
            <button type="button" role="radio" aria-checked={church} className={church ? 'on' : ''} onClick={() => setChurch(true)}>Toute l’église</button>
            <button type="button" role="radio" aria-checked={!church} className={!church ? 'on' : ''} onClick={() => setChurch(false)}>Choisir des groupes</button>
          </div>
          {!church && <MultiSelect label="Destinataires" options={options} value={groups} onChange={setGroups}
            placeholder="Tribus, GEMs ou départements…" searchPlaceholder="Rechercher une tribu, un GEM, un département…" />}
          <p className="helper">{count === null ? (scopes.length === 0 ? 'Choisissez au moins un groupe.' : '…') : `${count} personne(s) recevront cette notification.`}</p>
        </div>
        <label className="switch-line"><input type="checkbox" checked={important} onChange={(e) => setImportant(e.target.checked)} /> Importante (fait sonner le téléphone même au-delà de la limite horaire)</label>
        <div className="field mt"><label>Quand</label>
          <div className="seg small" role="radiogroup" aria-label="Quand">
            <button type="button" role="radio" aria-checked={!later} className={!later ? 'on' : ''} onClick={() => setLater(false)}>Maintenant</button>
            <button type="button" role="radio" aria-checked={later} className={later ? 'on' : ''} onClick={() => setLater(true)}>Programmer</button>
          </div>
          {later && <input className="input mt" type="datetime-local" aria-label="Date et heure d’envoi" value={at} min={new Date().toISOString().slice(0, 16)} onChange={(e) => setAt(e.target.value)} />}
        </div>
        <button type="button" className="btn btn-primary mt" disabled={!ok || busy} onClick={() => void send()}>
          {busy ? <span className="spinner" /> : later ? 'Programmer la notification' : 'Envoyer maintenant'}
        </button>
      </section>

      <section className="panel nc-section">
        <div className="panel-head"><h3>Programmées et envoyées</h3></div>
        {messages.length === 0 && <p className="helper">Aucune notification personnalisée pour l’instant.</p>}
        <ul className="nc-list">
          {messages.map((m) => (
            <li key={m.id} className="nc-row">
              <div className="nc-row-main">
                <strong>{m.title}</strong>
                {m.body && <small>{m.body}</small>}
                <span className={`nc-state ${m.status === 'scheduled' ? 'paused' : m.status === 'sent' ? 'on' : 'off'}`}>
                  {m.status === 'scheduled' ? `Programmée le ${when(m.send_at)}`
                    : m.status === 'sent' ? `Envoyée le ${when(m.sent_at)} à ${m.recipients_count ?? 0} personne(s)` : 'Annulée'}
                  {m.urgency === 'high' ? ' · importante' : ''}
                </span>
              </div>
              {m.status === 'scheduled' && (
                <div className="nc-row-actions">
                  <button type="button" className="btn-link" onClick={() => { if (confirm(`Annuler « ${m.title} » ?`)) void cancel(m) }}>Annuler</button>
                </div>
              )}
            </li>
          ))}
        </ul>
      </section>
    </>
  )
}

