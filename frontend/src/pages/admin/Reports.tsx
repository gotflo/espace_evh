import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { ColumnChart, HBarChart, LineChart, Meter, VIZ } from '../../components/charts'
import { toast } from '../../toast'
import type { ReportData, ReportMemberRow, ReportOptions } from '../../types'

interface MembersPage { members: ReportMemberRow[]; total: number; has_more: boolean }

type Tab = 'overview' | 'members'
type Filter = 'all' | 'active' | 'inactive' | 'incomplete' | 'fiss_missing'
const FILTERS: { key: Filter; label: string }[] = [
  { key: 'all', label: 'Tous' },
  { key: 'active', label: 'Actifs' },
  { key: 'inactive', label: 'Inactifs' },
  { key: 'incomplete', label: 'Profil incomplet' },
  { key: 'fiss_missing', label: 'FISS non remplie' },
]

const pct = (v: number | null) => (v === null ? '—' : `${Math.round(v)} %`)

function Kpi({ label, value, sub, tone }: { label: string; value: string; sub?: string; tone?: 'good' | 'warn' }) {
  return (
    <div className={`kpi ${tone ? `kpi-${tone}` : ''}`}>
      <span className="kpi-value">{value}</span>
      <span className="kpi-label">{label}</span>
      {sub && <span className="kpi-sub">{sub}</span>}
    </div>
  )
}

/** Donnees d'un graphique en tableau (accessibilite et lecture detaillee). */
function DataTable({ headers, rows }: { headers: string[]; rows: (string | number)[][] }) {
  return (
    <details className="chart-data">
      <summary>Voir les données</summary>
      <div className="table-scroll">
        <table>
          <thead><tr>{headers.map((h) => <th key={h}>{h}</th>)}</tr></thead>
          <tbody>{rows.map((r, i) => <tr key={i}>{r.map((c, j) => <td key={j}>{c}</td>)}</tr>)}</tbody>
        </table>
      </div>
    </details>
  )
}

