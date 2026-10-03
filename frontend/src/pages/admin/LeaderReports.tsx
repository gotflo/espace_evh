import { useCallback, useEffect, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { api } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { Icon } from '../../components/Icon'
import { SkeletonCard } from '../../components/Skeleton'
import { ReportView } from '../../components/leaderReports/ReportView'
import { ReportWizard } from '../../components/leaderReports/ReportWizard'
import { usePulse } from '../../pulse'
import { toast } from '../../toast'
import { fmtDay, KIND_LABEL } from '../../utils/leaderReports'
import type { LeaderReportDetail, LeaderReportKind, LeaderReportMine, LeaderReportsData } from '../../types'

const STATUS: Record<string, { label: string; action: string }> = {
  missing: { label: 'À remplir', action: 'Remplir le rapport' },
  draft: { label: 'Brouillon en cours', action: 'Continuer' },
  submitted: { label: 'Envoyé', action: 'Voir le rapport' },
}

/** Rapport envoye (ou son propre brouillon), en lecture. */
function Detail({ id, onBack, onEdit }: { id: number; onBack: () => void; onEdit: (r: LeaderReportDetail) => void }) {
  const [report, setReport] = useState<LeaderReportDetail | null>(null)
  const [failed, setFailed] = useState(false)
  const [exporting, setExporting] = useState(false)

  async function exportPdf(r: LeaderReportDetail) {
    setExporting(true)
    try {
      const { downloadLeaderReportPdf } = await import('../../reports/pdf')
      await downloadLeaderReportPdf(r)
      api(`/admin/leader-reports/${r.id}/exported`, { method: 'POST', toast: false }).catch(() => {})
      toast.success('Le rapport PDF a été téléchargé.', { title: 'PDF prêt' })
    } catch { toast.error('La génération du PDF a échoué. Réessayez.') } finally { setExporting(false) }
  }

  useEffect(() => {
    setReport(null); setFailed(false)
    api<LeaderReportDetail>(`/admin/leader-reports/${id}`).then(setReport).catch(() => setFailed(true))
  }, [id])

  if (failed) {
    return (
      <div className="empty-state">
        <Icon name="alert" size={28} />
        <h3>Rapport introuvable</h3>
        <p>Il ne fait pas partie de votre périmètre, ou il n'existe plus.</p>
        <button className="btn btn-ghost small mt" onClick={onBack}>Retour aux rapports</button>
      </div>
    )
  }
  if (!report) return <div className="panel-grid"><SkeletonCard /><SkeletonCard /></div>

  return (
    <div className="lr-wizard">
      <div className="lr-wizard-head">
        <div>
          <p className="section-label">Rapport mensuel · {report.period_label}</p>
          <h2 className="lr-wizard-title">{KIND_LABEL[report.kind]} {report.scope_name}</h2>
          <p className="helper">
            {report.status === 'submitted'
              ? `Envoyé le ${fmtDay(report.submitted_at)}${report.author ? ` par ${report.author}` : ''}`
              : 'Brouillon, pas encore envoyé'}
          </p>
        </div>
        <div className="row-actions">
          {report.can_edit && <button className="btn btn-primary small" onClick={() => onEdit(report)}>{report.status === 'submitted' ? 'Corriger' : 'Continuer'}</button>}
          {report.status === 'submitted' && (
            <button className="btn btn-ghost small" disabled={exporting} onClick={() => exportPdf(report)}>
              {exporting ? <span className="spinner" /> : <><Icon name="download" size={16} /> PDF</>}
            </button>
          )}
          <button className="btn btn-ghost small" onClick={onBack}>Retour</button>
        </div>
      </div>
      <section className="panel">
        <ReportView steps={report.steps} answers={report.answers} souls={report.souls} />
      </section>
    </div>
  )
}

function MineCard({ scope, onOpen }: { scope: LeaderReportMine; onOpen: (period: string, reportId: number | null, sent: boolean) => void }) {
  return (
    <article className="validation-card">
      <div className="validation-head">
        <span className="validation-icon"><Icon name={scope.kind === 'tribe' ? 'members' : 'serve'} size={20} /></span>
        <div>
          <strong>{KIND_LABEL[scope.kind]} {scope.scope_name}</strong>
          <small>{scope.kind === 'tribe' ? 'Rapport du patriarche' : 'Rapport du responsable de département'}</small>
        </div>
      </div>
      {scope.periods.map((p) => (
        <div key={p.period} className="lr-period">
          <div>
            <span className="lr-period-name">{p.label}</span>
            <span className={`status-pill ${p.status === 'submitted' ? 'on' : p.status === 'missing' ? 'due' : ''}`}>{STATUS[p.status].label}</span>
          </div>
          <button className={`btn small ${p.status === 'submitted' ? 'btn-ghost' : 'btn-primary'}`}
            onClick={() => onOpen(p.period, p.report_id, p.status === 'submitted')}>{STATUS[p.status].action}</button>
        </div>
      ))}
    </article>
  )
}

export default function LeaderReports() {
  const [params, setParams] = useSearchParams()
  const [data, setData] = useState<LeaderReportsData | null>(null)
  const [denied, setDenied] = useState(false)
  const [period, setPeriod] = useState('')

  // ?remplir=tribe:3&mois=2026-09 : questionnaire ; ?rapport=12 : lecture.
  const fill = params.get('remplir')?.match(/^(tribe|department):(\d+)$/)
  const month = params.get('mois')
  const reading = Number(params.get('rapport')) || null
  const filling = fill && month && /^\d{4}-\d{2}$/.test(month)
    ? { kind: fill[1] as LeaderReportKind, scopeId: Number(fill[2]), period: month } : null

  const listing = !filling && !reading

  const load = useCallback(() => {
    api<LeaderReportsData>(period ? `/admin/leader-reports?period=${period}` : '/admin/leader-reports')
      .then((d) => { setData(d); setDenied(false) })
      .catch(() => setDenied(true))
  }, [period])
  useEffect(() => { if (listing) load() }, [load, listing])
  usePulse('leader_reports', load, listing)

  const openFill = (kind: LeaderReportKind, scopeId: number, p: string) => setParams({ remplir: `${kind}:${scopeId}`, mois: p })
  const openReport = (id: number) => setParams({ rapport: String(id) })
  const backToList = () => setParams({})

  if (filling) {
    return (
      <AppLayout title="Rapport mensuel">
        <ReportWizard key={`${filling.kind}:${filling.scopeId}:${filling.period}`} {...filling} onClose={backToList} onSent={openReport} />
      </AppLayout>
    )
  }
  if (reading) {
    return (
      <AppLayout title="Rapport mensuel">
        <Detail id={reading} onBack={backToList} onEdit={(r) => openFill(r.kind, r.scope_id, r.period)} />
      </AppLayout>
    )
  }

  const sentCount = data?.received.filter((r) => r.status === 'submitted').length ?? 0

  return (
    <AppLayout title="Rapports mensuels" subtitle="Tribus et départements">
      {denied ? (
        <div className="empty-state">
          <Icon name="clipboard" size={28} />
          <h3>Aucun rapport à votre charge</h3>
          <p>Les rapports mensuels sont remplis par les patriarches et les responsables de département.</p>
        </div>
      ) : data === null ? (
        <div className="validation-grid"><SkeletonCard /><SkeletonCard /></div>
      ) : (
        <>
          {data.mine.length > 0 && (
            <section className="mt-0">
              <h3 className="section-label">Mes rapports</h3>
              <div className="validation-grid">
                {data.mine.map((scope) => (
                  <MineCard key={`${scope.kind}:${scope.scope_id}`} scope={scope}
                    onOpen={(p, id, sent) => (sent && id ? openReport(id) : openFill(scope.kind, scope.scope_id, p))} />
                ))}
              </div>
            </section>
          )}

          {data.can_review && (
            <section className={data.mine.length > 0 ? 'mt' : 'mt-0'}>
              <div className="lr-received-head">
                <h3 className="section-label">Rapports reçus <span className="count-soft">{sentCount} sur {data.received.length}</span></h3>
                <select className="select" aria-label="Mois du rapport" value={data.period} onChange={(e) => setPeriod(e.target.value)}>
                  {data.periods.map((p) => <option key={p.period} value={p.period}>{p.label}</option>)}
                </select>
              </div>
              {data.received.length === 0 ? (
                <p className="helper">Aucune tribu ni aucun département dans votre périmètre.</p>
              ) : (
                <div className="panel lr-received">
                  {data.received.map((r) => (
                    <button key={`${r.kind}:${r.scope_id}`} className="lr-row" disabled={!r.report_id} onClick={() => r.report_id && openReport(r.report_id)}>
                      <span className="lr-row-main">
                        <strong>{KIND_LABEL[r.kind]} {r.scope_name}</strong>
                        <small>
                          {r.status === 'submitted'
                            ? `Envoyé le ${fmtDay(r.submitted_at)}${r.author ? ` par ${r.author}` : ''}`
                            : r.leaders.length ? `En attente · ${r.leaders.join(', ')}` : 'Aucun patriarche désigné'}
                        </small>
                      </span>
                      {r.status === 'submitted' && <span className="chip-soft">{r.souls_count} âme(s)</span>}
                      <span className={`status-pill ${r.status === 'submitted' ? 'on' : ''}`}>{r.status === 'submitted' ? 'Reçu' : 'Non reçu'}</span>
                    </button>
                  ))}
                </div>
              )}
            </section>
          )}
        </>
      )}
    </AppLayout>
  )
}
