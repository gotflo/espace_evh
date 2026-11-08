import { useCallback, useEffect, useMemo, useState } from 'react'
import { api, ApiError } from '../../api/client'
import { getReference } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import type { AttendanceMember, AttendanceStatus, Department, RosterData } from '../../types'
import { ymd } from '../../utils/events'
import { confirmDiscard, useDirty } from '../../utils/dirty'
import { useLatestRequest } from '../../utils/latestRequest'
import { LoadError } from '../../components/LoadError'

// Date locale (et non UTC : le soir, toISOString donnerait deja le lendemain), calculee a chaque
// usage : un ecran ouvert avant minuit propose bien le jour suivant.
const today = () => ymd(new Date())
const EVENTS = ['Culte', 'Culte du dimanche', 'Réunion de prière', 'Étude biblique', 'Répétition', 'Autre']
const REH_STATUS: { key: AttendanceStatus; label: string }[] = [
  { key: 'present', label: 'Présent' },
  { key: 'retard', label: 'Retard' },
  { key: 'absent_justifie', label: 'Excusé' },
  { key: 'absent', label: 'Absent' },
]

export default function Attendance() {
  const { hasPermission, roles } = useAuth()
  const canRecord = hasPermission('attendance.record')
  const seesAll = hasPermission('members.view_all')

  // Departements : un responsable de plusieurs departements fait l'appel de chacun separement (ordre alphabetique).
  const myDepartments = useMemo(() => roles.filter((r) => r.scope_kind === 'department' && r.scope_id)
    .reduce<Department[]>((list, r) => (list.some((d) => d.id === r.scope_id) ? list : [...list, { id: r.scope_id!, name: r.scope_name ?? '' }]), [])
    .sort((a, b) => (a.name ?? '').localeCompare(b.name ?? '', 'fr')), [roles])
  // Sans departement choisi : sa tribu ou son GEM seulement (le serveur ne melange jamais ses departements).
  const baseGroups = useMemo(() => roles.filter((r) => (r.scope_kind === 'tribe' || r.scope_kind === 'gem') && r.scope_id && hasPermission('attendance.view'))
    .map((r) => (r.scope_kind === 'tribe' ? `Tribu ${r.scope_name ?? ''}` : `GEM ${r.scope_name ?? ''}`).trim())
    .filter((label, i, all) => all.indexOf(label) === i), [roles, hasPermission])
  const [allDepartments, setAllDepartments] = useState<Department[]>([])
  useEffect(() => { if (seesAll) getReference().then((r) => setAllDepartments(r.departments)).catch(() => {}) }, [seesAll])
  const departments = seesAll ? allDepartments : myDepartments
  // Responsable de departement(s) seulement : toujours une feuille par departement, le premier par defaut.
  const departmentOnly = !seesAll && baseGroups.length === 0 && myDepartments.length > 0

  const [date, setDate] = useState(today)
  const [event, setEvent] = useState('Culte')
  const [kind, setKind] = useState<'culte' | 'repetition'>('culte')
  const [departmentId, setDepartmentId] = useState(() => (departmentOnly ? String(myDepartments[0].id) : ''))
  useEffect(() => {
    if (departmentOnly && !departmentId) setDepartmentId(String(myDepartments[0].id))
  }, [departmentOnly, departmentId, myDepartments])
  const scopeQuery = departmentId ? `&department_id=${departmentId}` : ''
  const [members, setMembers] = useState<AttendanceMember[]>([])
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [error, setError] = useState('')
  const [saving, setSaving] = useState(false)
  const [hidden, setHidden] = useState(0)
  const [query, setQuery] = useState('')
  const [comebacks, setComebacks] = useState<AttendanceMember[]>([])
  const sheet = useDirty(members.map((m) => [m.user_id, m.present, m.status]))
  const resetSheet = sheet.reset
  const { begin: beginSheet } = useLatestRequest()
  const { begin: beginSearch } = useLatestRequest()

  // La feuille affichee est toujours celle de la date et du departement choisis : une reponse en retard
  // (autre date) ne la remplace jamais, sinon on enregistrerait les presences d'un autre jour.
  const load = useCallback(() => {
    const isLatest = beginSheet()
    setLoading(true); setLoadError('')
    api<RosterData>(`/admin/attendance?date=${date}&event=${encodeURIComponent(event)}&kind=${kind}${scopeQuery}`)
      .then((r) => {
        if (!isLatest()) return
        setMembers(r.members.map((m) => ({ ...m, status: m.status ?? (kind === 'repetition' ? 'absent' : null) })))
        setHidden(r.inactive_hidden ?? 0)
        resetSheet()
      })
      .catch((err) => { if (!isLatest()) return; setMembers([]); resetSheet(); setLoadError(err instanceof ApiError ? err.firstMessage : 'Erreur.') })
      .finally(() => { if (isLatest()) setLoading(false) })
  }, [date, event, kind, scopeQuery, resetSheet, beginSheet])
  useEffect(() => { load() }, [load])

  // Autre date, evenement, type ou departement : une feuille cochee mais non enregistree n'est
  // jamais perdue sans confirmation.
  const switchSheet = (apply: () => void) => { if (confirmDiscard(sheet.dirty)) apply() }

  // Recherche : filtre la feuille ET retrouve un fidele inactif qui revient (masque par defaut).
  useEffect(() => {
    const q = query.trim()
    const isLatest = beginSearch()
    if (q.length < 2 || hidden === 0) { setComebacks([]); return }
    const timer = window.setTimeout(() => {
      api<{ matches: AttendanceMember[] }>(`/admin/attendance?date=${date}&event=${encodeURIComponent(event)}&kind=${kind}${scopeQuery}&q=${encodeURIComponent(q)}`)
        .then((r) => { if (isLatest()) setComebacks(r.matches.filter((m) => !members.some((x) => x.user_id === m.user_id))) })
        .catch(() => { if (isLatest()) setComebacks([]) })
    }, 300)
    return () => window.clearTimeout(timer)
  }, [query, hidden, date, event, kind, scopeQuery, members, beginSearch])

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
      const department_id = departmentId ? Number(departmentId) : null
      const body = kind === 'repetition'
        ? { attended_on: date, event, kind, department_id, statuses: Object.fromEntries(members.map((m) => [m.user_id, m.status ?? 'absent'])) }
        : { attended_on: date, event, kind, department_id, present_user_ids: members.filter((m) => m.present).map((m) => m.user_id) }
      await api<{ message: string }>('/admin/attendance', { method: 'POST', body })
      resetSheet()
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
            <input aria-label="Date" className="input" type="date" max={today()} value={date} onChange={(e) => { const d = e.target.value; switchSheet(() => setDate(d)) }} />
          </div>
          <div className="field" style={{ marginBottom: 0 }}>
            <label>Événement</label>
            <select aria-label="Événement" className="select" value={event} onChange={(e) => { const ev = e.target.value; switchSheet(() => setEvent(ev)) }}>
              {EVENTS.map((ev) => <option key={ev} value={ev}>{ev}</option>)}
            </select>
          </div>
        </div>

        <div className="field mt" style={{ marginBottom: 0 }}>
          <label>Type de session</label>
          <div className="pill-choices">
            <button type="button" className={`pill-choice ${kind === 'culte' ? 'on' : ''}`} onClick={() => { if (kind !== 'culte') switchSheet(() => setKind('culte')) }}>Culte / activité</button>
            <button type="button" className={`pill-choice ${kind === 'repetition' ? 'on' : ''}`} onClick={() => {
              if (kind === 'repetition') return
              switchSheet(() => {
                setKind('repetition')
                if (event === 'Culte') setEvent('Répétition')
                // Une repetition est celle d'un departement : le premier de ses departements par defaut.
                if (!departmentId && !seesAll && myDepartments.length > 0) setDepartmentId(String(myDepartments[0].id))
              })
            }}>Répétition (retards)</button>
          </div>
        </div>

        {departments.length > 0 && (
          <div className="field mt" style={{ marginBottom: 0 }}>
            <label htmlFor="attendance-department">Département</label>
            <select id="attendance-department" className="select" value={departmentId} onChange={(e) => { const id = e.target.value; switchSheet(() => setDepartmentId(id)) }}>
              {!departmentOnly && <option value="">{seesAll ? 'Toute l’église' : (baseGroups.join(' · ') || 'Mon périmètre')}</option>}
              {departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}
            </select>
          </div>
        )}

        <div className="toolbar" style={{ marginTop: '1rem' }}>
          <p className="helper" style={{ margin: 0 }}>
            {loading ? 'Chargement...' : `${presentCount} / ${members.length} présent(s)`}
          </p>
          {canRecord && kind === 'culte' && members.length > 0 && (
            <div className="attendance-quick">
              <button className="btn-link" disabled={loading} onClick={() => setAll(true)}>Tout présent</button>
              <button className="btn-link" disabled={loading} onClick={() => setAll(false)}>Tout absent</button>
            </div>
          )}
        </div>

        {error && <div className="alert alert-error mt">{error}</div>}

        <input aria-label="Rechercher un fidèle" className="input mt" type="search" placeholder="Rechercher un fidèle…" value={query} onChange={(e) => setQuery(e.target.value)} />
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
                  ? <img className="member-avatar" src={m.photo_url} alt="" width={42} height={42} loading="lazy" decoding="async" />
                  : <span className="member-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
                <span className="member-main">
                  <span className="member-name">{m.full_name}</span>
                  {m.tribe && <span className="member-meta">{m.tribe}</span>}
                </span>
                <span className="reh-status-btns">
                  {REH_STATUS.map((s) => (
                    <button key={s.key} type="button" disabled={!canRecord || loading}
                      className={`reh-status reh-${s.key} ${m.status === s.key ? 'on' : ''}`}
                      onClick={() => canRecord && setStatus(m.user_id, s.key)}>{s.label}</button>
                  ))}
                </span>
              </div>
            ) : (
              <button key={m.user_id} className={`attendance-row ${m.present ? 'present' : ''}`}
                disabled={!canRecord || loading} onClick={() => canRecord && toggle(m.user_id)}>
                <span className={`check-box ${m.present ? 'on' : ''}`}>{m.present ? '✓' : ''}</span>
                {m.photo_url
                  ? <img className="member-avatar" src={m.photo_url} alt="" width={42} height={42} loading="lazy" decoding="async" />
                  : <span className="member-avatar">{(m.full_name[0] ?? '?').toUpperCase()}</span>}
                <span className="member-main">
                  <span className="member-name">{m.full_name}</span>
                  {m.tribe && <span className="member-meta">{m.tribe}</span>}
                </span>
              </button>
            )
          ))}
          {!loading && loadError && <LoadError message={loadError} onRetry={load} compact />}
          {!loading && !loadError && members.length === 0 && <p className="helper center">Aucun fidèle actif dans votre périmètre.</p>}
          {!loading && members.length > 0 && shown.length === 0 && comebacks.length === 0 && <p className="helper center">Aucun fidèle ne correspond à « {query} ».</p>}
        </div>

        {canRecord && members.length > 0 && (
          <button className="btn btn-primary mt" disabled={saving || loading || !sheet.dirty} onClick={save}>
            {saving ? <span className="spinner" /> : 'Enregistrer la session'}
          </button>
        )}
      </section>
    </AppLayout>
  )
}
