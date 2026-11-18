import { useRef, useState, type ReactNode } from 'react'
import { api, ApiError } from '../api/client'
import { toast } from '../toast'
import { Icon } from './Icon'
import { RichText } from '../rich/RichText'
import { LazyRichEditor } from '../rich/LazyRichEditor'
import { docText, sameDoc, type RichDoc } from '../rich/doc'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import { timeAgo } from '../notifications'
import type { AssignmentComment, AssignmentFeedbackData } from '../types'
import '../feedback.css'

const COMMENT_MAX = 2000
const HEART = 'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8l1 1.1L12 21l7.8-7.5 1-1.1a5.5 5.5 0 0 0 0-7.8z'
const BUBBLE = 'M21 11.5a8.4 8.4 0 0 1-9 8.4 8.6 8.6 0 0 1-3.8-.9L3 20l1.1-4.6A8.4 8.4 0 1 1 21 11.5z'

function Avatar({ name, photo, size = 32 }: { name: string; photo: string | null; size?: number }) {
  return photo
    ? <img className="mini-avatar fb-avatar" src={photo} alt="" width={size} height={size} style={{ width: size, height: size }} loading="lazy" decoding="async" />
    : <span className="mini-avatar fb-avatar" style={{ width: size, height: size }} aria-hidden>{(name.trim()[0] ?? '?').toUpperCase()}</span>
}

