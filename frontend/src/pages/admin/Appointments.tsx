import { useCallback, useEffect, useMemo, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api, peek } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Icon } from '../../components/Icon'
import { SkeletonCard } from '../../components/Skeleton'
import { usePulse } from '../../pulse'
import { ymd } from '../../utils/events'
import type { AdminAppointmentsData, AppointmentItem, AppointmentSession, AppointmentSlot, AppointmentUrgency } from '../../types'

const cap = (s: string) => s.charAt(0).toUpperCase() + s.slice(1)
const hhmm = (t: string) => { const [h, m] = t.split(':'); return `${Number(h)}h${m}` }
const WEEKDAYS = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi']
const DURATIONS = [10, 15, 20, 30]

function waited(iso: string | null): string {
  if (!iso) return ''
  const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000)
  return days <= 0 ? "aujourd'hui" : days === 1 ? 'hier' : `il y a ${days} jours`
}

type Dialog =
  | { kind: 'cancel'; a: AppointmentItem }
  | { kind: 'block-session'; s: AppointmentSession }
  | { kind: 'block-slot'; s: AppointmentSession; slot: AppointmentSlot }

/** Personne concernee : nom, tribu, priorite, telephone et (pour le pasteur) son message. */
function Who({ a }: { a: AppointmentItem }) {
  return (
    <div className="appt-who">
      {a.member?.photo_url ? <img className="mini-avatar" src={a.member.photo_url} alt="" /> : <span className="mini-avatar">{(a.member?.name ?? '?').charAt(0).toUpperCase()}</span>}
      <div className="appt-who-text">
        <strong>{a.member?.name}</strong>
        <small>{[a.member?.tribe ? `Tribu ${a.member.tribe}` : null, a.created_at ? `demandé ${waited(a.created_at)}` : null].filter(Boolean).join(' · ')}</small>
        <span className="appt-who-tags">
          <span className={`appt-urgency u-${a.urgency}`}>{a.urgency_label}</span>
          {a.member?.phone && <a className="appt-chip" href={`tel:${a.member.phone}`}><Icon name="phone" size={12} />Appeler</a>}
        </span>
        {a.message && <p className="appt-message"><Icon name="lock" size={12} /> {a.message}</p>}
      </div>
    </div>
  )
}

