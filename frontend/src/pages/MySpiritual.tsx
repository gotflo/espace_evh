import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import { MyGrades } from '../components/MyGrades'
import { MyOverview } from '../components/MyOverview'
import type { MySpiritualData } from '../types'

function today() { return new Date().toISOString().slice(0, 10) }

export default function MySpiritual() {
  const [data, setData] = useState<MySpiritualData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')

  const [type, setType] = useState('temoignage')
  const [entryDate, setEntryDate] = useState(today())
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    api<MySpiritualData>('/me/spiritual')
      .then((d) => { setData(d); if (d.entry_types[0]) setType((t) => t || d.entry_types[0].key) })
      .catch(() => setData(null))
      .finally(() => setLoading(false))
  }, [])
  useEffect(() => { load() }, [load])

  async function addEntry() {
    setError(''); setBusy(true)
    try {
      await api('/me/spiritual/entries', { method: 'POST', body: { type, entry_date: entryDate, note } })
      setNote(''); setEntryDate(today()); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function removeEntry(id: number) {
    if (!confirm('Supprimer cette entree ?')) return
    setError('')
    try { await api(`/me/spiritual/entries/${id}`, { method: 'DELETE' }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  async function toggleMilestone(key: string, reached: boolean) {
    setError('')
    try { await api('/me/spiritual/milestones', { method: 'POST', body: { milestone_key: key, reached } }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  return (
    <AppLayout title="Ma vie spirituelle" subtitle="Votre parcours et votre journal personnel">
      {error && <div className="alert alert-error">{error}</div>}

      <MyOverview />

      {loading ? (
        <div className="panel-grid"><SkeletonCard /><SkeletonCard /></div>
      ) : (
        <div className="panel-grid">
          <section className="panel">
            <div className="panel-head"><h3>Mon parcours</h3></div>
            <p className="helper" style={{ marginTop: 0, marginBottom: '0.8rem' }}>Cochez les etapes que vous avez franchies.</p>
            <div className="milestone-list">
              {data?.milestones.map((m) => (
                <button key={m.key} className={`milestone-toggle ${m.reached ? 'on' : ''}`}
                  onClick={() => toggleMilestone(m.key, !m.reached)}>
                  <span className="check-box2">{m.reached ? '✓' : ''}</span>
                  <span className="milestone-label">{m.label}</span>
                  {m.reached_at && <span className="milestone-date">{m.reached_at}</span>}
                </button>
              ))}
            </div>
          </section>

          <section className="panel">
            <div className="panel-head"><h3>Nouvelle entree</h3></div>
            <div className="field-row">
              <div className="field" style={{ marginBottom: 0 }}>
                <label>Type</label>
                <select className="select" value={type} onChange={(e) => setType(e.target.value)}>
                  {data?.entry_types.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
                </select>
              </div>
              <div className="field" style={{ marginBottom: 0 }}>
                <label>Date</label>
                <input className="input" type="date" max={today()} value={entryDate} onChange={(e) => setEntryDate(e.target.value)} />
              </div>
            </div>
            <div className="field mt">
              <label>Votre message (temoignage, priere, besoin...)</label>
              <textarea className="input" rows={4} value={note} onChange={(e) => setNote(e.target.value)} />
            </div>
            <button className="btn btn-primary" disabled={busy || !note.trim()} onClick={addEntry}>
              {busy ? <span className="spinner" /> : 'Ajouter a mon journal'}
            </button>
          </section>
        </div>
      )}

      {!loading && (
        <section className="panel mt">
          <div className="panel-head"><h3>Mon journal</h3></div>
          <div className="feed">
            {data?.entries.map((e) => (
              <article key={e.id} className="feed-item">
                <div className="feed-head">
                  <span className="chip">{e.type_label}</span>
                  <span className="feed-date">{e.entry_date}{!e.mine && e.author ? ` · par ${e.author}` : ''}</span>
                  {e.mine && <button className="org-del" onClick={() => removeEntry(e.id)} aria-label="Supprimer">×</button>}
                </div>
                {e.note && <p className="feed-body">{e.note}</p>}
              </article>
            ))}
            {data && data.entries.length === 0 && <p className="helper">Votre journal est vide. Ajoutez votre premiere entree.</p>}
          </div>
        </section>
      )}

      <MyGrades />
    </AppLayout>
  )
}