function People({ title, people, empty, extra }: { title: string; people: { user_id: number; name: string; tribe: string | null }[]; empty: string; extra?: (p: never) => string | undefined }) {
  const navigate = useNavigate()
  return (
    <section className="panel">
      <div className="panel-head"><h3>{title} <span className="count-soft">{people.length}</span></h3></div>
      {people.length === 0 ? <p className="helper">{empty}</p> : (
        <ul className="people-list">
          {people.slice(0, 30).map((p) => (
            <li key={p.user_id}>
              <button className="btn-link" onClick={() => navigate(`/admin/membres/${p.user_id}`)}>{p.name}</button>
              <small>{[p.tribe, extra?.(p as never)].filter(Boolean).join(' · ')}</small>
            </li>
          ))}
          {people.length > 30 && <li className="helper">+ {people.length - 30} autre(s) — voir l'onglet Membres.</li>}
        </ul>
      )}
    </section>
  )
}

export default function Reports() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [options, setOptions] = useState<ReportOptions | null>(null)
  const [scope, setScope] = useState(params.get('scope') ?? '')
  const [months, setMonths] = useState(Number(params.get('mois')) || 6)
  const [tab, setTab] = useState<Tab>((params.get('vue') as Tab) || 'overview')
  const [report, setReport] = useState<ReportData | null>(null)
  const [loading, setLoading] = useState(false)
  const [filter, setFilter] = useState<Filter>('all')
  const [rows, setRows] = useState<ReportMemberRow[] | null>(null)
  const [total, setTotal] = useState(0)
  const [page, setPage] = useState(1)
  const [hasMore, setHasMore] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [query, setQuery] = useState('')
  const [exporting, setExporting] = useState(false)

  useEffect(() => {
    api<ReportOptions>('/admin/reports/options').then((o) => {
      setOptions(o)
      setScope((s) => s || (o.church ? 'church' : o.mine ? 'mine' : o.tribes[0] ? `tribe:${o.tribes[0].id}` : ''))
    }).catch(() => setOptions({ church: false, mine: false, tribes: [] }))
  }, [])

  useEffect(() => {
    if (scope) setParams({ scope, mois: String(months), vue: tab }, { replace: true })
  }, [scope, months, tab, setParams])

  const loadReport = useCallback(() => {
    if (!scope) return
    setLoading(true)
    api<ReportData>(`/admin/reports?scope=${encodeURIComponent(scope)}&months=${months}`)
      .then(setReport).catch(() => setReport(null)).finally(() => setLoading(false))
  }, [scope, months])
  useEffect(() => { loadReport() }, [loadReport])

  // Liste affichee par pages de 60 (recherche cote serveur) ; liste complete seulement pour le PDF.
  const membersUrl = useCallback((p: number, all = false) =>
    `/admin/reports/members?scope=${encodeURIComponent(scope)}&filter=${filter}&q=${encodeURIComponent(query.trim())}${all ? '&all=1' : `&page=${p}`}`,
  [scope, filter, query])
  useEffect(() => {
    if (!scope || tab !== 'members') return
    const t = window.setTimeout(() => {
      setRows(null)
      api<MembersPage>(membersUrl(1))
        .then((r) => { setRows(r.members); setTotal(r.total); setHasMore(r.has_more); setPage(1) })
        .catch(() => { setRows([]); setTotal(0); setHasMore(false) })
    }, 250)
    return () => window.clearTimeout(t)
  }, [scope, tab, membersUrl])

  function moreMembers() {
    setLoadingMore(true)
    api<MembersPage>(membersUrl(page + 1))
      .then((r) => { setRows((prev) => [...(prev ?? []), ...r.members]); setHasMore(r.has_more); setPage(page + 1) })
      .catch(() => {})
      .finally(() => setLoadingMore(false))
  }
  const shown = rows ?? []

  async function exportReport() {
    if (!report) return
    setExporting(true)
    try {
      const { downloadReportPdf } = await import('../../reports/pdf')
      await downloadReportPdf(report)
      api('/admin/reports/exported', { method: 'POST', body: { scope, kind: 'report' }, toast: false }).catch(() => {})
      toast.success('Le rapport PDF a été téléchargé.', { title: 'PDF prêt' })
    } catch { toast.error('La génération du PDF a échoué. Réessayez.') } finally { setExporting(false) }
  }

  async function exportMembers() {
    if (!rows) return
    setExporting(true)
    try {
      const [{ downloadMembersPdf }, all] = await Promise.all([import('../../reports/pdf'), api<MembersPage>(membersUrl(1, true))])
      await downloadMembersPdf(report?.scope.label ?? '', FILTERS.find((f) => f.key === filter)?.label ?? '', all.members)
      api('/admin/reports/exported', { method: 'POST', body: { scope, kind: 'members' }, toast: false }).catch(() => {})
      toast.success('La liste PDF a été téléchargée.', { title: 'PDF prêt' })
    } catch { toast.error('La génération du PDF a échoué. Réessayez.') } finally { setExporting(false) }
  }

  if (options && !options.church && options.tribes.length === 0) {
    return (
      <AppLayout title="Rapports">
        <div className="empty-state"><span aria-hidden>📊</span><h3>Aucune tribu dans votre périmètre</h3><p>Les rapports sont disponibles pour les tribus qui vous sont assignées.</p></div>
      </AppLayout>
    )
  }

  const k = report?.kpis
  const labels = report?.monthly.map((m) => m.label) ?? []

  return (
    <AppLayout title="Rapports" subtitle={report ? `${report.scope.label} · ${months} derniers mois` : 'Statistiques et vie spirituelle'}>
      <div className="report-toolbar">
        <select className="select" value={scope} onChange={(e) => setScope(e.target.value)} aria-label="Portée du rapport">
          {options?.church && <option value="church">Toute l'église</option>}
          {options?.mine && <option value="mine">Mes tribus</option>}
          {options?.tribes.map((t) => <option key={t.id} value={`tribe:${t.id}`}>Tribu {t.name}</option>)}
        </select>
        <div className="seg small" role="radiogroup" aria-label="Période">
          {[3, 6, 12].map((m) => <button key={m} role="radio" aria-checked={months === m} className={months === m ? 'on' : ''} onClick={() => setMonths(m)}>{m} mois</button>)}
        </div>
        <div className="tabs small">
          <button className={`tab ${tab === 'overview' ? 'active' : ''}`} onClick={() => setTab('overview')}>Vue d'ensemble</button>
          <button className={`tab ${tab === 'members' ? 'active' : ''}`} onClick={() => setTab('members')}>Membres</button>
        </div>
        <button className="btn btn-primary small report-pdf" disabled={exporting || (tab === 'overview' ? !report : !rows)}
          onClick={tab === 'overview' ? exportReport : exportMembers}>
          {exporting ? <span className="spinner" /> : '⬇ PDF'}
        </button>
      </div>

      {tab === 'overview' && (
        !report || loading && !report ? (
          <div className="kpi-grid">{Array.from({ length: 8 }, (_, i) => <div key={i} className="skeleton skeleton-tile" />)}</div>
        ) : (
          <div className={loading ? 'is-refreshing' : ''}>
            <div className="kpi-grid">
              <Kpi label="Membres" value={String(k!.members)} sub={`${k!.active} actifs · ${k!.inactive} inactifs`} />
              <Kpi label="Vie spirituelle" value={pct(k!.spiritual_score)} sub="moyenne des FISS remplies" tone={k!.spiritual_score !== null && k!.spiritual_score >= 60 ? 'good' : undefined} />
              <Kpi label="FISS du mois" value={pct(k!.fiss_rate)} sub={`${k!.fiss_missing} actif(s) sans fiche`} tone={k!.fiss_missing > 0 ? 'warn' : 'good'} />
              <Kpi label="Assiduité" value={pct(k!.attendance_rate)} sub="présences aux cultes" />
              <Kpi label="Vertumètre" value={k!.vertumetre !== null ? `${k!.vertumetre.toFixed(1).replace('.', ',')}/20` : '—'} sub="moyenne des notes" />
              <Kpi label="Nouveaux membres" value={String(k!.new_members)} sub={`sur ${months} mois`} />
              <Kpi label="Profils complétés" value={k!.profile_completion_avg !== null ? `${k!.profile_completion_avg} %` : '—'} sub={`${k!.incomplete_profiles} incomplet(s)`} tone={k!.incomplete_profiles > 0 ? 'warn' : undefined} />
              <Kpi label="Événements" value={String(k!.events)} sub={`${k!.participations} participation(s)`} />
            </div>

            <section className="panel mt">
              <div className="panel-head"><h3>Membres actifs</h3></div>
              <Meter value={k!.active} total={k!.members} label="des membres sont actifs" />
            </section>

            <div className="report-charts">
              <section className="panel">
                <div className="panel-head"><h3>Vie spirituelle et FISS</h3></div>
                <LineChart labels={labels} yMax={100} ariaLabel="Évolution du score de vie spirituelle et du taux de FISS"
                  series={[
                    { key: 'score', label: 'Score de vie spirituelle', color: VIZ.s1, values: report.monthly.map((m) => m.spiritual_score) },
                    { key: 'fiss', label: 'FISS remplies', color: VIZ.s2, values: report.monthly.map((m) => m.fiss_rate) },
                  ]} />
                <DataTable headers={['Mois', 'Score', 'FISS remplies', 'Taux']} rows={report.monthly.map((m) => [m.label, pct(m.spiritual_score), m.fiss_filled, pct(m.fiss_rate)])} />
              </section>
              <section className="panel">
                <div className="panel-head"><h3>Assiduité aux cultes</h3></div>
                <LineChart labels={labels} yMax={100} ariaLabel="Évolution de l'assiduité"
                  series={[{ key: 'att', label: 'Assiduité', color: VIZ.s3, values: report.monthly.map((m) => m.attendance_rate) }]} />
                <DataTable headers={['Mois', 'Sessions', 'Présences', 'Taux']} rows={report.monthly.map((m) => [m.label, m.attendance_sessions, m.attendance_present, pct(m.attendance_rate)])} />
              </section>
              <section className="panel">
                <div className="panel-head"><h3>Nouveaux membres</h3></div>
                <ColumnChart labels={labels} values={report.monthly.map((m) => m.new_members)} ariaLabel="Nouveaux membres par mois" />
                <DataTable headers={['Mois', 'Nouveaux', 'Membres']} rows={report.monthly.map((m) => [m.label, m.new_members, m.members])} />
              </section>
              <section className="panel">
                <div className="panel-head"><h3>Vertumètre et activités</h3></div>
                <LineChart labels={labels} unit="" yMax={20} ariaLabel="Moyenne du Vertumètre par mois"
                  series={[{ key: 'v', label: 'Vertumètre /20', color: VIZ.s1, values: report.monthly.map((m) => m.vertumetre) }]} />
                <DataTable headers={['Mois', 'Vertumètre', 'Événements', 'Participations']} rows={report.monthly.map((m) => [m.label, m.vertumetre ?? '—', m.events, m.participations])} />
              </section>
            </div>

            {report.tribes.length > 0 && (
              <section className="panel mt">
                <div className="panel-head">
                  <h3>Vie spirituelle par tribu</h3>
                  <span className="helper">Trait : mois précédent</span>
                </div>
                <HBarChart ariaLabel="Score de vie spirituelle par tribu"
                  rows={report.tribes.filter((t) => t.members > 0).sort((a, b) => (b.spiritual_score ?? -1) - (a.spiritual_score ?? -1)).map((t) => ({
                    label: t.name, value: t.spiritual_score, previous: t.spiritual_score_previous, note: t.fiss_count ? `(${t.fiss_count})` : '(aucune fiche)',
                  }))} />
                <div className="tribe-cards">
                  {report.tribes.filter((t) => t.members > 0).map((t) => (
                    <button key={t.id} className="tribe-card" onClick={() => setScope(`tribe:${t.id}`)}>
                      <strong>{t.name}</strong>
                      <span>{t.members} membres · {t.active} actifs</span>
                      <span>FISS {pct(t.fiss_rate)} · profils {t.completion_avg ?? '—'} %</span>
                    </button>
                  ))}
                </div>
              </section>
            )}

            <div className="report-lists mt">
              <People title="FISS non remplie ce mois-ci" people={report.missing_fiss} empty="Tous les membres actifs ont rempli leur fiche. 🎉" />
              <People title="Membres inactifs" people={report.inactive_members} empty="Aucun membre inactif." extra={(p: { since?: string | null }) => (p.since ? `depuis le ${p.since}` : undefined)} />
              <People title="Nouveaux membres" people={report.new_members_list} empty="Aucun nouveau membre sur la période." extra={(p: { date?: string }) => p.date} />
            </div>
            <p className="helper mt">Données au {new Date(report.generated_at).toLocaleString('fr-CA')} (actualisées toutes les 10 minutes). Les membres sans fiche ne sont pas comptés comme 0 dans les moyennes.</p>
          </div>
        )
      )}

      {tab === 'members' && (
        <>
          <div className="members-filters">
            <div className="filter-chips" role="radiogroup" aria-label="Filtre">
              {FILTERS.map((f) => (
                <button key={f.key} role="radio" aria-checked={filter === f.key} className={`chip-toggle ${filter === f.key ? 'on' : ''}`} onClick={() => setFilter(f.key)}>{f.label}</button>
              ))}
            </div>
            <input className="input" type="search" placeholder="Rechercher…" value={query} onChange={(e) => setQuery(e.target.value)} />
          </div>
          {rows === null ? (
            <div className="member-cards">{[0, 1, 2, 3].map((i) => <div key={i} className="skeleton skeleton-row" />)}</div>
          ) : shown.length === 0 ? (
            <div className="empty-state compact"><span aria-hidden>👥</span><p>Aucun membre pour ce filtre.</p></div>
          ) : (
            <div className="member-cards">
              <p className="helper">{total > shown.length ? `${shown.length} sur ${total} membres` : `${total} membre(s)`}</p>
              {shown.map((r) => (
                <button key={r.user_id} className="member-card" onClick={() => navigate(`/admin/membres/${r.user_id}`)}>
                  <div className="member-card-head">
                    <strong>{r.name}</strong>
                    <span className={`status-dot ${r.status}`}>{r.status === 'active' ? 'Actif' : 'Inactif'}</span>
                  </div>
                  <div className="member-card-meta">{[r.tribe, r.gem, r.phone].filter(Boolean).join(' · ')}</div>
                  <div className="member-card-stats">
                    <span title="Complétion du profil">👤 {r.completion} %</span>
                    <span title="Dernière FISS" className={r.fiss_current ? '' : 'warn'}>🩺 {r.fiss_current ? `ce mois ${pct(r.fiss_score)}` : r.last_fiss ? `dernière : ${r.last_fiss}` : 'aucune'}</span>
                    <span title="Présences sur 3 mois">⛪ {r.attendance_3m}</span>
                    <span title="Vertumètre">⭐ {r.vertumetre ?? '—'}</span>
                    <span title="Dernière connexion">🕒 {r.last_seen ? new Date(r.last_seen).toLocaleDateString('fr-CA') : 'jamais'}</span>
                  </div>
                </button>
              ))}
              {hasMore && (
                <div className="center member-more">
                  <button className="btn btn-ghost" disabled={loadingMore} onClick={moreMembers}>
                    {loadingMore ? <span className="spinner" /> : `Afficher plus (${total - shown.length} restant(s))`}
                  </button>
                </div>
              )}
            </div>
          )}
        </>
      )}
    </AppLayout>
  )
}
