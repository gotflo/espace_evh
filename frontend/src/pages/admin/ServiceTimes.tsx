import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, peek } from '../../api/client'
import { usePulse } from '../../pulse'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import { SkeletonCard } from '../../components/Skeleton'
import { Icon } from '../../components/Icon'
import type { ServiceSlot } from '../../types'import { ModalLayer } from '../../components/ModalLayer'


const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']
const SHORT = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim']
const CATEGORIES: [string, string][] = [['culte', 'Culte'], ['priere', 'Prière'], ['formation', 'Formation'], ['autre', 'Autre']]
const hm = (t: string) => t.replace(':', 'h')

function Editor({ slot, onClose, onSaved }: { slot: ServiceSlot | null; onClose: () => void; onSaved: () => void }) {
  const [title, setTitle] = useState(slot?.title ?? '')
  const [day, setDay] = useState(slot?.day ?? 7)
  const [start, setStart] = useState(slot?.start ?? '09:30')
  const [hasEnd, setHasEnd] = useState(!!slot?.end)
  const [end, setEnd] = useState(slot?.end ?? '')
  const [category, setCategory] = useState(slot?.category ?? 'culte')
  const [description, setDescription] = useState(slot?.description ?? '')
  const [notify, setNotify] = useState(false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)

  const endBad = hasEnd && (!end || end <= start)

  async function save() {
    setBusy(true); setErrors({})
    const body = { title: title.trim(), day, start, end: hasEnd ? end : null, category, description: description.trim() || null, notify }
    try {
      if (slot) await api(`/admin/service-schedule/${slot.id}`, { method: 'PUT', body })
      else await api('/admin/service-schedule', { method: 'POST', body })
      onSaved(); onClose()
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.errors)
    } finally { setBusy(false) }
  }
  const err = (k: string) => errors[k]?.[0] && <p className="helper editor-error">{errors[k][0]}</p>

  return (
    <ModalLayer className="modal-overlay" onMouseDown={onClose}>
      <div className="modal-box" role="dialog" aria-modal="true" aria-label={slot ? 'Modifier le rendez-vous' : 'Nouveau rendez-vous'} onMouseDown={(e) => e.stopPropagation()}>
        <div className="panel-head">
          <h3>{slot ? 'Modifier le rendez-vous' : 'Nouveau rendez-vous'}</h3>
          <button className="btn-link" onClick={onClose}>Fermer</button>
        </div>
        <div className="field">
          <label htmlFor="st-title">Intitulé</label>
          <input id="st-title" className="input" maxLength={150} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Ex. Culte de célébration" />
          {err('title')}
        </div>
        <div className="field">
          <label id="st-day">Jour</label>
          <div className="day-picker" role="radiogroup" aria-labelledby="st-day">
            {SHORT.map((d, i) => (
              <button key={d} type="button" role="radio" aria-checked={day === i + 1} aria-label={DAYS[i]}
                className={`day-option ${day === i + 1 ? 'on' : ''}`} onClick={() => setDay(i + 1)}>{d}</button>
            ))}
          </div>
        </div>
        <div className="field-row">
          <div className="field">
            <label htmlFor="st-start">Début</label>
            <input id="st-start" className="input" type="time" value={start} onChange={(e) => setStart(e.target.value)} />
          </div>
          <div className="field">
            <label htmlFor="st-end">Fin <span className="helper" style={{ display: 'inline' }}>(facultatif)</span></label>
            {hasEnd
              ? <input id="st-end" className="input" type="time" value={end} onChange={(e) => setEnd(e.target.value)} />
              : <button type="button" className="btn btn-ghost small st-add-end" onClick={() => { setHasEnd(true); if (!end) setEnd(start) }}>Ajouter une fin</button>}
            {hasEnd && <button type="button" className="btn-link st-no-end" onClick={() => { setHasEnd(false); setEnd('') }}>Sans heure de fin</button>}
            {endBad && end && <p className="helper editor-error">La fin doit être après le début.</p>}
            {err('end')}
          </div>
        </div>
        <div className="field">
          <label htmlFor="st-cat">Type</label>
          <select id="st-cat" className="select" value={category} onChange={(e) => setCategory(e.target.value)}>
            {CATEGORIES.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
          </select>
        </div>
        <div className="field">
          <label htmlFor="st-desc">Description <span className="helper" style={{ display: 'inline' }}>(facultatif)</span></label>
          <textarea id="st-desc" className="input" rows={2} maxLength={1000} value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <label className="switch-line">
          <input type="checkbox" checked={notify} onChange={(e) => setNotify(e.target.checked)} />
          <span>Prévenir toute l'église de ce changement</span>
        </label>
        <p className="helper">Chacun reçoit déjà le programme la veille au soir et un rappel 30 minutes avant.</p>
        <button className="btn btn-primary mt" disabled={busy || title.trim().length < 2 || !start || endBad} onClick={save}>
          {busy ? <span className="spinner" /> : slot ? 'Enregistrer' : 'Ajouter le rendez-vous'}
        </button>
      </div>
    </ModalLayer>
  )
}

