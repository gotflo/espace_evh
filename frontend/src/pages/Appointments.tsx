import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { Icon } from '../components/Icon'
import { SkeletonCard } from '../components/Skeleton'
import { usePulse } from '../pulse'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import type { AppointmentItem, AppointmentSession, AppointmentUrgency, MyAppointmentsData } from '../types'

const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)
const hhmm = (t: string) => { const [h, m] = t.split(':'); return `${Number(h)}h${m}` }

/** Choix du membre : un creneau precis, la premiere heure libre d'un jour, ou le plus tot possible. Toujours confirme aussitot. */
type Choice = { mode: 'slot'; date: string; start: string } | { mode: 'session'; date: string } | { mode: 'first' }

const URGENCY_HINT: Record<AppointmentUrgency, string> = {
  normal: 'Une rencontre sans caractère pressant.',
  important: 'Le sujet vous tient à cœur et ne peut pas trop attendre.',
  urgent: 'Une situation pressante : le pasteur et le protocole sont prévenus en priorité.',
}

function ActiveCard({ a, onCancel }: { a: AppointmentItem; onCancel: () => void }) {
  const confirmed = a.status === 'confirmed' && a.day && a.time
  // L'heure est arrivee : plus d'annulation possible (le serveur la refuserait), place a l'accueil.
  const started = !!a.starts_at && new Date(a.starts_at) <= new Date()
  return (
    <section className={`appt-hero ${confirmed ? 'is-confirmed' : 'is-pending'}`} aria-live="polite">
      <span className="appt-hero-badge"><Icon name={confirmed ? 'check' : 'clock'} size={14} />{confirmed ? 'Rendez-vous confirmé' : 'Demande envoyée'}</span>
      {confirmed ? (
        <>
          <div className="appt-hero-when">
            <span className="appt-hero-day">{cap(a.day!)}</span>
            <span className="appt-hero-time">{a.time}</span>
          </div>
          <p className="appt-hero-text">{started ? 'Votre tour est arrivé : présentez-vous au protocole.' : `Avec ${a.host ?? 'le pasteur'}. Présentez-vous au protocole après le culte.`}</p>
        </>
      ) : (
        <>
          <div className="appt-hero-when">
            <span className="appt-hero-day">{cap(a.preferred_label ?? 'Le plus tôt possible')}</span>
            {a.preference === 'first' && <span className="appt-hero-sub">au plus tôt</span>}
          </div>
          <p className="appt-hero-text">Le protocole vous place sur un créneau et vous recevez une notification dès que l'heure est fixée.</p>
        </>
      )}
      <div className="appt-hero-meta">
        <span className={`appt-urgency u-${a.urgency}`}>Priorité {a.urgency_label.toLowerCase()}</span>
        {a.message && <span className="appt-chip"><Icon name="lock" size={12} /> Message pour le pasteur</span>}
      </div>
      {!started && <button className="btn btn-ghost small appt-hero-cancel" onClick={onCancel}>{confirmed ? 'Annuler le rendez-vous' : 'Retirer ma demande'}</button>}
    </section>
  )
}

function SessionCard({ s, choice, onChoose }: { s: AppointmentSession; choice: Choice | null; onChoose: (c: Choice) => void }) {
  const open = (choice?.mode === 'slot' || choice?.mode === 'session') && choice.date === s.date
  const [expanded, setExpanded] = useState(false)
  const show = expanded || open
  const day = new Date(s.date + 'T12:00')
  const full = s.free === 0
  return (
    <article className={`appt-session ${show ? 'open' : ''} ${full ? 'full' : ''}`}>
      <button type="button" className="appt-session-head" aria-expanded={show} onClick={() => setExpanded(!show)}>
        <span className="appt-date">
          <small>{day.toLocaleDateString('fr-CA', { weekday: 'short' }).replace('.', '')}</small>
          <b>{day.getDate()}</b>
          <small>{day.toLocaleDateString('fr-CA', { month: 'short' }).replace('.', '')}</small>
        </span>
        <span className="appt-session-text">
          <strong>{cap(s.label)}</strong>
          <small>{s.range} · après le culte</small>
        </span>
        <span className={`appt-places ${full ? 'none' : ''}`}>{full ? 'Complet' : `${s.free} place${s.free > 1 ? 's' : ''}`}</span>
      </button>
      {show && (
        <div className="appt-session-body">
          <div className="appt-slots" role="radiogroup" aria-label={`Créneaux du ${s.label}`}>
            {s.slots.map((slot) => {
              const on = choice?.mode === 'slot' && choice.date === s.date && choice.start === slot.start
              return (
                <button key={slot.start} type="button" role="radio" aria-checked={on} disabled={slot.state !== 'free'}
                  className={`appt-slot ${on ? 'on' : ''} s-${slot.state}`} onClick={() => onChoose({ mode: 'slot', date: s.date, start: slot.start })}>
                  {hhmm(slot.start)}
                </button>
              )
            })}
          </div>
          {full ? <p className="helper">Toutes les heures de ce jour sont prises : choisissez une autre date.</p> : (
            <button type="button" className={`appt-any ${choice?.mode === 'session' && choice.date === s.date ? 'on' : ''}`}
              onClick={() => onChoose({ mode: 'session', date: s.date })}>
              <span className="vt-radio" aria-hidden />
              <span><strong>La première heure libre ce jour-là</strong><small>Elle vous est réservée aussitôt.</small></span>
            </button>
          )}
        </div>
      )}
    </article>
  )
}

