import { useEffect, useState } from 'react'
import { api } from '../api/client'
import type { MonthlyResolution, ResolutionKey, ResolutionOutcome, VertumetreForm, VertumetreSection } from '../types'
import { VertumetreAnswers } from './VertumetreAnswers'

const fmt = (iso?: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' }) : '')

interface RmData { resolutions: MonthlyResolution[]; fields: Record<ResolutionKey, string>; outcomes: Record<ResolutionOutcome, string>; current_period: string }
interface DsData {
  responses: {
    id: number; title: string | null; speaker: string | null; withdrawn: boolean; summary: string; actions: string; submitted_at: string | null
    // Suivi de lecture (memes regles que les exercices video) ; percent null = lien externe
    percent?: number | null; media_completed?: boolean; seek_count?: number; done?: boolean
  }[]
  published_count: number
}

/** Fiche d'un membre : ses résolutions du mois (RM) et ses devoirs (DS), meme confidentialite que la FISS. */
export function MemberGrowth({ userId }: { userId: string }) {
  const [rm, setRm] = useState<RmData | null>(null)
  const [ds, setDs] = useState<DsData | null>(null)
  const [vt, setVt] = useState<{ sections: VertumetreSection[]; forms: VertumetreForm[] } | null>(null)
  useEffect(() => {
    api<RmData>(`/admin/members/${userId}/resolutions`, { toast: false }).then(setRm).catch(() => setRm(null))
    api<DsData>(`/admin/members/${userId}/assignments`, { toast: false }).then(setDs).catch(() => setDs(null))
    api<{ sections: VertumetreSection[]; forms: VertumetreForm[] }>(`/admin/members/${userId}/vertumetre`, { toast: false }).then(setVt).catch(() => setVt(null))
  }, [userId])

  return (
    <>
      {vt && (
        <section className="panel mt" id="vertumetre">
          <div className="panel-head"><h3>Vertumètre</h3></div>
          {vt.forms.length === 0 && <p className="helper">Aucun Vertumètre rempli pour le moment.</p>}
          <div className="fiss-admin-list">
            {vt.forms.slice(0, 6).map((f, i) => (
              <details key={f.id} className="fiss-admin-item" open={i === 0}>
                <summary><span className="fiss-hist-period">{f.period_label.charAt(0).toUpperCase() + f.period_label.slice(1)}</span><span className="fiss-hist-scores">{f.edit_count ? `modifié ${f.edit_count} fois` : ''}</span></summary>
                <VertumetreAnswers sections={vt.sections} answers={f.answers} />
              </details>
            ))}
          </div>
        </section>
      )}
      {rm && (
        <section className="panel mt" id="resolutions">
          <div className="panel-head"><h3>Résolutions du mois (RM)</h3></div>
          {rm.resolutions.length === 0 && <p className="helper">Aucune résolution prise pour le moment.</p>}
          {rm.resolutions.slice(0, 6).map((r) => {
            const keys = (Object.keys(rm.fields) as ResolutionKey[]).filter((k) => r[k])
            return (
              <div key={r.id} className="mp-month">
                <div className="mp-month-head">
                  <strong>{r.period_label}{r.period === rm.current_period ? ' (en cours)' : ''}</strong>
                  <span className="helper" style={{ margin: 0 }}>
                    {r.count} résolution{r.count > 1 ? 's' : ''}{r.outcome ? ` · ${r.achieved} atteinte${r.achieved > 1 ? 's' : ''}` : r.period !== rm.current_period ? ' · bilan non fait' : ''}
                  </span>
                </div>
                <ul className="rm-list compact">
                  {keys.map((k) => (
                    <li key={k} className="rm-item">
                      <span className="rm-item-label">
                        {rm.fields[k]}
                        {r.renewed.includes(k) && <span className="rm-badge">renouvelée</span>}
                        {r.outcome?.[k] && <span className={`rm-outcome-tag rm-outcome-${r.outcome[k]}`}>{rm.outcomes[r.outcome[k]!]}</span>}
                      </span>
                      <p>{r[k]}</p>
                    </li>
                  ))}
                </ul>
              </div>
            )
          })}
        </section>
      )}
      {ds && (
        <section className="panel mt" id="devoirs">
          <div className="panel-head">
            <h3>Devoirs (DS)</h3>
            <span className="helper" style={{ margin: 0 }}>{ds.responses.filter((r) => !r.withdrawn && r.done).length}/{ds.published_count} fait{ds.published_count > 1 ? 's' : ''}</span>
          </div>
          {ds.responses.length === 0 && <p className="helper">Aucun devoir fait pour le moment.</p>}
          {ds.responses.map((r) => (
            <details key={r.id} className="fiss-admin-item">
              <summary>
                <span className="fiss-hist-period">{r.title ?? 'Devoir'}{r.withdrawn ? ' (retiré)' : ''}</span>
                <span className="fiss-hist-scores">
                  {fmt(r.submitted_at)}
                  {r.percent != null && !r.media_completed ? ` · lu à ${r.percent} %` : ''}
                  {r.seek_count ? ` · avancé ${r.seek_count} fois` : ''}
                </span>
                <span className={`gr-pill ${r.done ? 'ok' : ''}`}>{r.done ? 'Fait' : 'À terminer'}</span>
              </summary>
              <div className="gr-block"><span>Résumé</span><p>{r.summary}</p></div>
              <div className="gr-block"><span>Actions et résolutions</span><p>{r.actions}</p></div>
            </details>
          ))}
        </section>
      )}
    </>
  )
}
