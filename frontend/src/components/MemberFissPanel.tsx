import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { FissDetail } from './FissDetail'
import { FissEvolution } from './FissEvolution'
import { compareFiss, TREND_ARROW, TREND_LABEL } from '../utils/fiss'
import type { FissForm } from '../types'

export function MemberFissPanel({ userId }: { userId: string }) {
  const [forms, setForms] = useState<FissForm[] | null>(null)

  useEffect(() => {
    api<{ forms: FissForm[] }>(`/admin/members/${userId}/fiss`).then((r) => setForms(r.forms)).catch(() => setForms([]))
  }, [userId])

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
