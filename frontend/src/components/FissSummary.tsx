import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import type { FissData, FissForm } from '../types'

function sanctDot(v: string | null) {
  const cls = v === 'bien' ? 'bien' : v === 'moyen' ? 'moyen' : v === 'mal' ? 'mal' : 'none'
  return <span className={`sanct-dot sanct-${cls}`} />
}

/** Compare deux fiches : direction globale (hausse/baisse/stable) + ecart. */
function trend(cur: FissForm, prev?: FissForm) {
  if (!prev) return null
  const c = (cur.vie_spirituelle_total ?? 0) + (cur.vie_sociale_total ?? 0)
  const p = (prev.vie_spirituelle_total ?? 0) + (prev.vie_sociale_total ?? 0)
  const diff = c - p
  return { diff, dir: diff > 0 ? 'up' : diff < 0 ? 'down' : 'stable' as const }
}

export function FissSummary() {
  const [d, setD] = useState<FissData | null>(null)
  const navigate = useNavigate()

  useEffect(() => { api<FissData>('/me/fiss').then(setD).catch(() => setD(null)) }, [])

  if (!d || !d.filled || !d.current) return null
  const f = d.current
  const t = trend(f, d.history[0])

  return (
    <button className="panel fiss-summary" onClick={() => navigate('/ma-fiche')}>
      <div className="panel-head">
        <h3>Ma fiche spirituelle <span className="fiss-summary-period">{f.period_label}</span></h3>
        {t && (
          <span className={`fiss-trend fiss-trend-${t.dir}`}>
            {t.dir === 'up' ? '↑ En hausse' : t.dir === 'down' ? '↓ En baisse' : '→ Stable'}
            {t.diff !== 0 && <b> {t.diff > 0 ? '+' : ''}{t.diff}</b>}
          </span>
        )}
      </div>
      <div className="fiss-summary-body">
        <div className="fiss-summary-metric">
          <span className="fiss-summary-value">{f.vie_spirituelle_total}<small>/60</small></span>
          <span className="fiss-summary-label">Vie spirituelle</span>
        </div>
        <div className="fiss-summary-metric">
          <span className="fiss-summary-value">{f.vie_sociale_total}<small>{f.situation_conjugale != null ? '/60' : '/40'}</small></span>
          <span className="fiss-summary-label">Vie sociale</span>
        </div>
        <div className="fiss-summary-detail">
          <span className="fiss-summary-line">Meditation <b>{f.meditation ?? '-'}</b> · Priere <b>{f.priere ?? '-'}</b> · Jeune <b>{f.jeune ?? '-'}</b></span>
          <span className="fiss-summary-line">Financiere <b>{f.situation_financiere ?? '-'}</b> · Familiale <b>{f.situation_familiale ?? '-'}</b>{f.situation_conjugale != null ? <> · Conjugale <b>{f.situation_conjugale}</b></> : null}</span>
          <span className="fiss-summary-sanct">Sanctification {sanctDot(f.sanctification_corps)}{sanctDot(f.sanctification_ame)}{sanctDot(f.sanctification_esprit)}</span>
        </div>
      </div>
    </button>
  )
}
