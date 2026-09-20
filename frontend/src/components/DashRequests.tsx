import { useCallback, useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import { useAutoRefresh } from '../hooks/useAutoRefresh'
import type { AdminRequest } from '../types'

/** Widget tableau de bord : dernieres demandes des fideles + compteur de nouvelles. */
export function DashRequests() {
  const navigate = useNavigate()
  const [data, setData] = useState<{ requests: AdminRequest[]; new_count: number } | null>(null)

  const load = useCallback(() => {
    api<{ requests: AdminRequest[]; new_count: number }>('/admin/requests')
      .then(setData).catch(() => setData(null))
  }, [])

  useEffect(() => { load() }, [load])
  useAutoRefresh(load)

  if (!data) return null

  return (
    <section className="panel">
      <div className="panel-head">
        <h3>Demandes {data.new_count > 0 && <span className="count-pill">{data.new_count}</span>}</h3>
        <button className="btn-link" onClick={() => navigate('/admin/demandes')}>Tout voir</button>
      </div>
      <div className="mini-list">
        {data.requests.slice(0, 4).map((r) => (
          <button key={r.id} className="mini-row" onClick={() => navigate('/admin/demandes')}>
            <span className={`req-dot req-${r.status}`} />
            <span className="dash-req-text">
              <strong>{r.sender}</strong>
              <small>{r.category_label}</small>
            </span>
            <span className={`req-status req-${r.status}`}>{r.status_label}</span>
          </button>
        ))}
        {data.requests.length === 0 && <p className="helper">Aucune demande pour le moment.</p>}
      </div>
    </section>
  )
}
