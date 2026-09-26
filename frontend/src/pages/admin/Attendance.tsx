import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import type { AttendanceMember, AttendanceStatus, RosterData } from '../../types'
import { ymd } from '../../utils/events'

// Date locale (et non UTC : le soir, toISOString donnerait deja le lendemain).
const TODAY = ymd(new Date())
const EVENTS = ['Culte', 'Culte du dimanche', 'Réunion de prière', 'Étude biblique', 'Répétition', 'Autre']
const REH_STATUS: { key: AttendanceStatus; label: string }[] = [
  { key: 'present', label: 'Présent' },
  { key: 'retard', label: 'Retard' },
  { key: 'absent_justifie', label: 'Excuse' },
  { key: 'absent', label: 'Absent' },
]

export default function Attendance() {
  const { hasPermission } = useAuth()
  const canRecord = hasPermission('attendance.record')

  const [date, setDate] = useState(TODAY)
  const [event, setEvent] = useState('Culte')
  const [kind, setKind] = useState<'culte' | 'repetition'>('culte')
  const [members, setMembers] = useState<AttendanceMember[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [hidden, setHidden] = useState(0)
  const [query, setQuery] = useState('')
  const [comebacks, setComebacks] = useState<AttendanceMember[]>([])

  const load = useCallback(() => {
    setLoading(true)
    api<RosterData>(`/admin/attendance?date=${date}&event=${encodeURIComponent(event)}&kind=${kind}`)
      .then((r) => {
        setMembers(r.members.map((m) => ({ ...m, status: m.status ?? (kind === 'repetition' ? 'absent' : null) })))
        setHidden(r.inactive_hidden ?? 0)
      })
      .catch(() => setMembers([]))
      .finally(() => setLoading(false))
  }, [date, event, kind])
  useEffect(() => { load() }, [load])

  // Recherche : filtre la feuille ET retrouve un fidele inactif qui revient (masque par defaut).
  useEffect(() => {
    const q = query.trim()
    if (q.length < 2 || hidden === 0) { setComebacks([]); return }
    const timer = window.setTimeout(() => {
      api<{ matches: AttendanceMember[] }>(`/admin/attendance?date=${date}&event=${encodeURIComponent(event)}&kind=${kind}&q=${encodeURIComponent(q)}`)
        .then((r) => setComebacks(r.matches.filter((m) => !members.some((x) => x.user_id === m.user_id))))
        .catch(() => setComebacks([]))
    }, 300)
    return () => window.clearTimeout(timer)
  }, [query, hidden, date, event, kind, members])

  function addBack(m: AttendanceMember) {
    setMembers((ms) => [{ ...m, present: true, status: 'present' }, ...ms])
    setComebacks((cs) => cs.filter((c) => c.user_id !== m.user_id))
    setHidden((h) => Math.max(0, h - 1))
  }

  const toggle = (uid: number) =>
    setMembers((ms) => ms.map((m) => (m.user_id === uid ? { ...m, present: !m.present } : m)))
  const setStatus = (uid: number, status: AttendanceStatus) =>
    setMembers((ms) => ms.map((m) => (m.user_id === uid ? { ...m, status, present: status === 'present' } : m)))
  const setAll = (present: boolean) => setMembers((ms) => ms.map((m) => ({ ...m, present })))

  const presentCount = members.filter((m) => (kind === 'repetition' ? m.status === 'present' : m.present)).length
  const q = query.trim().toLowerCase()
  const shown = q ? members.filter((m) => m.full_name.toLowerCase().includes(q)) : members

  async function save() {
    setError(''); setSaving(true)
    try {
      const body = kind === 'repetition'
        ? { attended_on: date, event, kind, statuses: Object.fromEntries(members.map((m) => [m.user_id, m.status ?? 'absent'])) }
        : { attended_on: date, event, kind, present_user_ids: members.filter((m) => m.present).map((m) => m.user_id) }
      await api<{ message: string }>('/admin/attendance', { method: 'POST', body })
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setSaving(false) }
  }

  return (
    <AppLayout title="Présences" subtitle="Feuille de présence des cultes et répétitions">
      <section className="panel">
        <div className="attendance-controls">
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Date</label>
            <input className="input" type="date" max={TODAY} value={date} onChange={(e) => setDate(e.target.value)} />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Événement</label>
            <select className="select" value={event} onChange={(e) => setEvent(e.target.value)}>
              {EVENTS.map((ev) => <option key={ev} value={ev}>{ev}</option>)}
            </select>
          </div>
        </div>

        <div className="field mt" style={{ marginBottom: 0 }}>
          <label>Type de session</label>
          <div className="pill-choices">
            <button type="button" className={`pill-choice ${kind === 'culte' ? 'on' : ''}`} onClick={() => setKind('culte')}>Culte / activité</button>
            <button type="button" className={`pill-choice ${kind === 'repetition' ? 'on' : ''}`} onClick={() => setKind('repetition')}>Répétition (avec retards)</button>
          </div>
        </div>

        <div className="toolbar" style={{ marginTop: '1rem' }}>
          <p className="helper" style={{ margin: 0 }}>
            {loading ? 'Chargement...' : `${presentCount} / ${members.length} présent(s)`}
          </p>
          {canRecord && kind === 'culte' && members.length > 0 && (
            <div className="attendance-quick">
              <button className="btn-link" onClick={() => setAll(true)}>Tout présent</button>
              <button className="btn-link" onClick={() => setAll(false)}>Tout absent</button>
            </div>
          )}
        </div>

        {error && <div className="alert alert-error mt">{error}</div>}

        <input className="input mt" type="search" placeholder="Rechercher un fidèle…" value={query} onChange={(e) => setQuery(e.target.value)} />
        {hidden > 0 && !q && (
          <p className="helper">{hidden} fidèle(s) inactif(s) masqué(s). Quelqu'un revient ? Tapez son nom pour l'ajouter : il redeviendra actif.</p>
        )}
        {canRecord && comebacks.length > 0 && (
          <div className="comeback-list">
            <span className="mini-label">Fidèles inactifs</span>
            {comebacks.map((m) => (
              <div key={m.user_id} className="comeback-row">
                <span className="member-main">
                  <span className="member-name">{m.full_name}</span>
                  {m.tribe && <span className="member-meta">{m.tribe}</span>}
                </span>
                <button className="btn btn-primary small" onClick={() => addBack(m)}>+ Marquer présent</button>
              </div>
            ))}
          </div>
        )}

        <div className="attendance-list mt">
          {shown.map((m) => (
            kind === 'repetition' ? (
              <div key={m.user_id} className="attendance-row reh-row">
                {m.photo_url
                  ? <img className="member-avatar" src={m.photo_url} alt="" />
                  : <span className="member-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
                <span className="member-main">
                  <span className="member-name">{m.full_name}</span>
                  {m.tribe && <span className="member-meta">{m.tribe}</span>}
                </span>
                <span className="reh-status-btns">
                  {REH_STATUS.map((s) => (
                    <button key={s.key} type="button" disabled={!canRecord}
                      className={`reh-status reh-${s.key} ${m.status === s.key ? 'on' : ''}`}
                      onClick={() => canRecord && setStatus(m.user_id, s.key)}>{s.label}</button>
                  ))}
                </span>
              </div>
            ) : (
              <button key={m.user_id} className={`attendance-row ${m.present ? 'present' : ''}`}
                disabled={!canRecord} onClick={() => canRecord && toggle(m.user_id)}>
                <span className={`check-box ${m.present ? 'on' : ''}`}>{m.present ? '✓' : ''}</span>
                {m.photo_url
                  ? <img className="member-avatar" src={m.photo_url} alt="" />
                  : <span className="member-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
                <span className="member-main">
                  <span className="member-name">{m.full_name}</span>
                  {m.tribe && <span className="member-meta">{m.tribe}</span>}
                </span>
              </button>
            )
          ))}
          {!loading && members.length === 0 && <p className="helper center">Aucun fidèle actif dans votre périmètre.</p>}
          {!loading && members.length > 0 && shown.length === 0 && comebacks.length === 0 && <p className="helper center">Aucun fidèle ne correspond à « {query} ».</p>}
        </div>

        {canRecord && members.length > 0 && (
          <button className="btn btn-primary mt" disabled={saving} onClick={save}>
            {saving ? <span className="spinner" /> : 'Enregistrer la session'}
          </button>
        )}
      </section>
    </AppLayout>
  )
}
