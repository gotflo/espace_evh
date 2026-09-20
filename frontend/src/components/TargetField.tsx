import { useAuth } from '../auth/AuthContext'
import type { Department, Tribe } from '../types'

type TType = 'all' | 'tribe' | 'department'

/**
 * Selecteur de destinataires. Pour un responsable restreint (pas view_all), il est
 * remplace par une note : la diffusion est automatiquement limitee a sa portee
 * (le backend force la portee, quel que soit ce qui est envoye).
 */
export function TargetField({ targetType, targetId, onType, onId, tribes, departments }: {
  targetType: TType
  targetId: string
  onType: (t: TType) => void
  onId: (id: string) => void
  tribes: Tribe[]
  departments: Department[]
}) {
  const { hasPermission } = useAuth()

  if (!hasPermission('members.view_all')) {
    return (
      <div className="field">
        <label>Destinataires</label>
        <div className="scope-note">📍 Limite automatiquement à votre périmètre (votre GEM / tribu / département).</div>
      </div>
    )
  }

  return (
    <div className="field-row">
      <div className="field" style={{ marginBottom: 0 }}>
        <label>Destinataires</label>
        <select className="select" value={targetType} onChange={(e) => { onType(e.target.value as TType); onId('') }}>
          <option value="all">Toute l'église</option>
          <option value="tribe">Une tribu</option>
          <option value="department">Un département</option>
        </select>
      </div>
      {targetType !== 'all' && (
        <div className="field" style={{ marginBottom: 0 }}>
          <label>{targetType === 'tribe' ? 'Tribu' : 'Département'}</label>
          <select className="select" value={targetId} onChange={(e) => onId(e.target.value)}>
            <option value="">Choisir...</option>
            {(targetType === 'tribe' ? tribes : departments).map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
          </select>
        </div>
      )}
    </div>
  )
}
