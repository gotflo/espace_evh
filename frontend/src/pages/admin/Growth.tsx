import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, ApiError, peek } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import { SkeletonCard } from '../../components/Skeleton'
import { Icon } from '../../components/Icon'
import { usePulse } from '../../pulse'
import type { Assignment } from '../../types'
import { de } from '../../utils/french'
import { useYouTubePreview } from '../../utils/youtubePreview'

const fmt = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }) : '')
const initial = (name: string) => name.trim()[0]?.toUpperCase() ?? '?'
const duration = (s: number) => (s >= 60 ? `${Math.floor(s / 60)} min${s % 60 ? ` ${s % 60} s` : ''}` : `${s} s`)

// ---------------------------------------------------------------- Devoirs

function AssignmentEditor({ item, maxMb, onClose, onSaved }: { item: Assignment | null; maxMb: number; onClose: () => void; onSaved: () => void }) {
  const [title, setTitle] = useState(item?.title ?? '')
  const [speaker, setSpeaker] = useState(item?.speaker ?? '')
  const [type, setType] = useState<'video' | 'audio'>(item?.media_type ?? 'video')
  const [url, setUrl] = useState(item?.media_url ?? '')
  const [file, setFile] = useState<File | null>(null)
  const [keepAudio, setKeepAudio] = useState(!!item?.has_audio_file)
  const [instructions, setInstructions] = useState(item?.instructions ?? '')
  const [notify, setNotify] = useState(true)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)
  const { preview } = useYouTubePreview(type === 'video' ? url : '', (p) => {
    setTitle((t) => t || p.title || '')
    setSpeaker((s) => s || p.author || '')
  })

  const tooBig = !!file && file.size > maxMb * 1024 * 1024
  const hasMedia = type === 'video' ? !!url.trim() : !!file || keepAudio || !!url.trim()

  async function save() {
    setBusy(true); setErrors({})
    const fd = new FormData()
    fd.append('title', title.trim())
    fd.append('speaker', speaker.trim())
    fd.append('media_type', type)
    fd.append('url', url.trim())
    fd.append('instructions', instructions.trim())
    if (type === 'audio' && file) fd.append('audio', file)
    if (type === 'audio' && item?.has_audio_file && !keepAudio && !file) fd.append('remove_audio', '1')
    if (!item) fd.append('notify', notify ? '1' : '0')
    try {
      await api(item ? `/admin/assignments/${item.id}` : '/admin/assignments', { method: 'POST', body: fd })
      onSaved(); onClose()
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.errors)
    } finally { setBusy(false) }
  }
  const err = (k: string) => errors[k]?.[0] && <p className="helper editor-error">{errors[k][0]}</p>

  return (
    <div className="modal-overlay" onMouseDown={onClose}>
      <div className="modal-box gr-modal" role="dialog" aria-modal="true" aria-label={item ? 'Modifier le devoir' : 'Publier un devoir'} onMouseDown={(e) => e.stopPropagation()}>
        <div className="panel-head">
          <h3>{item ? 'Modifier le devoir' : 'Publier un devoir'}</h3>
          <button className="btn-link" onClick={onClose}>Fermer</button>
        </div>
        <div className="seg" role="tablist" aria-label="Type de devoir">
          <button type="button" role="tab" aria-selected={type === 'video'} className={type === 'video' ? 'on' : ''} onClick={() => setType('video')}>Vidéo</button>
          <button type="button" role="tab" aria-selected={type === 'audio'} className={type === 'audio' ? 'on' : ''} onClick={() => setType('audio')}>Audio</button>
        </div>

        {type === 'video' ? (
          <div className="field">
            <label htmlFor="as-url">Lien de la vidéo</label>
            <input id="as-url" className="input" inputMode="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://www.youtube.com/watch?v=…" />
            <p className="helper">YouTube de préférence : la vidéo se lit dans l'application. Le titre et l'auteur se remplissent tout seuls.</p>
            {err('url')}
          </div>
        ) : (
          <div className="field">
            <label>Fichier audio</label>
            <div className="gr-file">
              <button type="button" className="btn btn-ghost small" onClick={() => fileRef.current?.click()}><Icon name="music" size={15} /> {file || keepAudio ? 'Remplacer le fichier' : 'Choisir un fichier'}</button>
              <span className="gr-file-name">{file ? file.name : keepAudio ? 'Fichier actuel conservé' : `MP3, M4A, WAV ou OGG · ${maxMb} Mo max.`}</span>
              {(file || keepAudio) && <button type="button" className="btn-link" onClick={() => { setFile(null); setKeepAudio(false); if (fileRef.current) fileRef.current.value = '' }}>Retirer</button>}
              <input ref={fileRef} type="file" accept="audio/*" hidden onChange={(e) => { setFile(e.target.files?.[0] ?? null); setKeepAudio(false) }} />
            </div>
            {tooBig && <p className="helper editor-error">Ce fichier dépasse {maxMb} Mo.</p>}
            {err('audio')}
            <label htmlFor="as-audio-url" className="mt">…ou lien de l'audio <span className="helper" style={{ display: 'inline' }}>(si pas de fichier)</span></label>
            <input id="as-audio-url" className="input" inputMode="url" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://…" />
            {err('url')}
          </div>
        )}

        {preview && (
          <div className="gr-yt-preview">
            <img src={preview.thumbnail} alt="" />
            <span>Vidéo trouvée{preview.title ? ` : ${preview.title}` : ''}</span>
          </div>
        )}

        <div className="field">
          <label htmlFor="as-title">Titre</label>
          <input id="as-title" className="input" maxLength={150} value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Ex. La foi qui agit" />
          {err('title')}
        </div>
        <div className="field">
          <label htmlFor="as-speaker">Orateur ou auteur <span className="helper" style={{ display: 'inline' }}>(facultatif)</span></label>
          <input id="as-speaker" className="input" maxLength={120} value={speaker} onChange={(e) => setSpeaker(e.target.value)} placeholder="Ex. Pst Abraham Andebi" />
        </div>
        <div className="field">
          <label htmlFor="as-instr">Consignes <span className="helper" style={{ display: 'inline' }}>(facultatif)</span></label>
          <textarea id="as-instr" className="input" rows={3} maxLength={3000} value={instructions} onChange={(e) => setInstructions(e.target.value)}
            placeholder="Ce sur quoi les membres doivent porter attention…" />
        </div>
        <p className="helper">Chaque membre écrira son <b>résumé</b> et ses <b>actions et résolutions</b>, avec le rappel de prier après le devoir.</p>
        {!item && (
          <label className="switch-line mt">
            <input type="checkbox" checked={notify} onChange={(e) => setNotify(e.target.checked)} />
            <span>Prévenir tous les membres (notification) ; une relance part 3 jours après à ceux qui ne l'ont pas fait.</span>
          </label>
        )}
        <button className="btn btn-primary mt" disabled={busy || title.trim().length < 3 || !hasMedia || tooBig} onClick={save}>
          {busy ? <span className="spinner" /> : item ? 'Enregistrer' : 'Publier le devoir'}
        </button>
      </div>
    </div>
  )
}

