import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { EvaluationItem } from '../types'

function today() { return new Date().toISOString().slice(0, 10) }

export function EvaluationsPanel({ userId }: { userId: string }) {
  const [items, setItems] = useState<EvaluationItem[]>([])
  const [types, setTypes] = useState<{ key: string; label: string }[]>([])
  const [average, setAverage] = useState<number | null>(null)
  const [error, setError] = useState('')
  const [open, setOpen] = useState(false)

  const [type, setType] = useState('meditation_perso')
  const [title, setTitle] = useState('')
  const [score, setScore] = useState('')
  const [date, setDate] = useState(today())
  const [comment, setComment] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api<{ evaluations: EvaluationItem[]; average: number | null; types: { key: string; label: string }[] }>(`/admin/members/${userId}/évaluations`)
      .then((r) => { setItems(r.evaluations); setAverage(r.average); setTypes(r.types) })
      .catch(() => setItems([]))
  }, [userId])
  useEffect(() => { load() }, [load])

  async function add() {
    setError(''); setBusy(true)
    try {
      await api(`/admin/members/${userId}/évaluations`, {
        method: 'POST',
        body: { type, title: title || null, score: Number(score), evaluated_on: date, comment: comment || null },
      })
      setTitle(''); setScore(''); setComment(''); setDate(today()); setOpen(false); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove(id: number) {
    if (!confirm('Supprimer cette note ?')) return
    try { await api(`/admin/évaluations/${id}`, { method: 'DELETE' }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  return (
    <section className="panel mt">
      <div className="panel-head">
        <h3>Notes &amp; evaluations {average !== null && <span className="count-pill" style={{ background: '#0d5f57' }}>{average.toFixed(1)}/20</span>}</h3>
        <button className="btn btn-primary small" onClick={() => setOpen((o) => !o)}>{open ? 'Fermer' : '+ Ajouter une note'}</button>
      </div>
      {error && <div className="alert alert-error">{error}</div>}

      {open && (
        <div className="assign-box mb">
          <div className="field-row">
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Type</label>
              <select className="select" value={type} onChange={(e) => setType(e.target.value)}>
                {types.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Note (/20)</label>
              <input className="input" type="number" min="0" max="20" step="0.5" value={score} onChange={(e) => setScore(e.target.value)} />
            </div>
          </div>
          <div className="field-row mt">
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Titre (optionnel)</label>
              <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Ex. Méditation du mois" />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Date</label>
              <input className="input" type="date" max={today()} value={date} onChange={(e) => setDate(e.target.value)} />
            </div>
          </div>
          <div className="field mt" style={{ marginBottom: 0 }}>
            <label>Commentaire (optionnel)</label>
            <input className="input" value={comment} onChange={(e) => setComment(e.target.value)} />
          </div>
          <button className="btn btn-primary mt" disabled={busy || score === ''} onClick={add}>
            {busy ? <span className="spinner" /> : 'Enregistrer la note'}
          </button>
        </div>
      )}

      <div className="grade-list">
        {items.map((e) => (
          <div key={e.id} className="grade-row">
            <div className="grade-row-main">
              <span className="grade-type">{e.type_label}</span>
              {e.title && <span className="grade-title">{e.title}</span>}
              <span className="grade-date">{e.evaluated_on}{e.author ? ` · ${e.author}` : ''}</span>
            </div>
            <span className="grade-score">{e.score}<small>/{e.max_score}</small></span>
            <button className="org-del" onClick={() => remove(e.id)} aria-label="Supprimer">×</button>
          </div>
        ))}
        {items.length === 0 && <p className="helper">Aucune note pour l'instant.</p>}
      </div>
    </section>
  )
}