export default function AdminAppointments() {
  const [params, setParams] = useSearchParams()
  const [hostId, setHostId] = useState<number | null>(() => peek<AdminAppointmentsData>('/admin/appointments')?.host_id ?? null)
  const [data, setData] = useState<AdminAppointmentsData | null>(() => peek<AdminAppointmentsData>('/admin/appointments') ?? null)
  const [placing, setPlacing] = useState<AppointmentItem | null>(null)
  const [dialog, setDialog] = useState<Dialog | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const load = useCallback(() => {
    api<AdminAppointmentsData>(hostId ? `/admin/appointments?host=${hostId}` : '/admin/appointments').then((d) => { setData(d); if (!hostId && d.host_id) setHostId(d.host_id) }).catch(() => {})
  }, [hostId])
  useEffect(() => { load() }, [load])
  usePulse('appointments', load)

  const tabs: [string, string][] = [['organiser', 'Organiser'], ...(data?.can_manage ? [['disponibilites', 'Disponibilités'] as [string, string]] : []), ['historique', 'Historique']]
  const tab = tabs.some(([k]) => k === params.get('onglet')) ? params.get('onglet')! : 'organiser'

  async function run(key: string, fn: () => Promise<unknown>) {
    setBusy(key)
    try { await fn() } catch { /* toast */ } finally { setBusy(null); load() }
  }

  async function placeAt(s: AppointmentSession, slot: AppointmentSlot) {
    if (!placing) return
    const a = placing
    setPlacing(null)
    await run(`p${a.id}`, () => api(`/admin/appointments/${a.id}/assign`, { method: 'POST', body: { starts_at: `${s.date} ${slot.start}` } }))
  }

  const host = data?.hosts.find((h) => h.id === data.host_id)
  const today = new Date().toLocaleDateString('en-CA')
  /** Premier rendez-vous encore attendu de la seance du jour : celui qui entre (ou est en cours). */
  const nextOf = (s: AppointmentSession) => (s.date === today ? s.slots.find((x) => x.state === 'booked')?.appointment_id : undefined)
  const upcoming = useMemo(() => (data?.sessions ?? []).filter((s) => !s.over || s.slots.some((x) => x.state === 'booked')), [data])

  return (
    <AppLayout title="Rendez-vous avec le pasteur" subtitle={host ? (data!.is_host ? 'Vos rendez-vous et vos disponibilités' : `Rendez-vous de ${host.name}`) : 'Demandes et rencontres après les cultes'}>
      {!data ? <SkeletonCard /> : data.hosts.length === 0 ? (
        <div className="empty-state"><Icon name="calendar" size={28} /><h3>Aucun pasteur ne reçoit de rendez-vous</h3><p>Le Pasteur Résident reçoit les rendez-vous ; d'autres pasteurs peuvent en recevoir avec la permission « Recevoir des rendez-vous ».</p></div>
      ) : (
        <div className="appt-page">
          {data.hosts.length > 1 && (
            <div className="appt-hosts" role="radiogroup" aria-label="Pasteur">
              {data.hosts.map((h) => (
                <button key={h.id} type="button" role="radio" aria-checked={h.id === data.host_id} className={`appt-host ${h.id === data.host_id ? 'on' : ''}`}
                  onClick={() => { setHostId(h.id); setPlacing(null) }}>
                  {h.photo_url ? <img src={h.photo_url} alt="" /> : <span className="mini-avatar">{h.name.charAt(0)}</span>}
                  <span>{h.name}</span>
                </button>
              ))}
            </div>
          )}

          <div className="seg gr-tabs" role="tablist">
            {tabs.map(([k, l]) => (
              <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => { setParams({ onglet: k }, { replace: true }); setPlacing(null) }}>
                {l}
              </button>
            ))}
          </div>

          {tab === 'organiser' && (
            <>
              {host && !host.accepting && <div className="alert appt-paused"><Icon name="alert" size={16} /> Les nouvelles demandes sont fermées pour le moment (Disponibilités).</div>}
              {placing && (
                <div className="appt-placing" role="status">
                  <Icon name="calendar" size={18} />
                  <span>Choisissez le nouveau créneau de <b>{placing.member?.name}</b>{placing.when ? ` (actuellement ${placing.when})` : ''}.</span>
                  <button className="btn-link" onClick={() => setPlacing(null)}>Annuler</button>
                </div>
              )}

              <section className="panel">
                <div className="panel-head"><h3>Par ordre de priorité <span className="count-soft">{data.priority.length}</span></h3></div>
                {data.priority.length === 0 ? <p className="helper">Aucun rendez-vous à venir. Chaque réservation d'un membre est confirmée aussitôt et apparaît ici, de la plus urgente à la plus ancienne.</p> : (
                  <ol className="appt-queue">
                    {data.priority.map((a) => (
                      <li key={a.id} className={`appt-queue-item ${placing?.id === a.id ? 'is-placing' : ''}`}>
                        <span className="appt-rank" aria-label={`Priorité ${a.rank}`}>{a.rank}</span>
                        <Who a={a} />
                        <div className="appt-queue-side">
                          <span className="appt-wish"><Icon name="calendar" size={13} />{cap(a.when ?? '')}</span>
                          <div className="appt-actions">
                            <select className="select small" aria-label="Priorité" value={a.urgency} disabled={busy === `u${a.id}`}
                              onChange={(e) => run(`u${a.id}`, () => api(`/admin/appointments/${a.id}/urgency`, { method: 'POST', body: { urgency: e.target.value as AppointmentUrgency } }))}>
                              {(Object.keys(data.urgencies) as AppointmentUrgency[]).map((k) => <option key={k} value={k}>{data.urgencies[k]}</option>)}
                            </select>
                            <button className="btn btn-ghost small" onClick={() => { setPlacing(a); document.querySelector('.appt-sessions-admin')?.scrollIntoView({ behavior: 'smooth' }) }}>Déplacer</button>
                            <button className="btn btn-ghost small" onClick={() => setDialog({ kind: 'cancel', a })}>Révoquer</button>
                          </div>
                        </div>
                      </li>
                    ))}
                  </ol>
                )}
              </section>

              <div className="appt-sessions-admin">
                {upcoming.length === 0 && <p className="helper">Aucune séance à venir.</p>}
                {upcoming.map((s) => (
                  <section key={s.date} className={`panel appt-day ${s.blocked ? 'is-blocked' : ''}`}>
                    <div className="appt-day-head">
                      <div>
                        <h3>{cap(s.label)}</h3>
                        <small>{s.range} · {s.blocked ? `séance bloquée${s.block_reason ? ` (${s.block_reason})` : ''}` : `${s.booked} rendez-vous · ${s.free} libre${s.free > 1 ? 's' : ''}`}</small>
                      </div>
                    </div>
                    <ol className="appt-timeline">
                      {s.slots.map((slot) => (
                        <li key={slot.start} className={`appt-row r-${slot.state} ${placing && slot.state === 'free' ? 'can-place' : ''} ${slot.appointment_id && slot.appointment_id === nextOf(s) ? 'is-next' : ''}`}>
                          <span className="appt-row-time">{hhmm(slot.start)}</span>
                          <div className="appt-row-body">
                            {slot.state === 'booked' && slot.appointment ? (
                              <>
                                {slot.appointment_id === nextOf(s) && (
                                  <span className="appt-now">{new Date(slot.at) <= new Date() ? 'En cours' : 'Suivant(e)'}</span>
                                )}
                                <Who a={slot.appointment} />
                                {slot.outside && <p className="helper">Hors des plages actuelles (plage ou durée modifiée).</p>}
                                <div className="appt-actions">
                                  {s.date === today ? (
                                    <>
                                      {/* Les creneaux se suivent : « Termine » appelle aussitot la personne suivante. */}
                                      <button className={`btn small ${slot.appointment_id === nextOf(s) ? 'btn-primary' : 'btn-ghost'}`} disabled={!!busy} onClick={() => run(`o${slot.appointment!.id}`, () => api(`/admin/appointments/${slot.appointment!.id}/outcome`, { method: 'POST', body: { status: 'done' } }))}>Terminé</button>
                                      <button className="btn btn-ghost small" disabled={!!busy} onClick={() => run(`o${slot.appointment!.id}`, () => api(`/admin/appointments/${slot.appointment!.id}/outcome`, { method: 'POST', body: { status: 'no_show' } }))}>Absent</button>
                                      <button className="btn btn-ghost small" disabled={!!busy} onClick={() => run(`c${slot.appointment!.id}`, () => api(`/admin/appointments/${slot.appointment!.id}/call`, { method: 'POST' }))}>Faire entrer</button>
                                    </>
                                  ) : (
                                    <>
                                      <button className="btn btn-ghost small" onClick={() => setPlacing(slot.appointment!)}>Déplacer</button>
                                      <button className="btn btn-ghost small" onClick={() => setDialog({ kind: 'cancel', a: slot.appointment! })}>Révoquer</button>
                                    </>
                                  )}
                                </div>
                              </>
                            ) : slot.state === 'done' && slot.appointment ? (
                              <div className="appt-seen">
                                <span>{slot.appointment.member?.name}</span>
                                <span className={`appt-status st-${slot.appointment.status}`}>{slot.appointment.status === 'no_show' ? 'Absent' : 'Reçu(e)'}</span>
                              </div>
                            ) : slot.state === 'free' && placing ? (
                              <button className="btn btn-primary small appt-place-here" disabled={!!busy} onClick={() => placeAt(s, slot)}>Placer ici</button>
                            ) : (
                              <span className="appt-row-state">{slot.state === 'free' ? 'Libre' : slot.state === 'blocked' ? 'Bloqué' : '—'}</span>
                            )}
                          </div>
                        </li>
                      ))}
                    </ol>
                  </section>
                ))}
              </div>
            </>
          )}

          {tab === 'disponibilites' && data.availability && data.host_id && (
            // Remonte le formulaire quand les reglages changent sur le serveur (autre appareil, enregistrement).
            <Availability key={JSON.stringify([data.host_id, data.availability.accepting, data.availability.slot_minutes, data.availability.weekly])}
              data={data} hostId={data.host_id} reload={load} onDialog={setDialog} />
          )}

          {tab === 'historique' && (
            <section className="panel">
              <div className="panel-head"><h3>30 derniers jours</h3></div>
              {data.recent.length === 0 ? <p className="helper">Aucun rendez-vous terminé ou annulé ces 30 derniers jours.</p> : (
                <ul className="appt-history">
                  {data.recent.map((a) => (
                    <li key={a.id}>
                      <span><b>{a.member?.name}</b><small>{a.when ? cap(a.when) : cap(a.preferred_label ?? 'Demande')}{a.cancel_reason ? ` · ${a.cancel_reason}` : ''}</small></span>
                      <span className={`appt-status st-${a.status}`}>{a.status_label}</span>
                    </li>
                  ))}
                </ul>
              )}
            </section>
          )}
        </div>
      )}

      {dialog?.kind === 'cancel' && (
        <ConfirmDialog
          title="Révoquer ce rendez-vous ?"
          text={`${dialog.a.member?.name} est prévenu(e) et peut reprendre rendez-vous sur un autre créneau.${dialog.a.when ? ` Rendez-vous : ${dialog.a.when}.` : ''}`}
          confirmLabel="Révoquer"
          danger
          reason={{ label: 'Motif communiqué au membre', required: false, placeholder: 'Ex. le pasteur est en déplacement ce jour-là' }}
          onConfirm={async (reason) => { await api(`/admin/appointments/${dialog.a.id}/cancel`, { method: 'POST', body: { reason } }); load() }}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog?.kind === 'block-session' && data?.host_id && (
        <ConfirmDialog
          title={`Bloquer le ${dialog.s.label} ?`}
          text={dialog.s.booked > 0
            ? `${dialog.s.booked} rendez-vous ${dialog.s.booked > 1 ? 'seront déplacés' : 'sera déplacé'} automatiquement au prochain créneau libre, les plus urgents d'abord. Les membres sont prévenus.`
            : 'Plus aucun rendez-vous ne pourra être pris ce jour-là.'}
          confirmLabel="Bloquer la séance"
          danger={dialog.s.booked > 0}
          reason={{ label: 'Motif (pour le protocole)', required: false, placeholder: 'Ex. voyage, conférence' }}
          onConfirm={async (reason) => { await api('/admin/appointment-availability/blocks', { method: 'POST', body: { host_id: data.host_id, date: dialog.s.date, reason: reason || null } }); load() }}
          onClose={() => setDialog(null)}
        />
      )}
      {dialog?.kind === 'block-slot' && data?.host_id && (
        <ConfirmDialog
          title={`Bloquer ${hhmm(dialog.slot.start)} le ${dialog.s.label} ?`}
          text={dialog.slot.state === 'booked' ? `Le rendez-vous de ${dialog.slot.appointment?.member?.name ?? 'ce membre'} sera déplacé automatiquement au prochain créneau libre. La personne est prévenue.` : 'Ce créneau ne sera plus proposé.'}
          confirmLabel="Bloquer"
          danger={dialog.slot.state === 'booked'}
          onConfirm={async () => { await api('/admin/appointment-availability/blocks', { method: 'POST', body: { host_id: data.host_id, date: dialog.s.date, starts_at: dialog.slot.start, ends_at: dialog.slot.end } }); load() }}
          onClose={() => setDialog(null)}
        />
      )}
    </AppLayout>
  )
}