/** Suivi de lecture d'un membre (memes indicateurs que les exercices video). */
interface Tracked { percent: number | null; media_completed: boolean; seek_count: number; skipped_seconds: number; max_rate: number | null; status: 'todo' | 'in_progress' | 'done' }
interface ResponsesData {
  assignment: Assignment & { trackable: boolean }
  responses: ({ user_id: number; name: string; tribe: string | null; photo_url: string | null; summary: string; actions: string; submitted_at: string | null } & Tracked)[]
  missing: ({ user_id: number; name: string; tribe: string | null; photo_url: string | null } & Tracked)[]
}

/** Lecture : pourcentage, avances rapides, vitesse. */
function TrackLine({ t, audio }: { t: Tracked; audio: boolean }) {
  if (t.percent === null) return null
  return (
    <div className="gr-track">
      <span className="watch-bar" aria-hidden><span style={{ width: `${t.percent}%` }} className={t.media_completed ? 'done' : ''} /></span>
      <span>{t.media_completed ? (audio ? 'Écouté en entier' : 'Regardée en entier') : `${audio ? 'Écouté' : 'Regardée'} à ${t.percent} %`}</span>
      {t.seek_count > 0 && <span className="gr-flag"><Icon name="forward" size={13} /> avancé {t.seek_count} fois ({duration(t.skipped_seconds)} sautées){t.media_completed ? ', puis tout lu' : ''}</span>}
      {t.max_rate && t.max_rate > 1 ? <span>vitesse ×{t.max_rate}</span> : null}
    </div>
  )
}

