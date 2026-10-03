import { useCallback, useEffect, useState } from 'react'
import { api } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { Icon } from '../../components/Icon'
import { SkeletonCard } from '../../components/Skeleton'
import { WeekReportView } from '../../components/leaderReports/WeekReportView'
import { fmtDay } from '../../utils/leaderReports'
import type { GemReportGem, GemReportsData, GemReportRow } from '../../types'

/** Formulaire de la semaine : deux cases par membre (culte, rencontre) et un mot facultatif. */
function WeekForm({ gem, week, onSaved, onCancel }: { gem: GemReportGem; week: string; onSaved: () => void; onCancel?: () => void }) {
  const initial = (): GemReportRow[] => gem.members.map((m) => {
    const sent = gem.current?.attendance.find((r) => r.user_id === m.user_id)
    return { user_id: m.user_id, name: m.name, culte: sent ? sent.culte : gem.culte_prefill.includes(m.user_id), rencontre: sent?.rencontre ?? false }
  })
  const [rows, setRows] = useState<GemReportRow[]>(initial)
  const [meeting, setMeeting] = useState(gem.current ? gem.current.meeting_held : true)
  const [comment, setComment] = useState(gem.current?.comment ?? '')
  const [busy, setBusy] = useState(false)

  const toggle = (userId: number, field: 'culte' | 'rencontre') =>
    setRows((rs) => rs.map((r) => (r.user_id === userId ? { ...r, [field]: !r[field] } : r)))
  const setAll = (field: 'culte' | 'rencontre', value: boolean) => setRows((rs) => rs.map((r) => ({ ...r, [field]: value })))

  async function send() {
    setBusy(true)
    try {
      await api(`/admin/gem-reports/${gem.gem_id}`, {
        method: 'PUT',
        body: { week_start: week, meeting_held: meeting, comment: comment.trim() || null, attendance: rows.map((r) => ({ user_id: r.user_id, culte: r.culte, rencontre: meeting && r.rencontre })) },
      })
      onSaved()
    } catch { /* toast automatique */ } finally { setBusy(false) }
  }

  if (gem.members.length === 0) {
    return <p className="helper">Ce GEM n'a pas encore de membre. Demandez à votre patriarche de les y rattacher.</p>
  }

  const allCulte = rows.every((r) => r.culte)
  const allMeeting = rows.every((r) => r.rencontre)

  return (
    <div className="gr-form">
      <label className="check-line gr-meeting">
        <input type="checkbox" checked={meeting} onChange={(e) => setMeeting(e.target.checked)} />
        <span>La rencontre du GEM a eu lieu cette semaine</span>
      </label>
      <div className="table-scroll">
        <table className="gr-table">
          <thead>
            <tr>
              <th scope="col">Membre</th>
              <th scope="col">Culte du dimanche<button type="button" className="btn-link gr-all" onClick={() => setAll('culte', !allCulte)}>{allCulte ? 'Aucun' : 'Tous'}</button></th>
              {meeting && <th scope="col">Rencontre du GEM<button type="button" className="btn-link gr-all" onClick={() => setAll('rencontre', !allMeeting)}>{allMeeting ? 'Aucun' : 'Tous'}</button></th>}
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.user_id}>
                <th scope="row">{r.name}</th>
                <td><input type="checkbox" className="gr-box" checked={r.culte} aria-label={`${r.name} : présent au culte`} onChange={() => toggle(r.user_id, 'culte')} /></td>
                {meeting && <td><input type="checkbox" className="gr-box" checked={r.rencontre} aria-label={`${r.name} : présent à la rencontre`} onChange={() => toggle(r.user_id, 'rencontre')} /></td>}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="field mt">
        <label htmlFor={`gr-comment-${gem.gem_id}`}>Un mot sur la semaine <span className="helper-inline">(facultatif)</span></label>
        <textarea id={`gr-comment-${gem.gem_id}`} className="input" rows={2} maxLength={2000} value={comment} onChange={(e) => setComment(e.target.value)}
          placeholder="Absences à signaler, besoins, sujets de prière" />
      </div>
      <div className="lr-actions">
        {onCancel ? <button className="btn btn-ghost small" disabled={busy} onClick={onCancel}>Annuler</button> : <span />}
        <button className="btn btn-primary small" disabled={busy} onClick={send}>
          {busy ? <span className="spinner" /> : gem.current ? 'Renvoyer le rapport corrigé' : 'Envoyer le rapport'}
        </button>
      </div>
    </div>
  )
}

