import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, ApiError, peek } from '../api/client'
import { usePulse } from '../pulse'
import { Icon } from './Icon'
import { SkeletonCard } from './Skeleton'
import { toast } from '../toast'
import { confirmDiscard, useDirty } from '../utils/dirty'
import type { ResolutionResource, ResolutionResourceGroup } from '../types'
import { isYouTubeLink, useYouTubePreview } from '../utils/youtubePreview'import { ModalLayer } from './ModalLayer'


interface Can { edit: boolean; publish: boolean; delete: boolean }
type Status = 'draft' | 'published'

function ResourceEditor({ item, themes, can, onClose, onSaved }: {
  item: ResolutionResource | null
  themes: Record<string, string>
  can: Can
  onClose: () => void
  onSaved: () => void
}) {
  const [theme, setTheme] = useState(item?.theme ?? Object.keys(themes)[0] ?? 'meditation')
  const [url, setUrl] = useState(item?.url ?? '')
  const [title, setTitle] = useState(item?.title ?? '')
  const [author, setAuthor] = useState(item?.author ?? '')
  const [note, setNote] = useState(item?.note ?? '')
  const [status, setStatus] = useState<Status>(item?.status ?? (can.publish ? 'published' : 'draft'))
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  // Lien YouTube reconnu automatiquement : miniature, et titre / auteur remplis s'ils sont vides.
  const { preview, status: ytStatus } = useYouTubePreview(url, (p) => {
    setTitle((t) => t || p.title || '')
    setAuthor((a) => a || p.author || '')
  })
  // Sans la permission « publier » : brouillons seulement, et une ressource publiee ne se modifie pas.
  const lockedPublished = !can.publish && item?.status === 'published'
  // Clic sur le fond : confirmation si quelque chose a ete saisi ou modifie.
  const changes = useDirty({ theme, url, title, author, note, status })
  const requestClose = () => { if (confirmDiscard(changes.dirty)) onClose() }

  async function save() {
    setBusy(true); setErrors({})
    const body = { theme, url: url.trim(), title: title.trim(), author: author.trim(), note: note.trim() || null, status }
    try {
      if (item) await api(`/admin/resolution-resources/${item.id}`, { method: 'PUT', body, toast: false })
      else await api('/admin/resolution-resources', { method: 'POST', body, toast: false })
      toast.success(status === 'published' ? 'Ressource publiée.' : 'Brouillon enregistré.')
      onSaved(); onClose()
    } catch (err) {
      if (err instanceof ApiError) setErrors(Object.keys(err.errors).length ? err.errors : { url: [err.firstMessage] })
    } finally { setBusy(false) }
  }
  const err = (k: string) => errors[k]?.[0] && <p className="helper editor-error">{errors[k][0]}</p>

  return (
    <ModalLayer className="modal-overlay" onMouseDown={requestClose}>
      <div className="modal-box modal-wide" role="dialog" aria-modal="true" aria-label={item ? 'Modifier la ressource' : 'Nouvelle ressource'} onMouseDown={(e) => e.stopPropagation()}>
        <div className="panel-head">
          <h3>{item ? 'Modifier la ressource' : 'Nouvelle ressource'}</h3>
          <button className="btn-link" onClick={onClose}>Fermer</button>
        </div>
        {lockedPublished && <div className="alert alert-info">Cette ressource est publiée : la modifier demande la permission « Publier ».</div>}
        <div className="field">
          <label id="rr-theme">Thème</label>
          <div className="seg" role="radiogroup" aria-labelledby="rr-theme">
            {Object.entries(themes).map(([k, l]) => (
              <button key={k} type="button" role="radio" aria-checked={theme === k} className={theme === k ? 'on' : ''} onClick={() => setTheme(k)}>{l}</button>
            ))}
          </div>
        </div>
        <div className="field">
          <label htmlFor="rr-url">Lien (YouTube ou autre site)</label>
          <input id="rr-url" className="input" inputMode="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://www.youtube.com/watch?v=… ou https://…" />
          {err('url')}
          {ytStatus === 'loading' && <p className="helper">Recherche de la vidéo…</p>}
          {ytStatus === 'invalid' && <p className="helper editor-error">Lien YouTube non reconnu : copiez l’adresse depuis le bouton « Partager » de la vidéo.</p>}
          {url.trim() && !isYouTubeLink(url) && <p className="helper">Lien web : il s’ouvrira dans le navigateur.</p>}
        </div>
        {preview && (
          <div className="gr-yt-preview">
            <img src={preview.thumbnail} alt="" />
            <span>
              Vidéo YouTube reconnue{preview.title ? ` : ${preview.title}` : ''}.
              {preview.embeddable === false ? ' Son auteur interdit la lecture hors de YouTube : elle s’ouvrira sur YouTube.' : ' Elle se lira dans l’application.'}
            </span>
          </div>
        )}
        <div className="field-row">
        <div className="field">
          <label htmlFor="rr-title">Titre</label>
          <input id="rr-title" className="input" maxLength={150} value={title} onChange={(e) => setTitle(e.target.value)} />
          {err('title')}
        </div>
        <div className="field">
          <label htmlFor="rr-author">Nom de l’auteur</label>
          <input id="rr-author" className="input" maxLength={120} value={author} onChange={(e) => setAuthor(e.target.value)} placeholder="Ex. Pst Abraham Andebi" />
          {err('author')}
        </div>
        </div>
        <div className="field">
          <label htmlFor="rr-note">Note <span className="helper" style={{ display: 'inline' }}>(facultatif)</span></label>
          <input id="rr-note" className="input" maxLength={300} value={note} onChange={(e) => setNote(e.target.value)} placeholder="Ex. à écouter avant le jeûne" />
        </div>
        <div className="field">
          <label id="rr-status">Visibilité</label>
          <div className="seg" role="radiogroup" aria-labelledby="rr-status">
            <button type="button" role="radio" aria-checked={status === 'draft'} className={status === 'draft' ? 'on' : ''} disabled={lockedPublished} onClick={() => setStatus('draft')}>Brouillon</button>
            <button type="button" role="radio" aria-checked={status === 'published'} className={status === 'published' ? 'on' : ''} disabled={!can.publish} onClick={() => setStatus('published')}>Publiée</button>
          </div>
          {!can.publish && <p className="helper">Un responsable autorisé publiera la ressource.</p>}
          {err('status')}
        </div>
        <button className="btn btn-primary" disabled={busy || lockedPublished || !url.trim() || title.trim().length < 2 || author.trim().length < 2 || ytStatus === 'invalid'} onClick={save}>
          {busy ? <span className="spinner" /> : status === 'published' ? 'Publier' : 'Enregistrer'}
        </button>
      </div>
    </ModalLayer>
  )
}