function Responses({ id, onClose }: { id: number; onClose: () => void }) {
  const [data, setData] = useState<ResponsesData | null>(null)
  const [view, setView] = useState<'done' | 'missing'>('done')
  useEffect(() => { api<ResponsesData>(`/admin/assignments/${id}/responses`).then(setData).catch(() => onClose()) }, [id, onClose])

  return (
    <div className="modal-overlay" onMouseDown={onClose}>
      <div className="modal-box gr-modal" role="dialog" aria-modal="true" aria-label="Réponses au devoir" onMouseDown={(e) => e.stopPropagation()}>
        <div className="panel-head">
          <h3>{data?.assignment.title ?? 'Réponses'}</h3>
          <button className="btn-link" onClick={onClose}>Fermer</button>
        </div>
        {!data ? <span className="spinner" /> : (
          <>
            <div className="seg">
              <button className={view === 'done' ? 'on' : ''} onClick={() => setView('done')}>Résumés ({data.responses.length})</button>
              <button className={view === 'missing' ? 'on' : ''} onClick={() => setView('missing')}>Pas encore ({data.missing.length})</button>
            </div>
            {view === 'done' ? (
              data.responses.length === 0 ? <p className="helper">Personne n'a encore répondu.</p> : data.responses.map((r) => (
                <article key={r.user_id} className="gr-response">
                  <div className="gr-response-head">
                    {r.photo_url ? <img className="mini-avatar" src={r.photo_url} alt="" /> : <span className="mini-avatar">{initial(r.name)}</span>}
                    <div className="gr-member-text">
                      <Link to={`/admin/membres/${r.user_id}`}>{r.name}</Link>
                      <small>{r.tribe ? `Tribu ${r.tribe} · ` : ''}{fmt(r.submitted_at)}</small>
                    </div>
                    <span className={`gr-pill ${r.status === 'done' ? 'ok' : ''}`}>{r.status === 'done' ? 'Fait' : data.assignment.media_type === 'audio' ? 'Écoute à terminer' : 'Vidéo à terminer'}</span>
                  </div>
                  <TrackLine t={r} audio={data.assignment.media_type === 'audio'} />
                  <div className="gr-block"><span>Résumé</span><p>{r.summary}</p></div>
                  <div className="gr-block"><span>Actions et résolutions</span><p>{r.actions}</p></div>
                </article>
              ))
            ) : (
              <ul className="gr-member-list">
                {data.missing.map((m) => (
                  <li key={m.user_id}>
                    <Link className="gr-member" to={`/admin/membres/${m.user_id}`}>
                      <span className="mini-avatar">{initial(m.name)}</span>
                      <span className="gr-member-text">
                        <strong>{m.name}</strong>
                        <small>{m.tribe ? `Tribu ${m.tribe}` : 'Sans tribu'}{m.status === 'in_progress' && m.percent != null ? ` · commencé (${m.percent} %)` : ''}</small>
                      </span>
                    </Link>
                  </li>
                ))}
                {data.missing.length === 0 && <p className="helper">Tout le monde a fait ce devoir.</p>}
              </ul>
            )}
          </>
        )}
      </div>
    </div>
  )
}

type AssignmentsPayload = { assignments: Assignment[]; can_manage: boolean; can_delete?: boolean; can_track?: boolean; audio_max_mb: number }

