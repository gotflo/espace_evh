import { useEffect, useMemo, useRef, useState } from 'react'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import type { AudienceScope, EventCategory, EventOccurrence, Recurrence } from '../types'
import { CAT_LABEL, parseYmd, ymd } from '../utils/events'
import { AudiencePicker } from './AudiencePicker'

const RECURRENCES: { key: Recurrence; label: string }[] = [
  { key: 'none', label: 'Une seule fois' },
  { key: 'daily', label: 'Chaque jour' },
  { key: 'weekly', label: 'Chaque semaine' },
  { key: 'biweekly', label: 'Toutes les 2 semaines' },
  { key: 'monthly', label: 'Chaque mois' },
]
const CATS = Object.keys(CAT_LABEL) as EventCategory[]
type Audience = 'personal' | 'church'

function hm(d: Date): string {
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
}

/** Heure proposee : 19h00, ou l'heure pleine suivante si le jour choisi est aujourd'hui. */
function suggestedStart(date: string): string {
  if (date !== ymd(new Date())) return '19:00'
  const next = new Date(); next.setMinutes(0, 0, 0); next.setHours(next.getHours() + 1)
  return next.getDate() === new Date().getDate() ? hm(next) : '23:00'
}
function addMinutes(time: string, minutes: number): string {
  const [h, m] = time.split(':').map(Number)
  const total = Math.min(23 * 60 + 59, h * 60 + m + minutes)
  return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`
}

/**
 * Programmer depuis le calendrier : un rendez-vous personnel (prive, pour tous les fideles)
 * ou un evenement de l'eglise (avec events.manage), creation ou modification.
 */
export function EventEditor({ date, event, onClose, onSaved }: {
  date?: string
  event?: EventOccurrence | null
  onClose: () => void
  onSaved: () => void
}) {
  const { hasPermission } = useAuth()
  const canChurch = hasPermission('events.manage')
  const editing = !!event
  const titleRef = useRef<HTMLInputElement>(null)

  // Valeurs de depart : l'evenement (toute la serie s'il est recurrent) ou le jour clique.
  const init = useMemo(() => {
    if (event) {
      const start = new Date(event.recurring ? event.series_starts_at : event.starts_at)
      const endIso = event.recurring ? event.series_ends_at : event.ends_at
      const end = endIso ? new Date(endIso) : null
      return {
        audience: (event.personal ? 'personal' : 'church') as Audience,
        title: event.title, category: event.category, date: ymd(start),
        start: hm(start), end: end ? hm(end) : '', endDate: end ? ymd(end) : ymd(start),
        allDay: event.all_day, recurrence: event.recurrence, until: event.recurrence_until ?? '',
        location: event.location ?? '', description: event.description ?? '',
        scopes: event.scopes, remindAll: !!event.remind_all,
      }
    }
    const d = date ?? ymd(new Date())
    const start = suggestedStart(d)
    return {
      audience: (canChurch ? 'church' : 'personal') as Audience,
      title: '', category: (canChurch ? 'culte' : 'autre') as EventCategory, date: d,
      start, end: addMinutes(start, 90), endDate: d, allDay: false, recurrence: 'none' as Recurrence, until: '',
      location: '', description: '', scopes: [] as AudienceScope[], remindAll: false,
    }
  }, [event, date, canChurch])

  const [f, setF] = useState(init)
  const [busy, setBusy] = useState(false)
  const set = <K extends keyof typeof init>(k: K, v: (typeof init)[K]) => setF((prev) => ({ ...prev, [k]: v }))

  useEffect(() => { titleRef.current?.focus() }, [])
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') onClose() }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const church = f.audience === 'church'
  const endBeforeStart = !f.allDay && f.end && `${f.endDate}T${f.end}` < `${f.date}T${f.start}`
  const valid = f.title.trim() && f.date && (f.allDay || f.start) && !endBeforeStart
    && (!church || f.scopes.length > 0)

  async function save() {
    if (!valid) return
    setBusy(true)
    const body: Record<string, unknown> = {
      title: f.title.trim(), category: f.category,
      starts_at: f.allDay ? f.date : `${f.date}T${f.start}`,
      ends_at: f.allDay ? (f.endDate && f.endDate !== f.date ? f.endDate : null) : (f.end ? `${f.endDate}T${f.end}` : null),
      all_day: f.allDay, recurrence: f.recurrence,
      recurrence_until: f.recurrence !== 'none' && f.until ? f.until : null,
      location: f.location || null, description: f.description || null,
    }
    if (church) {
      body.scopes = f.scopes
      body.remind_all = f.recurrence !== 'none' && f.remindAll
    }
    try {
      if (church && editing) await api(`/admin/events/${event!.event_id}`, { method: 'PUT', body })
      else if (church) await api('/admin/events', { method: 'POST', body })
      else if (editing) await api(`/me/events/${event!.event_id}`, { method: 'PUT', body })
      else await api('/me/events', { method: 'POST', body })
      onSaved(); onClose()
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  async function remove() {
    if (!event) return
    const what = event.recurring ? 'toute la série (toutes les occurrences)' : 'cet événement'
    if (!confirm(`Supprimer ${what} ?${event.personal ? '' : ' Les inscrits seront prévenus.'}`)) return
    setBusy(true)
    try {
      await api(event.personal ? `/me/events/${event.event_id}` : `/admin/events/${event.event_id}`, { method: 'DELETE' })
      onSaved(); onClose()
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  return (
    <div className="modal-overlay" onMouseDown={onClose}>
      <div className="modal-box editor-modal" role="dialog" aria-modal="true" aria-label={editing ? 'Modifier' : 'Programmer'}
        onMouseDown={(e) => e.stopPropagation()}>
        <div className="panel-head">
          <h3>{editing ? 'Modifier' : 'Programmer'}</h3>
          <button className="btn-link" onClick={onClose}>Fermer</button>
        </div>

        {canChurch && !editing && (
          <div className="seg" role="radiogroup" aria-label="Pour qui ?">
            <button role="radio" aria-checked={church} className={church ? 'on' : ''} onClick={() => set('audience', 'church')}>⛪ Pour l'église</button>
            <button role="radio" aria-checked={!church} className={!church ? 'on' : ''} onClick={() => set('audience', 'personal')}>🔒 Mon agenda</button>
          </div>
        )}
        {!church && <p className="helper editor-note">🔒 Visible par vous seul. Vous recevrez un rappel la veille et 1 h avant.</p>}
        {editing && event?.recurring && <p className="helper editor-note">↻ Les changements s'appliquent à toute la série.</p>}

        <input ref={titleRef} className="input editor-title" placeholder={church ? 'Titre (ex. Culte de louange)' : 'Titre (ex. Rendez-vous avec mon Garde)'}
          value={f.title} onChange={(e) => set('title', e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter') save() }} />

        <div className="cat-chips" role="radiogroup" aria-label="Catégorie">
          {CATS.map((c) => (
            <button key={c} role="radio" aria-checked={f.category === c} className={`cat-chip chip-evt-${c} ${f.category === c ? 'on' : ''}`} onClick={() => set('category', c)}>
              {CAT_LABEL[c]}
            </button>
          ))}
        </div>

        <div className="editor-when">
          <div className="field">
            <label>Date</label>
            <input className="input" type="date" value={f.date} onChange={(e) => {
              const d = e.target.value
              setF((p) => ({ ...p, date: d, endDate: p.endDate < d ? d : p.endDate }))
            }} />
          </div>
          {!f.allDay && (
            <>
              <div className="field">
                <label>Début</label>
                <input className="input" type="time" step={300} value={f.start} onChange={(e) => {
                  const s = e.target.value
                  // La fin suit le debut (meme duree), comme dans les agendas modernes.
                  setF((p) => {
                    if (!p.end || !p.start) return { ...p, start: s }
                    const [sh, sm] = p.start.split(':').map(Number); const [eh, em] = p.end.split(':').map(Number)
                    const dur = eh * 60 + em - (sh * 60 + sm)
                    return { ...p, start: s, end: dur > 0 && p.endDate === p.date ? addMinutes(s, dur) : p.end }
                  })
                }} />
              </div>
              <div className="field">
                <label>Fin</label>
                <input className="input" type="time" step={300} value={f.end} onChange={(e) => set('end', e.target.value)} />
              </div>
            </>
          )}
        </div>
        <div className="editor-row">
          <label className="switch-line">
            <input type="checkbox" checked={f.allDay} onChange={(e) => set('allDay', e.target.checked)} />
            Toute la journée
          </label>
          {(f.allDay || f.endDate !== f.date) && (
            <label className="inline-field">Jusqu'au
              <input className="input" type="date" min={f.date} value={f.endDate} onChange={(e) => set('endDate', e.target.value)} />
            </label>
          )}
          {!f.allDay && f.endDate === f.date && (
            <button className="btn-link" onClick={() => set('endDate', ymd(new Date(parseYmd(f.date).getTime() + 86400000)))}>Sur plusieurs jours</button>
          )}
        </div>
        {endBeforeStart && <p className="helper editor-error">La fin doit être après le début.</p>}

        <div className="field-row">
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Répétition</label>
            <select className="select" value={f.recurrence} onChange={(e) => set('recurrence', e.target.value as Recurrence)}>
              {RECURRENCES.map((r) => <option key={r.key} value={r.key}>{r.label}</option>)}
            </select>
          </div>
          {f.recurrence !== 'none' ? (
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Jusqu'au (optionnel)</label>
              <input className="input" type="date" min={f.date} value={f.until} onChange={(e) => set('until', e.target.value)} />
            </div>
          ) : (
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Lieu (optionnel)</label>
              <input className="input" value={f.location} onChange={(e) => set('location', e.target.value)} placeholder="Ex. Temple principal" />
            </div>
          )}
        </div>
        {f.recurrence !== 'none' && church && (
          <label className="switch-line mt">
            <input type="checkbox" checked={f.remindAll} onChange={(e) => set('remindAll', e.target.checked)} />
            <span>Rendez-vous régulier (culte) : rappel à tous 30 min avant chaque occurrence, et programme envoyé la veille au soir</span>
          </label>
        )}
        {f.recurrence !== 'none' && (
          <div className="field mt">
            <label>Lieu (optionnel)</label>
            <input className="input" value={f.location} onChange={(e) => set('location', e.target.value)} placeholder="Ex. Temple principal" />
          </div>
        )}
        <div className="field mt">
          <label>Description (optionnel)</label>
          <textarea className="input" rows={2} value={f.description} onChange={(e) => set('description', e.target.value)} />
        </div>

        {church && (
          <AudiencePicker value={f.scopes} onChange={(scopes) => setF((p) => ({ ...p, scopes }))} />
        )}
        {church && !editing && <p className="helper">Les destinataires sont prévenus immédiatement, puis rappelés la veille.</p>}

        <div className="editor-actions mt">
          <button className="btn btn-primary" disabled={busy || !valid} onClick={save}>
            {busy ? <span className="spinner" /> : editing ? 'Enregistrer' : church ? "Publier l'événement" : 'Ajouter à mon agenda'}
          </button>
          {editing && <button className="btn btn-ghost danger" disabled={busy} onClick={remove}>Supprimer</button>}
        </div>
      </div>
    </div>
  )
}
