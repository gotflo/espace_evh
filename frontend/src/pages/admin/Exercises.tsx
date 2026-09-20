import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { getReference } from '../../api/reference'
import { AppLayout } from '../../components/AppLayout'
import { TargetField } from '../../components/TargetField'
import type { Department, ExerciseListItem, ExerciseResponseItem, Tribe } from '../../types'

const TYPES = [
  { key: 'verset', label: 'Verset a mediter' },
  { key: 'quiz', label: 'Quiz' },
  { key: 'reflexion', label: 'Reflexion' },
  { key: 'lecture', label: 'Lecture' },
]

export default function Exercises() {
  const [exercises, setExercises] = useState<ExerciseListItem[]>([])
  const [tribes, setTribes] = useState<Tribe[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [mode, setMode] = useState<'list' | 'new'>('list')
  const [responses, setResponses] = useState<{ title: string; items: ExerciseResponseItem[] } | null>(null)
  const [error, setError] = useState('')

  // Formulaire
  const [title, setTitle] = useState('')
  const [content, setContent] = useState('')
  const [type, setType] = useState('reflexion')
  const [targetType, setTargetType] = useState<'all' | 'tribe' | 'department'>('all')
  const [targetId, setTargetId] = useState('')
  const [dueDate, setDueDate] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api<{ exercises: ExerciseListItem[] }>('/admin/exercises').then((r) => setExercises(r.exercises)).catch(() => setExercises([]))
  }, [])
  useEffect(() => { load() }, [load])
  useEffect(() => {
    getReference().then((r) => { setTribes(r.tribes); setDepartments(r.departments) }).catch(() => {})
  }, [])

  async function create() {
    setError(''); setBusy(true)
    try {
      await api('/admin/exercises', {
        method: 'POST',
        body: {
          title, content, type, target_type: targetType,
          target_id: targetType === 'all' ? null : Number(targetId),
          due_date: dueDate || null,
        },
      })
      setTitle(''); setContent(''); setType('reflexion'); setTargetType('all'); setTargetId(''); setDueDate('')
      setMode('list'); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function remove(id: number) {
    if (!confirm('Supprimer cet exercice et ses reponses ?')) return
    setError('')
    try { await api(`/admin/exercises/${id}`, { method: 'DELETE' }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  async function viewResponses(ex: ExerciseListItem) {
    const r = await api<{ exercise: { title: string }; responses: ExerciseResponseItem[] }>(`/admin/exercises/${ex.id}/responses`)
    setResponses({ title: r.exercise.title, items: r.responses })
  }

  const createBtn = <button className="btn btn-primary small" onClick={() => setMode('new')}>+ Creer un exercice</button>

  // --- Vue reponses ---
  if (responses) {
    return (
      <AppLayout title="Reponses" subtitle={responses.title}
        actions={<button className="btn btn-ghost small" onClick={() => setResponses(null)}>← Retour</button>}>
        <section className="panel">
          <div className="journal-list">
            {responses.items.map((r) => (
              <div key={r.user_id} className="journal-item">
                <div className="journal-item-head">
                  <span className="journal-type">{r.name}</span>
                  <span className="journal-date">{r.completed_at}</span>
                </div>
                <p className="journal-note">{r.response}</p>
              </div>
            ))}
            {responses.items.length === 0 && <p className="helper">Aucune reponse pour le moment.</p>}
          </div>
        </section>
      </AppLayout>
    )
  }

  return (
    <AppLayout title="Exercices" subtitle="Exercices spirituels pour les fideles"
      actions={mode === 'list' ? createBtn : undefined}>
      {error && <div className="alert alert-error">{error}</div>}

      {mode === 'new' ? (
        <section className="panel" style={{ maxWidth: 640 }}>
          <div className="panel-head">
            <h3>Nouvel exercice</h3>
            <button className="btn-link" onClick={() => setMode('list')}>Annuler</button>
          </div>
          <div className="field">
            <label>Titre</label>
            <input className="input" value={title} onChange={(e) => setTitle(e.target.value)} placeholder="Ex. Memoriser Jean 3:16" />
          </div>
          <div className="field">
            <label>Consigne / contenu</label>
            <textarea className="input" rows={3} value={content} onChange={(e) => setContent(e.target.value)} />
          </div>
          <div className="field-row">
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Type</label>
              <select className="select" value={type} onChange={(e) => setType(e.target.value)}>
                {TYPES.map((t) => <option key={t.key} value={t.key}>{t.label}</option>)}
              </select>
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label>Echeance (facultatif)</label>
              <input className="input" type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
            </div>
          </div>
          <TargetField targetType={targetType} targetId={targetId} onType={setTargetType} onId={setTargetId} tribes={tribes} departments={departments} />
          <button className="btn btn-primary mt" disabled={busy || !title.trim() || !content.trim() || (targetType !== 'all' && !targetId)} onClick={create}>
            {busy ? <span className="spinner" /> : "Publier l'exercice"}
          </button>
        </section>
      ) : (
        <div className="role-grid">
          {exercises.map((e) => (
            <div key={e.id} className="role-card" style={{ cursor: 'default' }}>
              <div className="role-card-top">
                <span className="role-card-name">{e.title}</span>
                <button className="org-del" onClick={() => remove(e.id)} aria-label="Supprimer">×</button>
              </div>
              <div className="role-card-meta" style={{ marginTop: 0 }}>
                <span>{e.type_label}</span><span>{e.target}</span>
              </div>
              {e.due_date && <p className="helper">Echeance : {e.due_date}</p>}
              <button className="btn btn-ghost small mt" onClick={() => viewResponses(e)}>
                {e.responses_count} reponse(s)
              </button>
            </div>
          ))}
          {exercises.length === 0 && <p className="helper">Aucun exercice. Cliquez sur "Creer un exercice".</p>}
        </div>
      )}
    </AppLayout>
  )
}
