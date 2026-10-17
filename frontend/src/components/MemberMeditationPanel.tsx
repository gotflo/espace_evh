import { useCallback, useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import { LoadError } from './LoadError'
import { MeditationSheetView } from './MeditationSheetView'
import { filledOn } from '../utils/meditation'
import type { MeditationSheet, MeditationSummary } from '../types'

type Opened = { sheet?: MeditationSheet; error?: string }

/**
 * Fiches de méditation d'un membre, en lecture seule (patriarche de sa tribu, pasteurs) : les dates,
 * puis le contenu d'une fiche lu seulement quand on l'ouvre.
 */
export function MemberMeditationPanel({ userId }: { userId: string }) {
  const [sheets, setSheets] = useState<MeditationSummary[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [opened, setOpened] = useState<Record<number, Opened>>({})

  const load = useCallback(() => {
    setError(null)
    api<{ sheets: MeditationSummary[] }>(`/admin/members/${userId}/meditations`)
      .then((r) => setSheets(r.sheets))
      .catch((err) => setError(err instanceof ApiError ? err.firstMessage : ''))
  }, [userId])
  useEffect(() => { load() }, [load])

  function read(id: number) {
    setOpened((o) => ({ ...o, [id]: {} }))
    api<{ sheet: MeditationSheet }>(`/admin/members/${userId}/meditations/${id}`)
      .then((r) => setOpened((o) => ({ ...o, [id]: { sheet: r.sheet } })))
      .catch((err) => setOpened((o) => ({ ...o, [id]: { error: err instanceof ApiError ? err.firstMessage : '' } })))
  }

  return (
    <section className="panel mt">
      <div className="panel-head"><h3>Fiches de méditation</h3></div>
      {sheets === null ? (
        error !== null ? <LoadError compact message={error} onRetry={load} /> : <div className="medit-loading"><span className="spinner" /></div>
      ) : sheets.length === 0 ? (
        <p className="helper" style={{ marginTop: 0 }}>Aucune fiche de méditation pour l’instant.</p>
      ) : (
        <div className="fiss-admin-list">
          {sheets.map((s) => {
            const o = opened[s.id]
            return (
              <details key={s.id} className="fiss-admin-item"
                onToggle={(e) => { if ((e.currentTarget as HTMLDetailsElement).open && (!o || o.error !== undefined)) read(s.id) }}>
                <summary><span className="medit-summary">{filledOn(s.submitted_at)}</span></summary>
                <div className="fiss-admin-detail">
                  {o?.sheet ? <MeditationSheetView sheet={o.sheet} />
                    : o?.error !== undefined ? <LoadError compact message={o.error} onRetry={() => read(s.id)} />
                      : <div className="medit-loading"><span className="spinner" /></div>}
                </div>
              </details>
            )
          })}
        </div>
      )}
    </section>
  )
}
