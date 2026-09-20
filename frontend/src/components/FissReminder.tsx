import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import type { FissData } from '../types'

/** Bandeau de rappel : la fiche de sante spirituelle du mois n'est pas remplie. */
export function FissReminder() {
  const [data, setData] = useState<FissData | null>(null)
  const navigate = useNavigate()

  useEffect(() => { api<FissData>('/me/fiss').then(setData).catch(() => setData(null)) }, [])

  if (!data || data.filled || data.reminder.level === 'none') return null
  const lvl = data.reminder.level

  return (
    <button className={`fiss-reminder fiss-reminder-${lvl} fiss-reminder-btn`} onClick={() => navigate('/ma-fiche')}>
      <span className="fiss-reminder-icon">{lvl === 'urgent' ? '⏰' : '📝'}</span>
      <div style={{ flex: 1, textAlign: 'left' }}>
        <strong>{lvl === 'urgent' ? 'Derniers jours : remplissez votre fiche !' : 'Fiche de sante spirituelle du mois'}</strong>
        <small>Votre fiche de {data.period_label} n'est pas remplie{data.reminder.days_left != null ? ` · ${data.reminder.days_left} j restants` : ''}. Cliquez pour la remplir.</small>
      </div>
      <span className="fiss-reminder-cta">Remplir →</span>
    </button>
  )
}
