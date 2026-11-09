import { useNavigate } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { canValidateWith, useValidationsCount, validationsSummary } from '../utils/validationsCount'
import { Icon } from './Icon'

/**
 * Bandeau de l'accueil, bien visible : des demandes attendent la decision de ce responsable (inscriptions aux
 * services, nouveaux comptes, changements de tribu...). Disparait quand tout est traite.
 */
export function ValidationsReminder() {
  const { hasPermission } = useAuth()
  const navigate = useNavigate()
  const { count, by } = useValidationsCount(canValidateWith(hasPermission))
  if (count === 0) return null

  return (
    <button className="fiss-reminder fiss-reminder-urgent fiss-reminder-btn validations-reminder" onClick={() => navigate('/admin/validations')}>
      <span className="fiss-reminder-icon validations-reminder-icon">
        <Icon name="validate" size={24} />
        <span key={count} className="validations-reminder-count" aria-hidden>{count > 99 ? '99+' : count}</span>
      </span>
      <div style={{ flex: 1, minWidth: 0, textAlign: 'left' }}>
        <strong>{count > 1 ? `${count} demandes à valider` : '1 demande à valider'}</strong>
        <small>{validationsSummary({ count, by })}</small>
      </div>
      <span className="fiss-reminder-cta">Valider →</span>
    </button>
  )
}
