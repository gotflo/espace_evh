import { useEffect, useState } from 'react'
import { api } from '../api/client'
import type { MyEvaluations } from '../types'

function Stars({ value }: { value: number }) {
  const full = Math.round(value)
  return (
    <span className="stars" aria-label={`${value} / 5`}>
      {[1, 2, 3, 4, 5].map((i) => <span key={i} className={i <= full ? 'star on' : 'star'}>★</span>)}
    </span>
  )
}

export function MyGrades() {
  const [data, setData] = useState<MyEvaluations | null>(null)

  useEffect(() => {
    api<MyEvaluations>('/me/evaluations').then(setData).catch(() => setData(null))
  }, [])

  if (!data || data.evaluations.length === 0) return null

  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Mes notes</h3></div>

      <div className="grades-top">
        <div className="grade-avg">
          <span className="grade-avg-value">{data.average?.toFixed(1)}</span>
          <span className="grade-avg-max">/ 20</span>
          <span className="grade-avg-label">Moyenne generale</span>
        </div>
        <div className="grade-bytype">
          {data.by_type.map((t) => (
            <div key={t.type} className="grade-type-chip">
              <span>{t.type_label}</span>
              <strong>{t.average.toFixed(1)}<small>/20</small></strong>
            </div>
          ))}
        </div>
      </div>

      <div className="grade-list">
        {data.evaluations.map((e) => (
          <div key={e.id} className="grade-row">
            <div className="grade-row-main">
              <span className="grade-type">{e.type_label}</span>
              {e.title && <span className="grade-title">{e.title}</span>}
              <span className="grade-date">{e.evaluated_on}</span>
            </div>
            <Stars value={e.stars} />
            <span className="grade-score">{e.score}<small>/{e.max_score}</small></span>
          </div>
        ))}
      </div>
    </section>
  )
}
