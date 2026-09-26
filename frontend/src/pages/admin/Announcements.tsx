import { useCallback, useEffect, useRef, useState, type ChangeEvent } from 'react'
import { api, ApiError } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { AudiencePicker } from '../../components/AudiencePicker'
import { SkeletonCard } from '../../components/Skeleton'
import type { AnnouncementAdminItem, AudienceScope } from '../../types'

const CATEGORIES = [
  { key: 'info', label: 'Information' },
  { key: 'important', label: 'Important' },
  { key: 'evenement', label: 'Événement' },
]
const CAT_LABEL: Record<string, string> = { info: 'Info', important: 'Important', evenement: 'Événement' }

export default function Announcements() {
  const [items, setItems] = useState<AnnouncementAdminItem[]>([])
  const [loading, setLoading] = useState(true)
  const [mode, setMode] = useState<'list' | 'new'>('list')
  const [error, setError] = useState('')

  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [category, setCategory] = useState('info')
  const [scopes, setScopes] = useState<AudienceScope[]>([])
  const [image, setImage] = useState<File | null>(null)
  const [imagePreview, setImagePreview] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  const load = useCallback(() => {
    setLoading(true)
    api<{ announcements: AnnouncementAdminItem[] }>('/admin/announcements')
      .then((r) => setItems(r.announcements)).catch(() => setItems([]))
      .finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  function onPickImage(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setImage(file)
    setImagePreview(URL.createObjectURL(file))
  }
  function resetForm() {
    setTitle(''); setBody(''); setCategory('info'); setScopes([])
    setImage(null); setImagePreview(null)
  }
  function openNew() { resetForm(); setError(''); setMode('new') }

  async function publish() {
    setError(''); setBusy(true)
    try {
      const fd = new FormData()
      if (title.trim()) fd.append('title', title.trim())
      if (body.trim()) fd.append('body', body.trim())
      if (image) fd.append('image', image)
      fd.append('category', category)
      fd.append('scopes', JSON.stringify(scopes))
      await api('/admin/announcements', { method: 'POST', body: fd })
      resetForm(); setMode('list'); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove(id: number) {
    if (!confirm('Supprimer cette annonce ?')) return
    setError('')
    try { await api(`/admin/announcements/${id}`, { method: 'DELETE' }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  const hasContent = title.trim() !== '' || body.trim() !== '' || image !== null
  const createBtn = <button className="btn btn-primary small" onClick={openNew}>+ Nouvelle annonce</button>

  return (
    <AppLayout title="Annonces" subtitle="Communiquer avec les fidèles" actions={mode === 'list' ? createBtn : undefined}>
      {error && <div className="alert alert-error">{error}</div>}

      {mode === 'new' ? (
        <section className="panel" style={{ maxWidth: 640 }}>
          <div className="panel-head">
            <h3>Nouvelle annonce</h3>
            <button className="btn-link" onClick={() => setMode('list')}>Annuler</button>
          </div>
          <div className="field">
            <label>Titre <span className="helper" style={{ display: 'inline' }}>(optionnel)</span></label>
            <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Ex. Culte special dimanche" />
          </div>
          <div className="field">
            <label>Message <span className="helper" style={{ display: 'inline' }}>(optionnel)</span></label>
            <textarea className="input" rows={4} value={body} onChange={(e) => setBody(e.target.value)} />
          </div>
          <div className="field">
            <label>Image <span className="helper" style={{ display: 'inline' }}>(optionnel , une annonce peut être juste une image)</span></label>
            <div className="image-picker">
              <button type="button" className="image-drop" onClick={() => fileRef.current?.click()}>
                {imagePreview ? <img src={imagePreview} alt="" /> : '🖼️'}
              </button>
              <input ref={fileRef} type="file" accept="image/*" hidden onChange={onPickImage} />
              {image && <button className="btn-link" onClick={() => { setImage(null); setImagePreview(null) }}>Retirer l'image</button>}
            </div>
          </div>
          <div className="field">
            <label>Catégorie</label>
            <select className="select" value={category} onChange={(e) => setCategory(e.target.value)}>
              {CATEGORIES.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
            </select>
          </div>
          <AudiencePicker value={scopes} onChange={setScopes} />
          <button className="btn btn-primary mt" disabled={busy || !hasContent || scopes.length === 0} onClick={publish}>
            {busy ? <span className="spinner" /> : "Publier l'annonce"}
          </button>
        </section>
      ) : loading ? (
        <div className="feed">
          <SkeletonCard /><SkeletonCard /><SkeletonCard />
        </div>
      ) : (
        <div className="feed">
          {items.map((a) => (
            <article key={a.id} className={`feed-item cat-border-${a.category}`}>
              <div className="feed-head">
                <span className={`cat-badge cat-${a.category}`}>{CAT_LABEL[a.category]}</span>
                <span className="feed-date">{a.target} · {a.recipients_count} destinataire(s) · {a.created_at}</span>
                <button className="org-del" onClick={() => remove(a.id)} aria-label="Supprimer">×</button>
              </div>
              {a.title && <h4 className="feed-title">{a.title}</h4>}
              {a.image_url && <img className="feed-image" src={a.image_url} alt={a.title ?? 'Annonce'} loading="lazy" />}
            </article>
          ))}
          {items.length === 0 && <p className="helper">Aucune annonce. Cliquez sur "Nouvelle annonce".</p>}
        </div>
      )}
    </AppLayout>
  )
}
