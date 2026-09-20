import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { SpiritualData } from '../types'

const TODAY = new Date().toISOString().slice(0, 10)

export function SpiritualPanel({ userId, canRecord }: { userId: string; canRecord: boolean }) {
  const [data, setData] = useState<SpiritualData | null>(null)
  const [error, setError] = useState('')

  // Formulaire d'ajout d'entree
  const [type, setType] = useState('')
  const [date, setDate] = useState(TODAY)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api<SpiritualData>(`/admin/members/${userId}/spiritual`).then(setData).catch(() => setData(null))
  }, [userId])
  useEffect(() => { load() }, [load])

  async function run(fn: () => Promise<unknown>) {
    setError('')
    try { await fn(); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  async function addEntry() {
    if (!type) return
    setBusy(true)
    await run(async () => {
      await api(`/admin/members/${userId}/spiritual/entries`, { method: 'POST', body: { type, entry_date: date, note } })
      setType(''); setNote(''); setDate(TODAY)
    })
    setBusy(false)
  }

  const toggleMilestone = (key: string, reached: boolean) =>
    run(() => api(`/admin/members/${userId}/spiritual/milestones`, { method: 'POST', body: { milestone_key: key, reached } }))

  const removeEntry = (id: number) =>
    run(() => api(`/admin/members/${userId}/spiritual/entries/${id}`, { method: 'DELETE' }))

  if (!data) return null

  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Suivi spirituel</h3></div>
      {error && <div className="alert alert-error">{error}</div>}

      {/* Etapes */}
      <label className="mini-label">Etapes du parcours</label>
      <div className="chip-picker">
        {data.milestones.map((m) => (
          <button
            key={m.key} type="button"
            className={`chip-toggle ${m.reached ? 'on' : ''}`}
            disabled={!canRecord}
            title={m.reached && m.reached_at ? `Franchi le ${m.reached_at}` : ''}
            onClick={() => canRecord && toggleMilestone(m.key, !m.reached)}
          >
            {m.reached ? '✓ ' : ''}{m.label}
          </button>
        ))}
      </div>

      {/* Journal */}
      <label className="mini-label" style={{ marginTop: '1.4rem' }}>Journal</label>
      {canRecord && (
        <div className="journal-form">
          <div className="journal-form-row">
            <select className="select" value={type} onChange={(e) => setType(e.target.value)}>
              <option value="">Type d'evenement...</option>
              {data.entry_types.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
            </select>
            <input className="input" type="date" max={TODAY} value={date} onChange={(e) => setDate(e.target.value)} />
          </div>
          <textarea className="input" rows={2} placeholder="Note (facultatif)" value={note} onChange={(e) => setNote(e.target.value)} />
          <button className="btn btn-primary small" disabled={busy || !type} onClick={addEntry}>
            {busy ? <span className="spinner" /> : 'Ajouter au journal'}
          </button>
        </div>
      )}

      <div className="journal-list">
        {data.entries.map((e) => (
          <div key={e.id} className="journal-item">
            <div className="journal-item-head">
              <span className="journal-type">{e.type_label}</span>
              <span className="journal-date">{e.entry_date}</span>
              {canRecord && <button className="org-del" onClick={() => removeEntry(e.id)} aria-label="Supprimer">×</button>}
            </div>
            {e.note && <p className="journal-note">{e.note}</p>}
            {e.author && <span className="journal-author">par {e.author}</span>}
          </div>
        ))}
        {data.entries.length === 0 && <p className="helper">Aucune entree dans le journal.</p>}
      </div>
    </section>
  )
}
