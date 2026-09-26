import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { VerseCard, type VerseContent } from '../../components/VerseBanner'

type VerseState = 'draft' | 'scheduled' | 'live' | 'expired'

interface Verse extends VerseContent {
  id: number
  status: 'draft' | 'published'
  state: VerseState
  starts_at: string | null
  ends_at: string | null
  is_pinned: boolean
  position: number
  author: string | null
  updated_by: string | null
  updated_at: string | null
}

interface HistoryItem { id: number; label: string; actor: string; old: Record<string, unknown> | null; new: Record<string, unknown> | null; created_at: string | null }

const STATE_LABEL: Record<VerseState, string> = { draft: 'Brouillon', scheduled: 'Programmé', live: 'En ligne', expired: 'Expiré' }
const dt = (iso: string | null) => (iso ? new Date(iso).toLocaleString('fr-CA', { dateStyle: 'medium', timeStyle: 'short' }) : '')
const localInput = (iso: string | null) => {
  if (!iso) return ''
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}

// ---------------------------------------------------------------- Formulaire

function VerseEditor({ verse, onClose, onSaved }: { verse: Verse | null; onClose: () => void; onSaved: () => void }) {
  const [label, setLabel] = useState(verse?.label ?? 'Parole du moment')
  const [text, setText] = useState(verse?.text ?? '')
  const [reference, setReference] = useState(verse?.reference ?? '')
  const [message, setMessage] = useState(verse?.message ?? '')
  const [schedule, setSchedule] = useState(!!(verse?.starts_at || verse?.ends_at))
  const [startsAt, setStartsAt] = useState(localInput(verse?.starts_at ?? null))
  const [endsAt, setEndsAt] = useState(localInput(verse?.ends_at ?? null))
  const [pinned, setPinned] = useState(verse?.is_pinned ?? false)
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [busy, setBusy] = useState(false)

  async function save(status: 'draft' | 'published') {
    setBusy(true); setErrors({})
    const body = {
      label: label.trim(), text: text.trim(), reference: reference.trim(), message: message.trim() || null, status,
      starts_at: schedule && startsAt ? startsAt : null, ends_at: schedule && endsAt ? endsAt : null, is_pinned: pinned,
    }
    try {
      if (verse) await api(`/admin/verses/${verse.id}`, { method: 'PUT', body })
      else await api('/admin/verses', { method: 'POST', body })
      onSaved(); onClose()
    } catch (err) {
      if (err instanceof ApiError) setErrors(err.errors)
    } finally { setBusy(false) }
  }

  const err = (k: string) => errors[k]?.[0] && <p className="helper editor-error">{errors[k][0]}</p>
  const valid = label.trim() && text.trim().length >= 5 && reference.trim()

  return (
    <div className="modal-overlay" onMouseDown={onClose}>
      <div className="modal-box verse-editor" role="dialog" aria-modal="true" aria-label={verse ? 'Modifier le texte' : 'Nouveau texte'} onMouseDown={(e) => e.stopPropagation()}>
        <div className="panel-head">
          <h3>{verse ? 'Modifier le texte' : 'Nouveau texte'}</h3>
          <button className="btn-link" onClick={onClose}>Fermer</button>
        </div>

        <div className="field">
          <label htmlFor="v-label">Intitulé</label>
          <input id="v-label" className="input" maxLength={60} value={label} onChange={(e) => setLabel(e.target.value)} placeholder="Ex. Notre appel, Parole du mois" />
          {err('label')}
        </div>
        <div className="field">
          <label htmlFor="v-text">Texte biblique</label>
          <textarea id="v-text" className="input" rows={4} maxLength={1000} value={text} onChange={(e) => setText(e.target.value)} />
          {err('text')}
        </div>
        <div className="field">
          <label htmlFor="v-ref">Référence</label>
          <input id="v-ref" className="input" maxLength={80} value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Ex. Jean 3.16" />
          {err('reference')}
        </div>
        <div className="field">
          <label htmlFor="v-msg">Mot d'accompagnement (facultatif)</label>
          <textarea id="v-msg" className="input" rows={2} maxLength={500} value={message} onChange={(e) => setMessage(e.target.value)} placeholder="Quelques mots pour accompagner le texte" />
        </div>

        <label className="switch-line mt">
          <input type="checkbox" checked={schedule} onChange={(e) => setSchedule(e.target.checked)} />
          <span>Afficher seulement pendant une période</span>
        </label>
        {schedule && (
          <div className="field-row mt-sm">
            <div className="field" style={{ marginBottom: 0 }}>
              <label htmlFor="v-start">Du</label>
              <input id="v-start" className="input" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} />
            </div>
            <div className="field" style={{ marginBottom: 0 }}>
              <label htmlFor="v-end">Au</label>
              <input id="v-end" className="input" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} />
            </div>
          </div>
        )}
        {err('starts_at')}{err('ends_at')}
        <label className="switch-line mt">
          <input type="checkbox" checked={pinned} onChange={(e) => setPinned(e.target.checked)} />
          <span>Mettre en avant : remplace la rotation {schedule ? 'pendant cette période' : 'tant qu’il est publié'}</span>
        </label>
        {err('is_pinned')}

        <span className="mini-label mt">Aperçu</span>
        <VerseCard verse={{ id: null, label: label || 'Intitulé', text: text || 'Le texte apparaîtra ici.', reference: reference || 'Référence', message: message.trim() || null }} />

        <div className="row-actions mt">
          <button className="btn btn-ghost" disabled={busy || !valid} onClick={() => save('draft')}>Enregistrer en brouillon</button>
          <button className="btn btn-primary" disabled={busy || !valid} onClick={() => save('published')}>
            {busy ? <span className="spinner" /> : schedule && startsAt && new Date(startsAt) > new Date() ? 'Programmer' : 'Publier'}
          </button>
        </div>
      </div>
    </div>
  )
}