export function Heart({ filled, size = 24 }: { filled: boolean; size?: number }) {
  return (
    <svg width={size} height={size} viewBox="0 0 24 24" aria-hidden focusable="false" fill={filled ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="2" strokeLinejoin="round">
      <path d={HEART} />
    </svg>
  )
}

/** Date courte et relative (« il y a 5 min »), la date complete au survol. */
function When({ iso }: { iso: string | null }) {
  if (!iso) return null
  return <time dateTime={iso} title={new Date(iso).toLocaleString('fr-CA', { dateStyle: 'long', timeStyle: 'short' })}>{timeAgo(iso)}</time>
}

function CommentItem({ c, onEdit, onDelete }: { c: AssignmentComment; onEdit?: () => void; onDelete?: () => void }) {
  return (
    <li className="fb-comment">
      <Avatar name={c.author} photo={c.photo_url} />
      <div className="fb-comment-main">
        <div className="fb-comment-bubble">
          <strong className="fb-comment-author">{c.author}</strong>
          <RichText doc={c.body_doc} text={c.body} />
        </div>
        <div className="fb-comment-meta">
          <When iso={c.at} />
          {c.edited && <span>modifié</span>}
          {onEdit && <button type="button" className="fb-link" onClick={onEdit}>Modifier</button>}
          {onDelete && <button type="button" className="fb-link is-danger" onClick={onDelete}>Supprimer</button>}
        </div>
      </div>
    </li>
  )
}

/**
 * Reponse d'un membre que le pasteur peut aimer d'un double appui (comme une publication), avec le coeur qui
 * apparait. Le contenu reste selectionnable ; un simple appui ne fait rien.
 */
export function LikeableBody({ enabled, liked, onLike, children }: { enabled: boolean; liked: boolean; onLike: () => void; children: ReactNode }) {
  const [burst, setBurst] = useState(0)
  const lastTap = useRef(0)
  const fire = () => {
    setBurst((n) => n + 1)
    if (!liked) onLike()
  }
  if (!enabled) return <>{children}</>
  return (
    <div className="fb-likeable"
      onDoubleClick={(e) => { e.preventDefault(); window.getSelection()?.removeAllRanges(); fire() }}
      onTouchEnd={(e) => {
        if (e.touches.length > 0 || (e.target as Element).closest('a, button')) return
        const now = Date.now()
        if (now - lastTap.current < 300) { e.preventDefault(); fire(); lastTap.current = 0 } else lastTap.current = now
      }}>
      {children}
      {burst > 0 && <span key={burst} className="fb-burst" aria-hidden><Heart filled size={84} /></span>}
    </div>
  )
}

/**
 * Cote pasteur : coeur, commentaires et zone pour commenter, sous la reponse d'un membre.
 * Le coeur repond tout de suite (et revient en arriere si le serveur refuse).
 */
export function PastorFeedback({ responseId, memberFirstName, feedback, onChange, likeRef }: {
  responseId: number
  memberFirstName: string
  feedback: AssignmentFeedbackData
  onChange: (f: AssignmentFeedbackData) => void
  /** Le double appui sur la reponse passe par ici. */
  likeRef?: { current: (() => void) | null }
}) {
  const key = `ds-comment:${responseId}`
  const [open, setOpen] = useState(false)
  const [doc, setDoc] = useState<RichDoc | null>(() => readDraft<RichDoc>(key))
  const [resetKey, setResetKey] = useState(0)
  const [busy, setBusy] = useState(false)
  const [editing, setEditing] = useState<AssignmentComment | null>(null)
  const [editDoc, setEditDoc] = useState<RichDoc | null>(null)
  const [pop, setPop] = useState(0)
  useDraft(key, doc, (v) => !docText(v))
  const text = docText(doc)

  async function toggleLike(next = !feedback.liked) {
    const before = feedback
    setPop((n) => n + (next ? 1 : 0))
    onChange({ ...feedback, liked: next, likes: next ? [...feedback.likes.filter((l) => !l.is_me), { name: 'Vous', photo_url: null, is_me: true }] : feedback.likes.filter((l) => !l.is_me) })
    try {
      const r = await api<{ feedback: AssignmentFeedbackData }>(`/admin/assignment-responses/${responseId}/like`, { method: 'PUT', body: { liked: next }, toast: false })
      onChange(r.feedback)
    } catch (err) {
      onChange(before)
      toast.error(err instanceof ApiError ? err.firstMessage : 'Action impossible. Vérifiez votre réseau.')
    }
  }
  if (likeRef) likeRef.current = () => { if (!feedback.liked) void toggleLike(true) }

  async function publish() {
    if (busy || !text || text.length > COMMENT_MAX) return
    setBusy(true)
    try {
      const r = await api<{ comment: AssignmentComment }>(`/admin/assignment-responses/${responseId}/comments`, { method: 'POST', body: { body_doc: doc } })
      onChange({ ...feedback, comments: [...feedback.comments, r.comment] })
      clearDraft(key)
      setDoc(null)
      setResetKey((n) => n + 1)
      setOpen(false)
    } catch { /* message affiche automatiquement */ } finally { setBusy(false) }
  }

  async function saveEdit() {
    if (!editing || busy || !docText(editDoc) || docText(editDoc).length > COMMENT_MAX) return
    setBusy(true)
    try {
      const r = await api<{ comment: AssignmentComment }>(`/admin/assignment-comments/${editing.id}`, { method: 'PUT', body: { body_doc: editDoc } })
      onChange({ ...feedback, comments: feedback.comments.map((c) => (c.id === r.comment.id ? r.comment : c)) })
      setEditing(null)
    } catch { /* message affiche automatiquement */ } finally { setBusy(false) }
  }

  async function remove(c: AssignmentComment) {
    if (!confirm('Supprimer ce commentaire ? Le membre ne le verra plus.')) return
    try {
      await api(`/admin/assignment-comments/${c.id}`, { method: 'DELETE' })
      onChange({ ...feedback, comments: feedback.comments.filter((x) => x.id !== c.id) })
    } catch { /* message affiche automatiquement */ }
  }

  const likers = feedback.likes.map((l) => (l.is_me ? 'vous' : l.name))
  return (
    <div className="fb">
      <div className="fb-bar">
        <button type="button" className={`fb-action fb-heart${feedback.liked ? ' is-on' : ''}`} aria-pressed={!!feedback.liked}
          aria-label={feedback.liked ? 'Je n’aime plus' : 'J’aime'} title={feedback.liked ? 'Je n’aime plus' : 'J’aime'} onClick={() => void toggleLike()}>
          <span key={pop} className={pop ? 'fb-pop' : undefined}><Heart filled={!!feedback.liked} /></span>
        </button>
        <button type="button" className={`fb-action${open ? ' is-on' : ''}`} aria-label="Commenter" title="Commenter" aria-expanded={open} onClick={() => setOpen((v) => !v)}>
          <Icon path={BUBBLE} size={23} />
        </button>
        <span className="fb-summary">
          {likers.length > 0 && <span>Aimé par <strong>{likers.join(', ')}</strong></span>}
          {feedback.comments.length > 0 && <span>{feedback.comments.length} commentaire{feedback.comments.length > 1 ? 's' : ''}</span>}
        </span>
      </div>

      {feedback.comments.length > 0 && (
        <ul className="fb-comments">
          {feedback.comments.map((c) => editing?.id === c.id ? (
            <li key={c.id} className="fb-comment is-editing">
              <div className="fb-comment-main">
                <LazyRichEditor value={editDoc} onChange={(d) => setEditDoc(d)} max={COMMENT_MAX} compact autoFocus onSubmit={() => void saveEdit()}
                  placeholder="Votre commentaire…" />
                <div className="fb-compose-actions">
                  <button type="button" className="btn btn-ghost small" onClick={() => setEditing(null)}>Annuler</button>
                  <button type="button" className="btn btn-primary small" disabled={busy || !docText(editDoc) || sameDoc(editDoc, c.body_doc)} onClick={() => void saveEdit()}>Enregistrer</button>
                </div>
              </div>
            </li>
          ) : (
            <CommentItem key={c.id} c={c}
              onEdit={c.mine ? () => { setEditing(c); setEditDoc(c.body_doc ?? { type: 'doc', content: [{ type: 'paragraph', content: [{ type: 'text', text: c.body }] }] }) } : undefined}
              onDelete={c.mine ? () => void remove(c) : undefined} />
          ))}
        </ul>
      )}

      {(open || text) && (
        <div className="fb-compose">
          <LazyRichEditor key={resetKey} value={doc} onChange={(d) => setDoc(d)} max={COMMENT_MAX} compact autoFocus={open && !text}
            onSubmit={() => void publish()} placeholder={`Un mot d’encouragement pour ${memberFirstName}…`} />
          <div className="fb-compose-actions">
            <span className="helper">Visible seulement par {memberFirstName} et vous. Une notification lui est envoyée.</span>
            <button type="button" className="btn btn-primary small" disabled={busy || !text || text.length > COMMENT_MAX} onClick={() => void publish()}>
              {busy ? <span className="spinner" /> : 'Publier'}
            </button>
          </div>
        </div>
      )}
    </div>
  )
}

/** Cote membre : qui a aime sa reponse et les commentaires du pasteur (lecture seule). */
export function MemberFeedback({ feedback }: { feedback: AssignmentFeedbackData | undefined }) {
  if (!feedback || (!feedback.likes.length && !feedback.comments.length)) return null
  return (
    <section className="fb fb-member" aria-label="Retour du pasteur">
      {feedback.likes.length > 0 && (
        <p className="fb-liked">
          <span className="fb-liked-heart"><Heart filled size={18} /></span>
          <span className="fb-liked-faces" aria-hidden>{feedback.likes.slice(0, 3).map((l, i) => <Avatar key={i} name={l.name} photo={l.photo_url} size={24} />)}</span>
          <span><strong>{feedback.likes.map((l) => l.name).join(', ')}</strong> {feedback.likes.length > 1 ? 'aiment' : 'aime'} votre devoir</span>
        </p>
      )}
      {feedback.comments.length > 0 && <ul className="fb-comments">{feedback.comments.map((c) => <CommentItem key={c.id} c={c} />)}</ul>}
    </section>
  )
}
