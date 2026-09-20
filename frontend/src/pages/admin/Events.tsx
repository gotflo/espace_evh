import { useCallback, useEffect, useRef, useState, type ChangeEvent } from 'react'
import { api, ApiError } from '../../api/client'
import { getReference } from '../../api/reference'
import { AppLayout } from '../../components/AppLayout'
import { SkeletonCard } from '../../components/Skeleton'
import { TargetField } from '../../components/TargetField'
import type { Department, EventAdminItem, EventCategory, EventParticipants, Tribe } from '../../types'

const CATEGORIES: { key: EventCategory; label: string }[] = [
  { key: 'culte', label: 'Culte' },
  { key: 'priere', label: 'Prière' },
  { key: 'formation', label: 'Formation' },
  { key: 'reunion', label: 'Réunion' },
  { key: 'sortie', label: 'Sortie' },
  { key: 'autre', label: 'Autre' },
]
const CAT_LABEL: Record<string, string> = Object.fromEntries(CATEGORIES.map((c) => [c.key, c.label]))

function fmtDate(iso: string | null): string {
  if (!iso) return ''
  return new Date(iso).toLocaleString('fr-CA', { dateStyle: 'medium', timeStyle: 'short' })
}
/** ISO -> valeur pour <input type=datetime-local> (heure locale). */
function toLocalInput(iso: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`
}

const EMPTY = {
  id: 0, title: '', description: '', category: 'culte' as EventCategory,
  starts_at: '', ends_at: '', location: '',
  target_type: 'all' as 'all' | 'tribe' | 'department', target_id: '',
}

export default function Events() {
  const [items, setItems] = useState<EventAdminItem[]>([])
  const [loading, setLoading] = useState(true)
  const [tribes, setTribes] = useState<Tribe[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [mode, setMode] = useState<'list' | 'form'>('list')
  const [form, setForm] = useState({ ...EMPTY })
  const [image, setImage] = useState<File | null>(null)
  const [imagePreview, setImagePreview] = useState<string | null>(null)
  const [existingImage, setExistingImage] = useState<string | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [participants, setParticipants] = useState<EventParticipants | null>(null)
  const fileRef = useRef<HTMLInputElement>(null)

  function openParticipants(id: number) {
    setParticipants(null)
    api<EventParticipants>(`/admin/events/${id}/participants`).then(setParticipants).catch(() => {})
  }

  const load = useCallback(() => {
    setLoading(true)
    api<{ events: EventAdminItem[] }>('/admin/events')
      .then((r) => setItems(r.events)).catch(() => setItems([]))
      .finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    getReference().then((r) => { setTribes(r.tribes); setDepartments(r.departments) }).catch(() => {})
  }, [])

  function clearImage() { setImage(null); setImagePreview(null) }
  function openNew() { setForm({ ...EMPTY }); clearImage(); setExistingImage(null); setError(''); setMode('form') }
  function openEdit(e: EventAdminItem) {
    setForm({
      id: e.id, title: e.title, description: e.description ?? '', category: e.category,
      starts_at: toLocalInput(e.starts_at), ends_at: toLocalInput(e.ends_at), location: e.location ?? '',
      target_type: e.target_type, target_id: e.target_id?.toString() ?? '',
    })
    clearImage(); setExistingImage(e.image_url); setError(''); setMode('form')
  }
  function onPickImage(ev: ChangeEvent<HTMLInputElement>) {
    const file = ev.target.files?.[0]
    if (!file) return
    setImage(file); setImagePreview(URL.createObjectURL(file))
  }

  async function save() {
    setError(''); setBusy(true)
    const fd = new FormData()
    fd.append('title', form.title)
    if (form.description) fd.append('description', form.description)
    fd.append('category', form.category)
    fd.append('starts_at', form.starts_at)
    if (form.ends_at) fd.append('ends_at', form.ends_at)
    if (form.location) fd.append('location', form.location)
    fd.append('target_type', form.target_type)
    if (form.target_type !== 'all') fd.append('target_id', form.target_id)
    if (image) fd.append('image', image)
    // Laravel : envoi multipart en POST + _method=PUT pour la modification.
    if (form.id) fd.append('_method', 'PUT')
    try {
      await api(form.id ? `/admin/events/${form.id}` : '/admin/events', { method: 'POST', body: fd })
      setMode('list'); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove(id: number) {
    if (!confirm('Supprimer cet événement ?')) return
    setError('')
    try { await api(`/admin/events/${id}`, { method: 'DELETE' }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  const upcoming = items.filter((e) => !e.is_past)
  const past = items.filter((e) => e.is_past)

  return (
    <AppLayout title="Événements" subtitle="Cultes, reunions, formations, sorties..."
      actions={mode === 'list' ? <button className="btn btn-primary small" onClick={openNew}>+ Nouvel événement</button> : undefined}>
      {error && <div className="alert alert-error">{error}</div>}

      {participants && (
        <div className="modal-overlay" onClick={() => setParticipants(null)}>
          <div className="modal-box" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head">
              <h3>Participants , {participants.title}</h3>
              <button className="btn-link" onClick={() => setParticipants(null)}>Fermer</button>
            </div>
            <h4 className="section-label">Presents ({participants.going.length})</h4>
            <div className="participant-list">
              {participants.going.map((p) => (
                <span key={p.user_id} className="participant-chip">{p.name}{p.volunteer ? ' 🙋' : ''}</span>
              ))}
              {participants.going.length === 0 && <p className="helper">Aucun inscrit pour l'instant.</p>}
            </div>
            {participants.volunteers.length > 0 && (
              <>
                <h4 className="section-label" style={{ marginTop: '1rem' }}>Volontaires ({participants.volunteers.length})</h4>
                <div className="participant-list">
                  {participants.volunteers.map((p) => <span key={p.user_id} className="participant-chip vol">{p.name}</span>)}
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {mode === 'form' ? (
        <section className="panel" style={{ maxWidth: 660 }}>
          <div className="panel-head">
            <h3>{form.id ? "Modifier l'événement" : 'Nouvel événement'}</h3>
            <button className="btn-link" onClick={() => setMode('list')}>Annuler</button>
          </div>
          <div className="field">
            <label>Titre</label>
            <input className="input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Ex. Culte de la moisson" />
          </div>
          <div className="field-row">
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Catégorie</label>
              <select className="select" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value as EventCategory })}>
                {CATEGORIES.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Lieu</label>
              <input className="input" value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} placeholder="Ex. Temple principal" />
            </div>
          </div>
          <div className="field-row">
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Debut</label>
              <input className="input" type="datetime-local" value={form.starts_at} onChange={(e) => setForm({ ...form, starts_at: e.target.value })} />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Fin (optionnel)</label>
              <input className="input" type="datetime-local" value={form.ends_at} onChange={(e) => setForm({ ...form, ends_at: e.target.value })} />
            </div>
          </div>
          <div className="field">
            <label>Description (optionnel)</label>
            <textarea className="input" rows={3} value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} />
          </div>
          <div className="field">
            <label>Image (optionnel)</label>
            <div className="image-picker">
              <button type="button" className="image-drop" onClick={() => fileRef.current?.click()}>
                {imagePreview
                  ? <img src={imagePreview} alt="" />
                  : existingImage ? <img src={existingImage} alt="" /> : '🖼️'}
              </button>
              <input ref={fileRef} type="file" accept="image/*" hidden onChange={onPickImage} />
              {image
                ? <button className="btn-link" onClick={clearImage}>Annuler l'image</button>
                : existingImage && <span className="helper">Image actuelle , choisir un fichier pour la remplacer.</span>}
            </div>
          </div>
          <TargetField targetType={form.target_type} targetId={form.target_id}
            onType={(t) => setForm({ ...form, target_type: t, target_id: '' })}
            onId={(id) => setForm({ ...form, target_id: id })}
            tribes={tribes} departments={departments} />
          <button className="btn btn-primary mt" disabled={busy || !form.title.trim() || !form.starts_at || (form.target_type !== 'all' && !form.target_id)} onClick={save}>
            {busy ? <span className="spinner" /> : form.id ? 'Enregistrer' : "Créer l'événement"}
          </button>
        </section>
      ) : loading ? (
        <div className="event-grid">
          <SkeletonCard /><SkeletonCard /><SkeletonCard />
        </div>
      ) : (
        <>
          <h3 className="section-label">A venir</h3>
          <div className="event-grid">
            {upcoming.map((e) => <EventCard key={e.id} e={e} onEdit={openEdit} onDelete={remove} />)}
            {upcoming.length === 0 && <p className="helper">Aucun événement à venir.</p>}
          </div>
          {past.length > 0 && (
            <>
              <h3 className="section-label" style={{ marginTop: '1.6rem' }}>Passes</h3>
              <div className="event-grid">
                {past.map((e) => <EventCard key={e.id} e={e} onEdit={openEdit} onDelete={remove} past />)}
              </div>
            </>
          )}
        </>
      )}
    </AppLayout>
  )

  function EventCard({ e, onEdit, onDelete, past }: {
    e: EventAdminItem; onEdit: (e: EventAdminItem) => void; onDelete: (id: number) => void; past?: boolean
  }) {
    return (
      <article className={`event-card evt-border-${e.category} ${past ? 'is-past' : ''}`}>
        <div className="event-date-chip">
          <span className="event-day">{new Date(e.starts_at).toLocaleDateString('fr-CA', { day: '2-digit' })}</span>
          <span className="event-month">{new Date(e.starts_at).toLocaleDateString('fr-CA', { month: 'short' })}</span>
        </div>
        <div className="event-body">
          <div className="event-top">
            <span className={`cat-badge evt-${e.category}`}>{CAT_LABEL[e.category]}</span>
            <div className="event-actions">
              <button className="btn-link" onClick={() => onEdit(e)}>Modifier</button>
              <button className="org-del" onClick={() => onDelete(e.id)} aria-label="Supprimer">×</button>
            </div>
          </div>
          <h4 className="event-title">{e.title}</h4>
          <p className="event-meta">🕒 {fmtDate(e.starts_at)}{e.location ? ` · 📍 ${e.location}` : ''}</p>
          <p className="event-meta-soft">{e.target}</p>
          {e.image_url && <img className="event-image" style={{ marginTop: '0.6rem', marginBottom: 0 }} src={e.image_url} alt={e.title} loading="lazy" />}
          <button className="event-participants-btn" onClick={() => openParticipants(e.id)}>
            👥 {e.going_count} inscrit(s){e.volunteer_count > 0 ? ` · ${e.volunteer_count} volontaire(s)` : ''}
          </button>
        </div>
      </article>
    )
  }
}
