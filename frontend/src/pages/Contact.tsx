import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import type { MyRequest, RequestCategory } from '../types'

const CATEGORIES: { key: RequestCategory; label: string }[] = [
  { key: 'rendez-vous', label: 'Rendez-vous' },
  { key: 'aide', label: "Besoin d'aide" },
  { key: 'priere', label: 'Demande de prière' },
  { key: 'question', label: 'Question' },
  { key: 'autre', label: 'Autre' },
]

export default function Contact() {
  const [items, setItems] = useState<MyRequest[]>([])
  const [loading, setLoading] = useState(true)
  const [category, setCategory] = useState<RequestCategory>('question')
  const [subject, setSubject] = useState('')
  const [message, setMessage] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [ok, setOk] = useState('')

  function load() {
    setLoading(true)
    api<{ requests: MyRequest[] }>('/me/requests')
      .then((r) => setItems(r.requests)).catch(() => setItems([]))
      .finally(() => setLoading(false))
  }
  useEffect(() => { load() }, [])

  async function send() {
    setError(''); setOk(''); setBusy(true)
    try {
      await api('/me/requests', { method: 'POST', body: { category, subject: subject || null, message } })
      setSubject(''); setMessage(''); setCategory('question')
      setOk('Votre demande a bien été envoyée.'); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  return (
    <AppLayout title="Nous contacter" subtitle="Envoyez une demande à un responsable">
      {error && <div className="alert alert-error">{error}</div>}
      {ok && <div className="alert alert-ok">{ok}</div>}

      <div className="panel-grid">
        <section className="panel">
          <div className="panel-head"><h3>Nouvelle demande</h3></div>
          <div className="field">
            <label>Motif</label>
            <select className="select" value={category} onChange={(e) => setCategory(e.target.value as RequestCategory)}>
              {CATEGORIES.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}
            </select>
          </div>
          <div className="field">
            <label>Sujet <span className="helper" style={{ display: 'inline' }}>(optionnel)</span></label>
            <input className="input" value={subject} onChange={(e) => setSubject(e.target.value)} placeholder="Ex. Demande de rencontre" />
          </div>
          <div className="field">
            <label>Message</label>
            <textarea className="input" rows={5} value={message} onChange={(e) => setMessage(e.target.value)} placeholder="Ecrivez votre message..." />
          </div>
          <button className="btn btn-primary" disabled={busy || !message.trim()} onClick={send}>
            {busy ? <span className="spinner" /> : 'Envoyer la demande'}
          </button>
        </section>

        <section className="panel">
          <div className="panel-head"><h3>Mes demandes</h3></div>
          {loading ? <SkeletonCard /> : (
            <div className="feed">
              {items.map((r) => (
                <article key={r.id} className="feed-item">
                  <div className="feed-head">
                    <span className="chip">{r.category_label}</span>
                    <span className={`req-status req-${r.status}`}>{r.status_label}</span>
                    <span className="feed-date" style={{ marginLeft: 'auto' }}>{r.created_at}</span>
                  </div>
                  {r.subject && <h4 className="feed-title">{r.subject}</h4>}
                  <p className="feed-body">{r.message}</p>
                  {r.reply && (
                    <div className="req-reply">
                      <span className="req-reply-label">Réponse du responsable{r.replied_at ? ` · ${r.replied_at}` : ''}</span>
                      <p>{r.reply}</p>
                    </div>
                  )}
                </article>
              ))}
              {items.length === 0 && <p className="helper">Vous n'avez pas encore envoyé de demande.</p>}
            </div>
          )}
        </section>
      </div>
    </AppLayout>
  )
}
