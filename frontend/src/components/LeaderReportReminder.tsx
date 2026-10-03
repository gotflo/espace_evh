import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { useAuth } from '../auth/AuthContext'
import { fillUrl, KIND_LABEL } from '../utils/leaderReports'
import type { GemReportsData, LeaderReportsData } from '../types'
import { Icon } from './Icon'

/** Bandeau du tableau de bord : rapport mensuel du mois ecoule pas encore envoye (patriarche, responsable de departement). */
export function LeaderReportReminder() {
  const { roles } = useAuth()
  const navigate = useNavigate()
  const concerned = roles.some((r) => (r.key === 'patriarche' && r.scope_kind === 'tribe') || r.key === 'department_leader')
  const [data, setData] = useState<LeaderReportsData | null>(null)

  useEffect(() => {
    if (concerned) api<LeaderReportsData>('/admin/leader-reports').then(setData).catch(() => setData(null))
  }, [concerned])

  if (!data) return null
  const due = data.mine.filter((s) => s.periods.some((p) => p.period === data.due_period && p.status !== 'submitted'))

  return (
    <>
      {due.map((s) => (
        <button key={`${s.kind}:${s.scope_id}`} className="fiss-reminder fiss-reminder-advance fiss-reminder-btn"
          onClick={() => navigate(fillUrl(s.kind, s.scope_id, data.due_period))}>
          <span className="fiss-reminder-icon"><Icon name="clipboard" size={26} /></span>
          <div style={{ flex: 1, textAlign: 'left' }}>
            <strong>Rapport de {data.due_label} à envoyer</strong>
            <small>{KIND_LABEL[s.kind]} {s.scope_name} · un questionnaire guidé, quelques minutes suffisent.</small>
          </div>
          <span className="fiss-reminder-cta">Remplir →</span>
        </button>
      ))}
    </>
  )
}

/** Bandeau du tableau de bord : rapport de la semaine pas encore envoye (Garde). */
export function GemReportReminder() {
  const { roles } = useAuth()
  const navigate = useNavigate()
  const isGarde = roles.some((r) => r.key === 'garde' && r.scope_kind === 'gem')
  const [data, setData] = useState<GemReportsData | null>(null)

  useEffect(() => {
    if (isGarde) api<GemReportsData>('/admin/gem-reports').then(setData).catch(() => setData(null))
  }, [isGarde])

  const due = data?.gems.filter((g) => !g.current && g.members.length > 0) ?? []
  if (!data || due.length === 0) return null

  return (
    <button className="fiss-reminder fiss-reminder-advance fiss-reminder-btn" onClick={() => navigate('/admin/rapport-gem')}>
      <span className="fiss-reminder-icon"><Icon name="gem" size={26} /></span>
      <div style={{ flex: 1, textAlign: 'left' }}>
        <strong>Rapport de la semaine à envoyer</strong>
        <small>{due.map((g) => g.name).join(', ')} · semaine {data.due_label} · présences au culte et à la rencontre.</small>
      </div>
      <span className="fiss-reminder-cta">Remplir →</span>
    </button>
  )
}