/**
 * Ressources et liens video proposes aux membres sous leurs resolutions (meditation, priere, jeune, culte) :
 * ajout, modification, publication / depublication et suppression selon les permissions.
 */
type ResourcesPayload = { groups: ResolutionResourceGroup[]; themes: Record<string, string>; can: Can }

export function ResourcesManager() {
  // Onglet deja ouvert : affiche tout de suite (derniere lecture), puis mis a jour en silence.
  const [known] = useState(() => peek<ResourcesPayload>('/admin/resolution-resources'))
  const [groups, setGroups] = useState<ResolutionResourceGroup[] | null>(known?.groups ?? null)
  const [themes, setThemes] = useState<Record<string, string>>(known?.themes ?? {})
  const [can, setCan] = useState<Can>(known?.can ?? { edit: false, publish: false, delete: false })
  const [filter, setFilter] = useState<'all' | Status>('all')
  const [editing, setEditing] = useState<ResolutionResource | null | 'new'>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(() => {
    api<ResourcesPayload>('/admin/resolution-resources')
      .then((r) => { setGroups(r.groups); setThemes(r.themes); setCan(r.can); setFailed(false) })
      .catch(() => { setGroups((g) => g ?? []); setFailed(true) })
  }, [])
  useEffect(() => { load() }, [load])
  usePulse('resolutions', load)

  const counts = useMemo(() => {
    const all = (groups ?? []).flatMap((g) => g.items)
    return { all: all.length, published: all.filter((r) => r.status !== 'draft').length, draft: all.filter((r) => r.status === 'draft').length }
  }, [groups])

  async function remove(r: ResolutionResource) {
    if (!confirm(`Supprimer « ${r.title} » ? Les membres ne la verront plus.`)) return
    try { await api(`/admin/resolution-resources/${r.id}`, { method: 'DELETE' }); load() } catch { /* message affiche par le client */ }
  }

  async function togglePublish(r: ResolutionResource) {
    const status: Status = r.status === 'draft' ? 'published' : 'draft'
    try {
      await api(`/admin/resolution-resources/${r.id}`, {
        method: 'PUT', toast: false,
        body: { theme: r.theme, url: r.url, title: r.title, author: r.author, note: r.note, status },
      })
      toast.success(status === 'published' ? 'Ressource publiée.' : 'Ressource dépubliée : les membres ne la voient plus.')
      load()
    } catch (err) {
      toast.error(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    }
  }

  if (!groups) return <SkeletonCard />
  return (
    <section className="panel">
      <div className="panel-head">
        <h3>Ressources et liens vidéo</h3>
        {can.edit && <button className="btn btn-primary small" onClick={() => setEditing('new')}><Icon name="plus" size={16} /> Ajouter</button>}
      </div>
      {failed && <div className="alert alert-error">Les ressources n’ont pas pu être chargées.</div>}
      <p className="helper res-intro">Proposées aux membres sous leurs résolutions. Collez un lien YouTube : la vidéo est reconnue et se lit dans l’application.</p>
      <div className="seg res-filter" role="tablist" aria-label="Filtrer les ressources">
        {([['all', `Toutes (${counts.all})`], ['published', `Publiées (${counts.published})`], ['draft', `Brouillons (${counts.draft})`]] as const).map(([k, l]) => (
          <button key={k} role="tab" aria-selected={filter === k} className={filter === k ? 'on' : ''} onClick={() => setFilter(k)}>{l}</button>
        ))}
      </div>
      {groups.map((g) => {
        const items = g.items.filter((r) => filter === 'all' || (filter === 'draft' ? r.status === 'draft' : r.status !== 'draft'))
        return (
          <div key={g.theme} className="gr-res-group">
            <h4>{g.label}</h4>
            {items.length === 0 && <p className="helper">Aucune ressource.</p>}
            <ul className="resource-list">
              {items.map((r) => (
                <li key={r.id} className={`gr-res-row ${r.status === 'draft' ? 'is-draft' : ''}`}>
                  <a className="resource-card" href={r.url} target="_blank" rel="noopener noreferrer">
                    <span className={`resource-thumb ${r.youtube_id ? '' : 'link'}`}>
                      {r.youtube_id ? <><img src={`https://i.ytimg.com/vi/${r.youtube_id}/mqdefault.jpg`} alt="" loading="lazy" /><Icon name="play" size={20} /></> : <Icon name="link" size={20} />}
                    </span>
                    <span className="resource-text">
                      <strong>{r.title}</strong>
                      <small>par {r.author}</small>
                      <span className="res-tags">
                        <span className={`res-tag ${r.youtube_id ? 'yt' : ''}`}>{r.youtube_id ? 'Vidéo YouTube' : 'Lien'}</span>
                        {r.status === 'draft' && <span className="res-tag draft">Brouillon</span>}
                      </span>
                      {r.note && <em>{r.note}</em>}
                    </span>
                  </a>
                  <span className="gr-actions">
                    {can.publish && (
                      <button className="icon-btn" aria-label={r.status === 'draft' ? `Publier ${r.title}` : `Dépublier ${r.title}`} title={r.status === 'draft' ? 'Publier' : 'Dépublier'} onClick={() => togglePublish(r)}>
                        <Icon name={r.status === 'draft' ? 'eye' : 'lock'} size={17} />
                      </button>
                    )}
                    {can.edit && <button className="icon-btn" aria-label={`Modifier ${r.title}`} onClick={() => setEditing(r)}><Icon name="edit" size={17} /></button>}
                    {can.delete && <button className="icon-btn danger" aria-label={`Supprimer ${r.title}`} onClick={() => remove(r)}><Icon name="trash" size={17} /></button>}
                  </span>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
      {editing && <ResourceEditor item={editing === 'new' ? null : editing} themes={themes} can={can} onClose={() => setEditing(null)} onSaved={load} />}
    </section>
  )
}