function GemCard({ gem, data, onSaved }: { gem: GemReportGem; data: GemReportsData; onSaved: () => void }) {
  const [editing, setEditing] = useState(false)
  return (
    <section className="panel">
      <div className="panel-head">
        <div>
          <h3>{gem.name}</h3>
          <p className="panel-sub">{gem.tribe ? `Tribu ${gem.tribe} · ` : ''}semaine {data.due_label}</p>
        </div>
        <span className={`status-pill ${gem.current ? 'on' : 'due'}`}>{gem.current ? 'Envoyé' : 'À envoyer'}</span>
      </div>

      {gem.current && !editing ? (
        <>
          <WeekReportView report={gem.current} />
          <div className="lr-actions">
            <span className="helper">Modifiable jusqu'au {fmtDay(data.closes_on)}.</span>
            <button className="btn btn-ghost small" onClick={() => setEditing(true)}>Corriger</button>
          </div>
        </>
      ) : (
        <WeekForm key={gem.current?.submitted_at ?? 'new'} gem={gem} week={data.due_week}
          onCancel={gem.current ? () => setEditing(false) : undefined}
          onSaved={() => { setEditing(false); onSaved() }} />
      )}

      {gem.history.length > 0 && (
        <div className="mt">
          <p className="lr-context-title">Semaines précédentes</p>
          <div className="fiss-admin-list">
            {gem.history.map((h) => (
              <details key={h.id} className="fiss-admin-item">
                <summary>
                  <span className="fiss-hist-period">Semaine {h.label}</span>
                  <span className="fiss-hist-scores">Culte {h.culte_count}/{h.members_count}{h.meeting_held ? ` · Rencontre ${h.meeting_count}/${h.members_count}` : ' · Pas de rencontre'}</span>
                </summary>
                <div className="fiss-admin-detail"><WeekReportView report={h} /></div>
              </details>
            ))}
          </div>
        </div>
      )}
    </section>
  )
}

/** Rapport hebdomadaire du Garde : presences des membres de son GEM au culte et a la rencontre. */
export default function GemReport() {
  const [data, setData] = useState<GemReportsData | null>(null)
  const [denied, setDenied] = useState(false)

  const load = useCallback(() => {
    api<GemReportsData>('/admin/gem-reports').then((d) => { setData(d); setDenied(false) }).catch(() => setDenied(true))
  }, [])
  useEffect(() => { load() }, [load])

  return (
    <AppLayout title="Rapport de GEM" subtitle="Chaque semaine, pour votre patriarche et votre Assistant Pasteur">
      {denied ? (
        <div className="empty-state">
          <Icon name="gem" size={28} />
          <h3>Réservé aux Gardes</h3>
          <p>Le rapport hebdomadaire est rempli par le Garde de chaque GEM.</p>
        </div>
      ) : data === null ? (
        <div className="panel-grid"><SkeletonCard /></div>
      ) : (
        <div className="gr-page">
          <p className="helper gr-intro">
            Cochez les membres présents au culte du dimanche et à la rencontre du GEM. Le rapport part automatiquement à votre patriarche et à l'Assistant Pasteur de la tribu.
          </p>
          {data.gems.map((gem) => <GemCard key={gem.gem_id} gem={gem} data={data} onSaved={load} />)}
        </div>
      )}
    </AppLayout>
  )
}