function AssignmentsTab() {
  // Onglet deja ouvert : affiche tout de suite (derniere lecture), puis mis a jour en silence.
  const [known] = useState(() => peek<AssignmentsPayload>('/admin/assignments'))
  const [items, setItems] = useState<Assignment[] | null>(known?.assignments ?? null)
  const [canManage, setCanManage] = useState(known?.can_manage ?? false)
  const [canDelete, setCanDelete] = useState(!!known?.can_delete)
  const [canTrack, setCanTrack] = useState(known?.can_track !== false)
  const [maxMb, setMaxMb] = useState(known?.audio_max_mb ?? 40)
  const [editing, setEditing] = useState<Assignment | null | 'new'>(null)
  const [viewing, setViewing] = useState<number | null>(null)
  const load = useCallback(() => {
    api<AssignmentsPayload>('/admin/assignments')
      .then((r) => { setItems(r.assignments); setCanManage(r.can_manage); setCanDelete(!!r.can_delete); setCanTrack(r.can_track !== false); setMaxMb(r.audio_max_mb) })
      .catch(() => setItems((p) => p ?? []))
  }, [])
  useEffect(() => { load() }, [load])
  usePulse('assignments', load)
  const closeViewing = useCallback(() => setViewing(null), [])

  async function remove(a: Assignment) {
    if (!confirm(`Retirer « ${a.title} » ? Les membres ne le verront plus ; leurs réponses restent dans leur historique.`)) return
    try { await api(`/admin/assignments/${a.id}`, { method: 'DELETE' }); load() } catch { /* toast */ }
  }

  if (!items) return <SkeletonCard />
  return (
    <>
      {canManage && (
        <button className="btn btn-primary small gr-new" onClick={() => setEditing('new')}><Icon name="plus" size={16} /> Publier un devoir</button>
      )}
      <ul className="gr-list mt">
        {items.map((a) => {
          // « Fait » = resume envoye ET video ou audio lu en entier (memes regles que les exercices).
          const pct = a.members_count ? Math.round(((a.done_count ?? 0) / a.members_count) * 100) : 0
          return (
            <li key={a.id} className="panel gr-card">
              <span className="gr-thumb">{a.thumbnail ? <img src={a.thumbnail} alt="" /> : <Icon name={a.media_type === 'audio' ? 'headphones' : 'play'} size={28} />}</span>
              <div className="gr-main">
                <span className={`ds-type ds-type-${a.media_type}`}>{a.media_type === 'audio' ? 'Audio' : 'Vidéo'}</span>
                <h3>{a.title}</h3>
                <span className="gr-meta">{a.speaker ? `${a.speaker} · ` : ''}publié le {fmt(a.published_at)}</span>
                {canTrack && <span className="gr-progress">
                  <span className="bar-track"><span className="bar-fill" style={{ width: `${pct}%`, display: 'block' }} /></span>
                  {a.done_count}/{a.members_count} fait{(a.done_count ?? 0) > 1 ? 's' : ''}{(a.responses_count ?? 0) > (a.done_count ?? 0) ? ` · ${(a.responses_count ?? 0) - (a.done_count ?? 0)} en cours` : ''}
                </span>}
                <div className="gr-actions">
                  {canTrack && <button className="btn btn-ghost small" onClick={() => setViewing(a.id)}><Icon name="eye" size={15} /> Réponses</button>}
                  {canManage && <button className="icon-btn" aria-label={`Modifier ${a.title}`} onClick={() => setEditing(a)}><Icon name="edit" size={17} /></button>}
                  {canDelete && <button className="icon-btn danger" aria-label={`Retirer ${a.title}`} onClick={() => remove(a)}><Icon name="trash" size={17} /></button>}
                </div>
              </div>
            </li>
          )
        })}
        {items.length === 0 && (
          <li className="panel empty-state"><span><Icon name="devoir" size={32} /></span><h3>Aucun devoir publié</h3>
            <p>{canManage ? 'Publiez une vidéo ou un audio : chaque membre écrira son résumé et ses résolutions.' : 'Le pasteur n’a pas encore publié de devoir.'}</p></li>
        )}
      </ul>
      {editing && <AssignmentEditor item={editing === 'new' ? null : editing} maxMb={maxMb} onClose={() => setEditing(null)} onSaved={load} />}
      {viewing && <Responses id={viewing} onClose={closeViewing} />}
    </>
  )
}

// ---------------------------------------------------------------- Suivi des RM

