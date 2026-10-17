import { Icon } from './Icon'

/**
 * Chargement impossible : jamais confondu avec « rien a faire » ni laisse en squelette sans fin.
 * Message clair et bouton « Réessayer » qui relance la lecture.
 */
export function LoadError({ message, onRetry, compact = false }: {
  message?: string
  onRetry: () => void
  compact?: boolean
}) {
  return (
    <div className={`empty-state load-error${compact ? ' compact' : ''}`} role="alert">
      <Icon name="alert" size={28} />
      <h3>Chargement impossible</h3>
      <p>{message || 'Vérifiez votre connexion puis réessayez.'}</p>
      <button type="button" className="btn btn-ghost small mt-sm" onClick={onRetry}>Réessayer</button>
    </div>
  )
}
