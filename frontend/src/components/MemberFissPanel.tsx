import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import { FissDetail } from './FissDetail'
import { FissEvolution } from './FissEvolution'
import { LoadError } from './LoadError'
import { compareFiss, TREND_ARROW, TREND_LABEL } from '../utils/fiss'
import type { FissForm } from '../types'

export function MemberFissPanel({ userId }: { userId: string }) {
  const [forms, setForms] = useState<FissForm[] | null>(null)

  const [loadError, setLoadError] = useState('')

  const load = useCallback(() => {
    setLoadError('')
    api<{ forms: FissForm[] }>(`/admin/members/${userId}/fiss`).then((r) => setForms(r.forms)).catch((err) => {
      // Fiches reservees a d'autres responsables : le bloc reste simplement absent.
      if (err instanceof ApiError && err.status === 403) setForms([])
      else setLoadError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    })
  }, [userId])
  useEffect(() => { load() }, [load])

  if (!forms && loadError) {
    return (
      <section className="panel mt">
        <div className="panel-head"><h3>Fiches de santé spirituelle (FISS)</h3></div>
        <LoadError compact message={loadError} onRetry={load} />
      </section>
    )
  }
  if (!forms || forms.length === 0) return null

  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Fiches de santé spirituelle (FISS)</h3></div>
      <div className="fiss-admin-list">
        {forms.map((f, i) => {
          const cmp = compareFiss(f, forms[i + 1])
          return (
            <details key={f.id} className="fiss-admin-item">
              <summary>
                <span className="fiss-hist-period">{f.period_label}</span>
                <span className="fiss-hist-scores">Spirituelle {f.vie_spirituelle_total}/60 · Sociale {f.vie_sociale_total}</span>
                {cmp && (
                  <span className={`fiss-trend fiss-trend-${cmp.overall}`}>
                    {TREND_ARROW[cmp.overall]} {TREND_LABEL[cmp.overall]}
                  </span>
                )}
              </summary>
              <div className="fiss-admin-detail">
                <FissDetail form={f} />
                {forms[i + 1] && (
                  <FissEvolution current={f} previous={forms[i + 1]} previousLabel={forms[i + 1].period_label} />
                )}
              </div>
            </details>
          )
        })}
      </div>
    </section>
  )
}