interface RmMember { user_id: number; name: string; tribe: string | null; photo_url: string | null; taken: boolean; count: number; renewed: number; previous: { count: number; achieved: number; reviewed: boolean } | null }
interface RmData { period: string; period_label: string; members: RmMember[]; taken: number; total: number }

function ResolutionsTab() {
  const now = new Date()
  const thisMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const [period, setPeriod] = useState(thisMonth)
  const [filter, setFilter] = useState<'all' | 'taken' | 'missing'>('all')
  const [data, setData] = useState<RmData | null>(() => peek<RmData>(`/admin/resolutions?period=${thisMonth}`) ?? null)
  const load = useCallback(() => { api<RmData>(`/admin/resolutions?period=${period}`).then(setData).catch(() => setData(null)) }, [period])
  useEffect(() => { load() }, [load])
  usePulse('resolutions', load)

  if (!data) return <SkeletonCard />
  const list = data.members.filter((m) => filter === 'all' || (filter === 'taken' ? m.taken : !m.taken))
  const pct = data.total ? Math.round((data.taken / data.total) * 100) : 0

  return (
    <section className="panel">
      <div className="gr-summary">
        <span className="gr-big">{pct} %</span>
        <span className="helper" style={{ margin: 0, flex: 1 }}>{data.taken} membre{data.taken > 1 ? 's' : ''} sur {data.total} {data.taken > 1 ? 'ont' : 'a'} pris {data.taken > 1 ? 'leurs' : 'ses'} résolutions {de(data.period_label)}.</span>
        <input className="input" type="month" aria-label="Mois" max={thisMonth} value={period} onChange={(e) => e.target.value && setPeriod(e.target.value)} style={{ width: 'auto' }} />
      </div>
      <div className="seg">
        <button className={filter === 'all' ? 'on' : ''} onClick={() => setFilter('all')}>Tous</button>
        <button className={filter === 'taken' ? 'on' : ''} onClick={() => setFilter('taken')}>Prises ({data.taken})</button>
        <button className={filter === 'missing' ? 'on' : ''} onClick={() => setFilter('missing')}>Pas encore ({data.total - data.taken})</button>
      </div>
      <ul className="gr-member-list">
        {list.map((m) => (
          <li key={m.user_id}>
            <Link className="gr-member" to={`/admin/membres/${m.user_id}#resolutions`}>
              {m.photo_url ? <img className="mini-avatar" src={m.photo_url} alt="" /> : <span className="mini-avatar">{initial(m.name)}</span>}
              <span className="gr-member-text">
                <strong>{m.name}</strong>
                <small>
                  {m.tribe ? `Tribu ${m.tribe}` : 'Sans tribu'}
                  {m.taken ? ` · ${m.count} résolution${m.count > 1 ? 's' : ''}${m.renewed ? `, ${m.renewed} renouvelée${m.renewed > 1 ? 's' : ''}` : ''}` : ''}
                  {m.previous?.reviewed ? ` · bilan du mois d'avant : ${m.previous.achieved}/${m.previous.count}` : ''}
                </small>
              </span>
              <span className={`gr-pill ${m.taken ? 'ok' : ''}`}>{m.taken ? 'Prises' : 'Pas encore'}</span>
            </Link>
          </li>
        ))}
        {list.length === 0 && <p className="helper">Aucun membre dans cette liste.</p>}
      </ul>
    </section>
  )
}

// ---------------------------------------------------------------- Suivi du Vertumetre

interface VtMember { user_id: number; name: string; tribe: string | null; photo_url: string | null; filled: boolean }
interface VtData { period: string; period_label: string; members: VtMember[]; filled: number; total: number }