export default function Appointments() {
  const [data, setData] = useState<MyAppointmentsData | null>(() => peek<MyAppointmentsData>('/me/appointments') ?? null)
  const [hostId, setHostId] = useState<number | null>(() => peek<MyAppointmentsData>('/me/appointments')?.host_id ?? null)
  const [choice, setChoice] = useState<Choice | null>(null)
  const [urgency, setUrgency] = useState<AppointmentUrgency>('normal')
  const draftKey = hostId ? `appointment:${hostId}` : null
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [cancelling, setCancelling] = useState(false)
  // Quatre dates d'abord (les deux prochaines semaines) : la suite sur demande.
  const [more, setMore] = useState(false)

  function choose(c: Choice) {
    setChoice(c)
    // L'etape suivante apparait sous la liste : on y amene la personne.
    window.setTimeout(() => document.querySelector('.appt-step-2')?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 80)
  }

  const load = useCallback(() => {
    api<MyAppointmentsData>(hostId ? `/me/appointments?host=${hostId}` : '/me/appointments').then((d) => { setData(d); if (!hostId && d.host_id) setHostId(d.host_id) }).catch(() => {})
  }, [hostId])
  useEffect(() => { load() }, [load])
  usePulse('appointments', load)
  useEffect(() => { if (draftKey) setMessage(readDraft<string>(draftKey) ?? '') }, [draftKey])
  useDraft(draftKey, message, (m) => !m.trim())

  // Creneau choisi puis pris entre-temps (actualisation) : le choix est retire.
  useEffect(() => {
    if (!data || choice?.mode !== 'slot') return
    const slot = data.sessions.find((s) => s.date === choice.date)?.slots.find((x) => x.start === choice.start)
    if (!slot || slot.state !== 'free') setChoice(null)
  }, [data, choice])

  const summary = useMemo(() => {
    if (!choice || !data) return null
    if (choice.mode === 'first') return 'Le plus tôt possible : premier créneau libre'
    const s = data.sessions.find((x) => x.date === choice.date)
    if (!s) return null
    return choice.mode === 'slot' ? `${cap(s.label)} à ${hhmm(choice.start)}` : `${cap(s.label)}, première heure libre`
  }, [choice, data])

  async function submit() {
    if (!choice || !hostId) return
    setBusy(true)
    try {
      await api('/me/appointments', {
        method: 'POST',
        body: {
          host_id: hostId, mode: choice.mode, urgency, message: message.trim() || null,
          starts_at: choice.mode === 'slot' ? `${choice.date} ${choice.start}` : null,
          date: choice.mode === 'session' ? choice.date : null,
        },
      })
      if (draftKey) clearDraft(draftKey)
      setMessage(''); setChoice(null); setUrgency('normal')
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } catch { /* toast */ } finally { setBusy(false); load() }
  }

  const host = data?.hosts.find((h) => h.id === hostId)

  return (
    <AppLayout title="Prendre rendez-vous avec le pasteur" subtitle="Après les cultes du mercredi et du dimanche">
      {!data ? <SkeletonCard /> : data.hosts.length === 0 ? (
        <div className="empty-state"><Icon name="calendar" size={28} /><h3>Pas encore de rendez-vous</h3><p>Les rendez-vous avec le pasteur seront bientôt proposés ici.</p></div>
      ) : (
        <div className="appt-page">
          {data.hosts.length > 1 && (
            <div className="appt-hosts" role="radiogroup" aria-label="Pasteur">
              {data.hosts.map((h) => (
                <button key={h.id} type="button" role="radio" aria-checked={h.id === hostId} className={`appt-host ${h.id === hostId ? 'on' : ''}`}
                  onClick={() => { setHostId(h.id); setChoice(null) }}>
                  {h.photo_url ? <img src={h.photo_url} alt="" /> : <span className="mini-avatar">{h.name.charAt(0)}</span>}
                  <span>{h.name}</span>
                </button>
              ))}
            </div>
          )}

          {data.active ? (
            <ActiveCard a={data.active} onCancel={() => setCancelling(true)} />
          ) : !data.accepting ? (
            <div className="empty-state"><Icon name="clock" size={28} /><h3>Pas de rendez-vous pour le moment</h3><p>{host?.name ?? 'Le pasteur'} ne prend pas de nouveaux rendez-vous en ce moment. Revenez bientôt.</p></div>
          ) : (
            <>
              <section className="panel appt-step">
                <h3 className="appt-step-title"><span>1</span>Quand ?</h3>
                <p className="helper">Chaque rendez-vous dure {data.slot_minutes} minutes{host && data.hosts.length > 1 ? `, avec ${host.name}` : ''}.</p>
                <button type="button" className={`appt-any appt-first ${choice?.mode === 'first' ? 'on' : ''}`} onClick={() => choose({ mode: 'first' })}>
                  <span className="vt-radio" aria-hidden />
                  <span><strong>Le plus tôt possible</strong><small>Le premier créneau libre vous est réservé aussitôt.</small></span>
                </button>
                {data.sessions.length === 0 ? <p className="helper mt">Aucune séance n'est ouverte dans les prochaines semaines.</p> : (
                  <div className="appt-sessions">
                    {(more ? data.sessions : data.sessions.slice(0, 4)).map((s) => <SessionCard key={s.date} s={s} choice={choice} onChoose={choose} />)}
                    {!more && data.sessions.length > 4 && (
                      <button type="button" className="btn btn-ghost small appt-more" onClick={() => setMore(true)}>Voir d'autres dates</button>
                    )}
                  </div>
                )}
              </section>

              {choice && (
                <section className="panel appt-step appt-step-2">
                  <h3 className="appt-step-title"><span>2</span>Votre demande</h3>
                  <p className="appt-summary"><Icon name="calendar" size={16} />{summary}</p>
                  <div className="field">
                    <label>Priorité</label>
                    <div className="seg appt-urgencies" role="radiogroup" aria-label="Priorité">
                      {(Object.keys(data.urgencies) as AppointmentUrgency[]).map((k) => (
                        <button key={k} type="button" role="radio" aria-checked={urgency === k} className={urgency === k ? 'on' : ''} onClick={() => setUrgency(k)}>{data.urgencies[k]}</button>
                      ))}
                    </div>
                    <p className="helper">{URGENCY_HINT[urgency]}</p>
                  </div>
                  <div className="field">
                    <label htmlFor="appt-message">Message pour le pasteur (facultatif)</label>
                    <textarea id="appt-message" className="input" rows={3} maxLength={1000} value={message} onChange={(e) => setMessage(e.target.value)}
                      placeholder="En quelques mots, le sujet de la rencontre." />
                    <p className="helper with-icon"><Icon name="lock" size={13} />Lu par le pasteur seulement, pas par le protocole.</p>
                  </div>
                  <p className="helper appt-instant"><Icon name="check" size={14} />Votre rendez-vous est confirmé dès que vous réservez.</p>
                  <button className="btn btn-primary" disabled={busy} onClick={submit}>
                    {busy ? <span className="spinner" /> : 'Réserver'}
                  </button>
                </section>
              )}
            </>
          )}

          {data.history.length > 0 && (
            <section className="panel">
              <div className="panel-head"><h3>Mes rendez-vous passés</h3></div>
              <ul className="appt-history">
                {data.history.map((a) => (
                  <li key={a.id}>
                    <span>{a.when ? cap(a.when) : cap(a.preferred_label ?? 'Demande')}</span>
                    <span className={`appt-status st-${a.status}`}>{a.status_label}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}
        </div>
      )}

      {cancelling && data?.active && (
        <ConfirmDialog
          title="Annuler le rendez-vous ?"
          text="Le créneau sera libéré pour une autre personne. Le pasteur et le protocole sont prévenus."
          confirmLabel="Annuler le rendez-vous"
          danger
          reason={{ label: 'Un mot pour le pasteur', required: false }}
          onConfirm={async (reason) => { await api(`/me/appointments/${data.active!.id}/cancel`, { method: 'POST', body: { reason: reason || null } }); load() }}
          onClose={() => setCancelling(false)}
        />
      )}
    </AppLayout>
  )
}
