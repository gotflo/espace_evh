import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { SkeletonCard } from '../../components/Skeleton'
import type { AdminRequest, RequestStatus } from '../../types'

const NEXT: { key: RequestStatus; label: string }[] = [
  { key: 'nouvelle', label: 'Nouvelle' },
  { key: 'en_cours', label: 'En cours' },
  { key: 'traitee', label: 'Traitee' },
]

export default function Requests() {
  const [items, setItems] = useState<AdminRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [replyFor, setReplyFor] = useState<number | null>(null)
  const [replyText, setReplyText] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    api<{ requests: AdminRequest[] }>('/admin/requests')
      .then((r) => setItems(r.requests)).catch(() => setItems([]))
      .finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  async function setStatus(id: number, status: RequestStatus) {
    setError('')
    // Mise a jour optimiste.
    setItems((prev) => prev.map((r) => (r.id === id ? { ...r, status } : r)))
    try { await api(`/admin/requests/${id}/status`, { method: 'PATCH', body: { status } }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.'); load() }
  }

  function openReply(r: AdminRequest) {
    setReplyFor(r.id); setReplyText(r.reply ?? ''); setError('')
  }
  async function sendReply(id: number) {
    if (!replyText.trim()) return
    setBusy(true); setError('')
    try {
      await api(`/admin/requests/${id}/reply`, { method: 'POST', body: { reply: replyText.trim() } })
      setReplyFor(null); setReplyText(''); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  return (
    <AppLayout title="Demandes des fideles" subtitle="Messages recus, a traiter">
      {error && <div className="alert alert-error">{error}</div>}
      {loading ? (
        <div className="feed"><SkeletonCard /><SkeletonCard /></div>
      ) : (
        <div className="feed">
          {items.map((r) => (
            <article key={r.id} className={`feed-item ${r.status === 'nouvelle' ? 'unread' : ''}`}>
              <div className="feed-head">
                <span className="chip">{r.category_label}</span>
                <span className={`req-status req-${r.status}`}>{r.status_label}</span>
                <span className="feed-date" style={{ marginLeft: 'auto' }}>{r.created_at}</span>
              </div>
              <h4 className="feed-title">{r.sender}{r.subject ? ` , ${r.subject}` : ''}</h4>
              <p className="feed-body">{r.message}</p>

              {r.reply && replyFor !== r.id && (
                <div className="req-reply">
                  <span className="req-reply-label">Votre reponse{r.replied_by ? ` , ${r.replied_by}` : ''}{r.replied_at ? ` · ${r.replied_at}` : ''}</span>
                  <p>{r.reply}</p>
                </div>
              )}

              {replyFor === r.id ? (
                <div className="req-reply-box">
                  <textarea className="input" rows={3} value={replyText} autoFocus
                    onChange={(e) => setReplyText(e.target.value)} placeholder="Ecrivez votre reponse au fidele..." />
                  <div className="req-reply-actions">
                    <button className="btn-link" onClick={() => setReplyFor(null)}>Annuler</button>
                    <button className="btn btn-primary small" disabled={busy || !replyText.trim()} onClick={() => sendReply(r.id)}>
                      {busy ? <span className="spinner" /> : 'Envoyer la reponse'}
                    </button>
                  </div>
                </div>
              ) : (
                <div className="req-actions">
                  <button className="btn-link" onClick={() => openReply(r)}>{r.reply ? 'Modifier la reponse' : '✍️ Repondre'}</button>
                  {r.sender_phone && <a className="btn-link" href={`tel:${r.sender_phone}`}>📞 {r.sender_phone}</a>}
                  <div className="req-status-btns">
                    {NEXT.map((s) => (
                      <button key={s.key} className={`status-btn ${r.status === s.key ? 'on' : ''}`}
                        onClick={() => setStatus(r.id, s.key)}>{s.label}</button>
                    ))}
                  </div>
                </div>
              )}
            </article>
          ))}
          {items.length === 0 && <p className="helper">Aucune demande pour le moment.</p>}
        </div>
      )}
    </AppLayout>
  )
}