/** Horaires des cultes (PR, PA) : rendez-vous hebdomadaires de toute l'eglise, groupes par jour. */
export default function ServiceTimes() {
  const { hasPermission } = useAuth()
  const canEdit = hasPermission('schedule.edit')
  const canDelete = hasPermission('schedule.delete')
  const [slots, setSlots] = useState<ServiceSlot[] | null>(() => peek<{ services: ServiceSlot[] }>('/admin/service-schedule')?.services ?? null)
  const [editing, setEditing] = useState<ServiceSlot | null | 'new'>(null)
  const load = useCallback(() => { api<{ services: ServiceSlot[] }>('/admin/service-schedule').then((r) => setSlots(r.services)).catch(() => setSlots((p) => p ?? [])) }, [])
  useEffect(() => { load() }, [load])
  usePulse('events', load)

  async function remove(s: ServiceSlot) {
    if (!confirm(`Retirer « ${s.title} » des horaires ? Il disparaîtra du calendrier de tous.`)) return
    try { await api(`/admin/service-schedule/${s.id}`, { method: 'DELETE' }); load() } catch { /* toast */ }
  }

  const days = [1, 2, 3, 4, 5, 6, 7].map((d) => ({ d, items: (slots ?? []).filter((s) => s.day === d) })).filter((g) => g.items.length)

  return (
    <AppLayout title="Horaires des cultes" subtitle="Programme hebdomadaire de toute l'église"
      actions={canEdit ? <button className="btn btn-primary small" onClick={() => setEditing('new')}><Icon name="plus" size={16} /> Ajouter</button> : undefined}>
      <p className="helper st-intro">Ces horaires apparaissent sur le tableau de bord et dans le calendrier de chacun, avec le programme la veille et un rappel avant chaque rendez-vous.</p>
      {!slots ? <div className="panel-grid"><SkeletonCard /></div> : (
        <div className="st-days">
          {days.map(({ d, items }) => (
            <section key={d} className="panel st-day">
              <h3>{DAYS[d - 1]}</h3>
              <ul>
                {items.map((s) => (
                  <li key={s.id} className="st-slot">
                    <span className="st-time">{hm(s.start)}{s.end && <small>– {hm(s.end)}</small>}</span>
                    <span className="st-title">{s.title}{s.description && <small>{s.description}</small>}</span>
                    <span className="st-actions">
                      {canEdit && <button className="icon-btn" aria-label={`Modifier ${s.title}`} onClick={() => setEditing(s)}><Icon name="edit" size={17} /></button>}
                      {canDelete && <button className="icon-btn danger" aria-label={`Retirer ${s.title}`} onClick={() => remove(s)}><Icon name="trash" size={17} /></button>}
                    </span>
                  </li>
                ))}
              </ul>
            </section>
          ))}
          {days.length === 0 && <section className="panel empty-state"><h3>Aucun horaire</h3><p>Ajoutez le premier rendez-vous de la semaine.</p></section>}
        </div>
      )}
      {editing && <Editor slot={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={load} />}
    </AppLayout>
  )
}