// ---------------------------------------------------------------- Page

export default function Verses() {
  const [verses, setVerses] = useState<Verse[] | null>(null)
  const [today, setToday] = useState<VerseContent | null>(null)
  const [fallback, setFallback] = useState(false)
  const [editing, setEditing] = useState<Verse | null | 'new'>(null)
  const [history, setHistory] = useState<{ verse: Verse; items: HistoryItem[] } | null>(null)

  const load = useCallback(() => {
    api<{ verses: Verse[]; today: VerseContent; today_is_fallback: boolean }>('/admin/verses')
      .then((r) => { setVerses(r.verses); setToday(r.today); setFallback(r.today_is_fallback) })
      .catch(() => setVerses([]))
  }, [])
  useEffect(() => { load() }, [load])

  async function move(index: number, delta: number) {
    if (!verses) return
    const next = [...verses]
    const [item] = next.splice(index, 1)
    next.splice(index + delta, 0, item)
    setVerses(next)
    try { await api('/admin/verses/reorder', { method: 'POST', body: { ids: next.map((v) => v.id) }, toast: false }); load() } catch { load() }
  }

  async function togglePublish(v: Verse) {
    try {
      await api(`/admin/verses/${v.id}`, {
        method: 'PUT',
        body: { label: v.label, text: v.text, reference: v.reference, message: v.message, starts_at: v.starts_at, ends_at: v.ends_at, is_pinned: v.is_pinned, status: v.status === 'published' ? 'draft' : 'published' },
      })
      load()
    } catch { /* message affiche */ }
  }

  async function remove(v: Verse) {
    if (!confirm(`Supprimer « ${v.reference} » ? Cette action est définitive.`)) return
    try { await api(`/admin/verses/${v.id}`, { method: 'DELETE' }); load() } catch { /* message affiche */ }
  }

  async function openHistory(v: Verse) {
    const r = await api<{ history: HistoryItem[] }>(`/admin/verses/${v.id}/history`)
    setHistory({ verse: v, items: r.history })
  }

  const live = (verses ?? []).filter((v) => v.state === 'live')

  return (
    <AppLayout title="Versets du tableau de bord" subtitle="Le texte biblique affiché à tous en haut de l'accueil"
      actions={<button className="btn btn-primary small" onClick={() => setEditing('new')}>+ Nouveau texte</button>}>
      <section className="panel">
        <div className="panel-head"><h3>Affiché aujourd'hui</h3></div>
        {today ? <VerseCard verse={today} /> : <div className="skeleton verse-skeleton" />}
        <p className="helper mt-sm">
          {fallback
            ? "Aucun texte n'est en ligne : le verset de l'église (Actes 20.28) s'affiche par défaut."
            : live.some((v) => v.is_pinned)
              ? 'Un texte mis en avant est affiché pendant sa période ; la rotation reprend ensuite.'
              : live.length > 1
                ? `${live.length} textes en ligne : un texte différent chaque jour, dans l'ordre de la liste.`
                : 'Un seul texte en ligne : il reste affiché tant qu’il est publié.'}
        </p>
      </section>

      {verses === null ? (
        <div className="verse-list mt">{[0, 1].map((i) => <div key={i} className="skeleton skeleton-row" />)}</div>
      ) : verses.length === 0 ? (
        <div className="empty-state mt"><span aria-hidden>📖</span><h3>Aucun texte</h3><p>Ajoutez un premier texte : il s'affichera sur le tableau de bord de tous les membres.</p></div>
      ) : (
        <ol className="verse-list mt">
          {verses.map((v, i) => (
            <li key={v.id} className={`verse-item state-${v.state}`}>
              <div className="verse-order">
                <button className="icon-btn" aria-label="Monter" disabled={i === 0} onClick={() => move(i, -1)}>▲</button>
                <button className="icon-btn" aria-label="Descendre" disabled={i === verses.length - 1} onClick={() => move(i, 1)}>▼</button>
              </div>
              <div className="verse-item-main">
                <div className="verse-item-head">
                  <span className={`verse-state ${v.state}`}>{STATE_LABEL[v.state]}</span>
                  {v.is_pinned && <span className="verse-state pinned">Mis en avant</span>}
                  <strong>{v.reference}</strong>
                  <small>{v.label}</small>
                </div>
                <p className="verse-item-text">{v.text}</p>
                <small className="helper">
                  {v.starts_at || v.ends_at ? `${v.starts_at ? `du ${dt(v.starts_at)} ` : ''}${v.ends_at ? `au ${dt(v.ends_at)}` : ''} · ` : ''}
                  Modifié {v.updated_at ? `le ${dt(v.updated_at)}` : ''}{v.updated_by ? ` par ${v.updated_by}` : ''}
                </small>
              </div>
              <div className="verse-item-actions">
                <button className="btn btn-ghost small" onClick={() => setEditing(v)}>Modifier</button>
                <button className="btn btn-ghost small" onClick={() => togglePublish(v)}>{v.status === 'published' ? 'Retirer' : 'Publier'}</button>
                <button className="btn-link" onClick={() => openHistory(v)}>Historique</button>
                <button className="org-del" onClick={() => remove(v)} aria-label={`Supprimer ${v.reference}`}>×</button>
              </div>
            </li>
          ))}
        </ol>
      )}

      {editing && <VerseEditor verse={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={load} />}

      {history && (
        <div className="modal-overlay" onMouseDown={() => setHistory(null)}>
          <div className="modal-box" role="dialog" aria-modal="true" aria-label="Historique" onMouseDown={(e) => e.stopPropagation()}>
            <div className="panel-head"><h3>Historique · {history.verse.reference}</h3><button className="btn-link" onClick={() => setHistory(null)}>Fermer</button></div>
            {history.items.length === 0 ? <p className="helper">Aucune modification enregistrée.</p> : (
              <ul className="timeline">
                {history.items.map((h) => (
                  <li key={h.id}>
                    <strong>{h.label}</strong>
                    <small>{h.actor} · {dt(h.created_at)}</small>
                    {h.new && Object.keys(h.new).length > 0 && <em>{Object.keys(h.new).map((k) => ({ label: 'intitulé', text: 'texte', reference: 'référence', message: 'mot', status: 'statut', starts_at: 'début', ends_at: 'fin', is_pinned: 'mise en avant' } as Record<string, string>)[k] ?? k).join(', ')}</em>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </AppLayout>
  )
}