function VertumetreTab() {
  const now = new Date()
  const thisMonth = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`
  const [period, setPeriod] = useState(thisMonth)
  const [filter, setFilter] = useState<'all' | 'filled' | 'missing'>('all')
  const [data, setData] = useState<VtData | null>(null)
  const load = useCallback(() => { api<VtData>(`/admin/vertumetre?period=${period}`).then(setData).catch(() => setData(null)) }, [period])
  useEffect(() => { load() }, [load])

  if (!data) return <SkeletonCard />
  const list = data.members.filter((m) => filter === 'all' || (filter === 'filled' ? m.filled : !m.filled))
  const pct = data.total ? Math.round((data.filled / data.total) * 100) : 0

  return (
    <section className="panel">
      <div className="gr-summary">
        <span className="gr-big">{pct} %</span>
        <span className="helper" style={{ margin: 0, flex: 1 }}>{data.filled} membre{data.filled > 1 ? 's' : ''} sur {data.total} {data.filled > 1 ? 'ont' : 'a'} rempli le Vertumètre {de(data.period_label)}.</span>
        <input className="input" type="month" aria-label="Mois" max={thisMonth} value={period} onChange={(e) => e.target.value && setPeriod(e.target.value)} style={{ width: 'auto' }} />
      </div>
      <div className="seg">
        <button className={filter === 'all' ? 'on' : ''} onClick={() => setFilter('all')}>Tous</button>
        <button className={filter === 'filled' ? 'on' : ''} onClick={() => setFilter('filled')}>Remplis ({data.filled})</button>
        <button className={filter === 'missing' ? 'on' : ''} onClick={() => setFilter('missing')}>Pas encore ({data.total - data.filled})</button>
      </div>
      <ul className="gr-member-list">
        {list.map((m) => (
          <li key={m.user_id}>
            <Link className="gr-member" to={`/admin/membres/${m.user_id}#vertumetre`}>
              {m.photo_url ? <img className="mini-avatar" src={m.photo_url} alt="" /> : <span className="mini-avatar">{initial(m.name)}</span>}
              <span className="gr-member-text"><strong>{m.name}</strong><small>{m.tribe ? `Tribu ${m.tribe}` : 'Sans tribu'}</small></span>
              <span className={`gr-pill ${m.filled ? 'ok' : ''}`}>{m.filled ? 'Rempli' : 'Pas encore'}</span>
            </Link>
          </li>
        ))}
        {list.length === 0 && <p className="helper">Aucun membre dans cette liste.</p>}
      </ul>
    </section>
  )
}

// ---------------------------------------------------------------- Page

/** Devoirs (DS) et Résolutions du mois (RM) : publication (PR, PA) et suivi (pasteurs, patriarches, AP). */
export default function Growth() {
  // Suivi (pasteurs, patriarches, AP) : devoirs, RM et Vertumetre ; publication seule (compte de gestion) : devoirs.
  const { hasPermission, roles, moduleOn } = useAuth()
  const canTrack = hasPermission('spiritual.view') && (hasPermission('members.view_all') || roles.some((r) => r.scope_kind === 'tribe'))
  const canResources = hasPermission('resources.view') && moduleOn('resolutions')
  const [params, setParams] = useSearchParams()
  // Onglets des modules actives seulement (Gestion > Reglages).
  const all: [string, string, boolean][] = [
    ['devoirs', 'Devoirs (DS)', moduleOn('assignments')],
    ['rm', 'Résolutions (RM)', canTrack && moduleOn('resolutions')],
    ['vertumetre', 'Vertumètre', canTrack && moduleOn('vertumetre')],
  ]
  const tabs: [string, string][] = all.filter(([, , on]) => on).map(([k, l]) => [k, l])
  const tab = tabs.some(([k]) => k === params.get('onglet')) ? params.get('onglet')! : (tabs[0]?.[0] ?? 'devoirs')

  return (
    <AppLayout title={canTrack ? 'Suivi du mois' : 'Devoirs (DS)'} subtitle={canTrack ? 'Devoirs, résolutions et Vertumètre des membres' : 'Publier les devoirs des membres'}
      actions={canResources ? <Link className="btn btn-ghost small" to="/admin/ressources"><Icon name="link" size={15} /> Ressources</Link> : undefined}>
      {tabs.length > 1 && <div className="seg gr-tabs" role="tablist">
        {tabs.map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} className={tab === k ? 'on' : ''} onClick={() => setParams({ onglet: k }, { replace: true })}>{l}</button>
        ))}
      </div>}
      {tab === 'devoirs' && <AssignmentsTab />}
      {tab === 'rm' && <ResolutionsTab />}
      {tab === 'vertumetre' && <VertumetreTab />}
    </AppLayout>
  )
}