/** Disponibilites du pasteur : plages de chaque semaine, duree, plages ponctuelles, blocages. */
function Availability({ data, hostId, reload, onDialog }: { data: AdminAppointmentsData; hostId: number; reload: () => void; onDialog: (d: Dialog) => void }) {
  const av = data.availability!
  const [accepting, setAccepting] = useState(av.accepting)
  const [minutes, setMinutes] = useState(av.slot_minutes)
  const [weekly, setWeekly] = useState(av.weekly.map((w) => ({ weekday: w.weekday ?? 0, starts_at: w.starts_at, ends_at: w.ends_at })))
  const [extra, setExtra] = useState({ date: '', starts_at: '', ends_at: '' })
  const [busy, setBusy] = useState(false)

  const original = JSON.stringify({ a: av.accepting, m: av.slot_minutes, w: av.weekly.map((w) => [w.weekday, w.starts_at, w.ends_at]) })
  const current = JSON.stringify({ a: accepting, m: minutes, w: weekly.map((w) => [w.weekday, w.starts_at, w.ends_at]) })
  const dirty = original !== current

  const invalid = weekly.some((w) => !w.starts_at || !w.ends_at || w.starts_at >= w.ends_at)

  async function save() {
    setBusy(true)
    try { await api('/admin/appointment-availability', { method: 'PUT', body: { host_id: hostId, accepting, slot_minutes: minutes, weekly } }) } catch { /* toast */ } finally { setBusy(false); reload() }
  }
  async function addExtra() {
    setBusy(true)
    try {
      await api('/admin/appointment-availability/windows', { method: 'POST', body: { host_id: hostId, ...extra } })
      setExtra({ date: '', starts_at: '', ends_at: '' })
    } catch { /* toast */ } finally { setBusy(false); reload() }
  }
  async function removeWindow(id: number) {
    try { await api(`/admin/appointment-availability/windows/${id}`, { method: 'DELETE' }) } catch { /* toast */ } finally { reload() }
  }
  async function unblock(id: number | null) {
    if (!id) return
    try { await api(`/admin/appointment-availability/blocks/${id}`, { method: 'DELETE' }) } catch { /* toast */ } finally { reload() }
  }

  const upcoming = data.sessions.filter((s) => !s.over)

  return (
    <>
      <section className="panel">
        <div className="panel-head"><h3>Chaque semaine</h3></div>
        <label className="switch-line">
          <input type="checkbox" checked={accepting} onChange={(e) => setAccepting(e.target.checked)} />
          <span><b>Je prends des rendez-vous</b><br /><small className="helper">Décoché : les membres ne peuvent plus faire de nouvelle demande (les rendez-vous pris restent).</small></span>
        </label>
        <div className="field mt">
          <label>Durée d'un rendez-vous</label>
          <div className="seg" role="radiogroup" aria-label="Durée d'un rendez-vous">
            {DURATIONS.map((m) => <button key={m} type="button" role="radio" aria-checked={minutes === m} className={minutes === m ? 'on' : ''} onClick={() => setMinutes(m)}>{m} min</button>)}
          </div>
          <p className="helper">Au moins 6 rendez-vous par séance : si la plage est trop courte, la fin est prolongée.</p>
        </div>
        <ul className="appt-windows">
          {weekly.map((w, i) => (
            <li key={i} className="appt-window">
              <select className="select" aria-label="Jour" value={w.weekday} onChange={(e) => setWeekly(weekly.map((x, j) => (j === i ? { ...x, weekday: Number(e.target.value) } : x)))}>
                {WEEKDAYS.map((d, k) => <option key={d} value={k}>{d}</option>)}
              </select>
              <input className="input" type="time" aria-label="Début" value={w.starts_at} onChange={(e) => setWeekly(weekly.map((x, j) => (j === i ? { ...x, starts_at: e.target.value } : x)))} />
              <span aria-hidden>→</span>
              <input className="input" type="time" aria-label="Fin" value={w.ends_at} onChange={(e) => setWeekly(weekly.map((x, j) => (j === i ? { ...x, ends_at: e.target.value } : x)))} />
              <button className="icon-btn" aria-label="Retirer cette plage" onClick={() => setWeekly(weekly.filter((_, j) => j !== i))}><Icon name="trash" size={16} /></button>
            </li>
          ))}
        </ul>
        <button className="btn-link" onClick={() => setWeekly([...weekly, { weekday: 3, starts_at: '21:50', ends_at: '23:20' }])}><Icon name="plus" size={14} /> Ajouter une plage</button>
        {invalid && <p className="helper appt-warn">L'heure de fin doit être après l'heure de début.</p>}
        <div className="row-actions mt">
          <button className="btn btn-primary small" disabled={busy || !dirty || invalid} onClick={save}>{busy ? <span className="spinner" /> : 'Enregistrer'}</button>
        </div>
      </section>

      <section className="panel">
        <div className="panel-head"><h3>Une date en plus</h3></div>
        <div className="appt-extra-form">
          <input className="input" type="date" aria-label="Date" min={ymd(new Date())} value={extra.date} onChange={(e) => setExtra({ ...extra, date: e.target.value })} />
          <input className="input" type="time" aria-label="Début" value={extra.starts_at} onChange={(e) => setExtra({ ...extra, starts_at: e.target.value })} />
          <input className="input" type="time" aria-label="Fin" value={extra.ends_at} onChange={(e) => setExtra({ ...extra, ends_at: e.target.value })} />
          <button className="btn btn-primary small" disabled={busy || !extra.date || !extra.starts_at || !extra.ends_at || extra.starts_at >= extra.ends_at} onClick={addExtra}>Ajouter</button>
        </div>
        {av.extra.length > 0 && (
          <ul className="appt-history mt">
            {av.extra.map((w) => (
              <li key={w.id}>
                <span><b>{cap(w.label ?? '')}</b><small>{hhmm(w.starts_at)} – {hhmm(w.ends_at)}</small></span>
                <button className="btn-link" onClick={() => removeWindow(w.id)}>Retirer</button>
              </li>
            ))}
          </ul>
        )}
      </section>

      <section className="panel">
        <div className="panel-head"><h3>Prochaines séances</h3></div>
        <p className="helper">Touchez un créneau pour le bloquer ou le rouvrir. Un rendez-vous déjà pris sur un créneau bloqué est déplacé automatiquement au prochain créneau libre (la personne est prévenue).</p>
        {upcoming.length === 0 && <p className="helper">Aucune séance à venir.</p>}
        <div className="appt-avail-days">
          {upcoming.map((s) => (
            <article key={s.date} className={`appt-avail-day ${s.blocked ? 'is-blocked' : ''}`}>
              <div className="appt-day-head">
                <div><strong>{cap(s.label)}</strong><small>{s.range}{s.blocked && s.block_reason ? ` · ${s.block_reason}` : ''}</small></div>
                {s.blocked
                  ? <button className="btn btn-ghost small" onClick={() => unblock(s.block_id)}>Rouvrir</button>
                  : <button className="btn btn-ghost small" onClick={() => onDialog({ kind: 'block-session', s })}>Bloquer la séance</button>}
              </div>
              {!s.blocked && (
                <div className="appt-slots">
                  {s.slots.map((slot) => (
                    <button key={slot.start} type="button" disabled={slot.state === 'past' || !!slot.outside}
                      className={`appt-slot s-${slot.state}`} aria-label={`${hhmm(slot.start)} : ${slot.state === 'blocked' ? 'bloqué, toucher pour rouvrir' : slot.state === 'booked' ? `réservé par ${slot.appointment?.member?.name ?? 'un membre'}` : 'libre, toucher pour bloquer'}`}
                      onClick={() => (slot.state === 'blocked' && slot.block_id ? unblock(slot.block_id) : onDialog({ kind: 'block-slot', s, slot }))}>
                      {hhmm(slot.start)}
                      {slot.state === 'booked' && <Icon name="profile" size={11} />}
                      {slot.state === 'blocked' && <Icon name="lock" size={11} />}
                    </button>
                  ))}
                </div>
              )}
            </article>
          ))}
        </div>
      </section>
    </>
  )
}
