import type { GemWeekReport } from '../../types'
import { fmtDay } from '../../utils/leaderReports'

/** Lecture d'un rapport hebdomadaire de GEM : presences de chaque membre et mot du Garde. */
export function WeekReportView({ report }: { report: GemWeekReport }) {
  return (
    <div className="gr-view">
      <div className="kpi-mini-row gr-kpis">
        <div className="kpi-mini"><strong>{report.culte_count}/{report.members_count}</strong><span>au culte du dimanche</span></div>
        <div className="kpi-mini">
          <strong>{report.meeting_held ? `${report.meeting_count}/${report.members_count}` : '—'}</strong>
          <span>{report.meeting_held ? 'à la rencontre du GEM' : 'pas de rencontre du GEM'}</span>
        </div>
      </div>
      <div className="table-scroll">
        <table className="gr-table gr-read">
          <thead>
            <tr><th scope="col">Membre</th><th scope="col">Culte</th>{report.meeting_held && <th scope="col">Rencontre</th>}</tr>
          </thead>
          <tbody>
            {report.attendance.map((r) => (
              <tr key={r.user_id}>
                <th scope="row">{r.name}</th>
                <td className={r.culte ? 'gr-yes' : 'gr-no'}>{r.culte ? 'Présent' : 'Absent'}</td>
                {report.meeting_held && <td className={r.rencontre ? 'gr-yes' : 'gr-no'}>{r.rencontre ? 'Présent' : 'Absent'}</td>}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {report.comment && <blockquote className="validation-reason gr-comment">{report.comment}</blockquote>}
      {report.submitted_at && <p className="helper">Envoyé le {fmtDay(report.submitted_at)}.</p>}
    </div>
  )
}
