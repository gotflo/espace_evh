import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { Icon } from '../../components/Icon'
import { SkeletonCard } from '../../components/Skeleton'
import { WeekReportView } from '../../components/leaderReports/WeekReportView'
import { usePulse } from '../../pulse'
import { fmtDay } from '../../utils/leaderReports'
import type { GardePage, LeaderGarde, LeaderPerson, LeadersData } from '../../types'

/** Comparaison sans accents ni majuscules (« bethel » trouve « Béthel »). */
const plain = (s: string) => s.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase()
const initials = (name: string) => name.split(/\s+/).slice(0, 2).map((w) => w[0] ?? '').join('').toUpperCase()

function Avatar({ person }: { person: { name: string; photo_url: string | null } }) {
  return person.photo_url
    ? <img className="mini-avatar" src={person.photo_url} alt="" />
    : <span className="mini-avatar">{initials(person.name)}</span>
}

/** Une personne : nom (vers sa fiche), telephone, appartenance. */
function PersonRow({ person, belonging }: { person: LeaderPerson; belonging: string[] }) {
  const navigate = useNavigate()
  return (
    <li className="ld-row">
      <Avatar person={person} />
      <div className="ld-row-main">
        <button className="btn-link strong" onClick={() => navigate(`/admin/membres/${person.user_id}`)}>{person.name}</button>
        <span className="chip-list">{belonging.map((b) => <span key={b} className="chip-soft">{b}</span>)}</span>
      </div>
      {person.phone && <a className="ld-phone" href={`tel:${person.phone}`} aria-label={`Appeler ${person.name}`}><Icon name="phone" size={16} /><span>{person.phone}</span></a>}
    </li>
  )
}

function Group({ title, count, empty, note, children }: { title: string; count: number; empty: string; note?: string; children: ReactNode }) {
  return (
    <section className="panel">
      <div className="panel-head"><h3>{title} <span className="count-soft">{count}</span></h3></div>
      {count === 0 ? <p className="helper">{empty}</p> : <ul className="ld-list">{children}</ul>}
      {note && <p className="helper ld-note">{note}</p>}
    </section>
  )
}

const pct = (v: number | null) => (v === null ? '—' : `${v} %`)

/** Fiche d'un Garde : son GEM, ses rapports de la semaine et son activite de suivi. */
function GardeDetail({ gemId, onBack }: { gemId: number; onBack: () => void }) {
  const navigate = useNavigate()
  const [page, setPage] = useState<GardePage | null>(null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(() => {
    api<GardePage>(`/admin/leaders/gems/${gemId}`).then((p) => { setPage(p); setFailed(false) }).catch(() => setFailed(true))
  }, [gemId])
  useEffect(() => { setPage(null); load() }, [load])
  usePulse('gem_reports', load)

  if (failed) {
    return (
      <div className="empty-state">
        <Icon name="alert" size={28} />
        <h3>GEM introuvable</h3>
        <p>Il ne fait pas partie de votre périmètre, ou il n'existe plus.</p>
        <button className="btn btn-ghost small mt" onClick={onBack}>Retour aux responsables</button>
      </div>
    )
  }
  if (!page) return <div className="panel-grid"><SkeletonCard /><SkeletonCard /></div>

  const i = page.indicators
  const sent = page.reports_count
  // FISS : visibles du patriarche, de l'AP et des pasteurs, jamais du Garde.
  const seesFiss = i.fiss_filled !== null
  return (
    <div className="ld-detail">
      <div className="lr-wizard-head">
        <div className="ld-hero">
          {page.garde && <Avatar person={page.garde} />}
          <div>
            <p className="section-label">{page.gem.name}{page.gem.tribe ? ` · Tribu ${page.gem.tribe}` : ''}</p>
            <h2 className="lr-wizard-title">{page.garde ? page.garde.name : 'Aucun Garde désigné'}</h2>
            {page.garde && (
              <p className="helper">
                Garde{page.garde.since ? ` depuis le ${fmtDay(page.garde.since)}` : ''}
                {page.garde.phone && <> · <a href={`tel:${page.garde.phone}`}>{page.garde.phone}</a></>}
              </p>
            )}
          </div>
        </div>
        <div className="row-actions">
          {page.garde && <button className="btn btn-ghost small" onClick={() => navigate(`/admin/membres/${page.garde!.user_id}`)}>Fiche du membre</button>}
          <button className="btn btn-ghost small" onClick={onBack}>Retour</button>
        </div>
      </div>

      <div className="kpi-grid">
        <div className={`kpi ${i.reports_expected > 0 && i.reports_sent === i.reports_expected ? 'kpi-good' : i.reports_expected > 0 ? 'kpi-warn' : ''}`}>
          <span className="kpi-value">{i.reports_sent}/{i.reports_expected}</span>
          <span className="kpi-label">Rapports hebdomadaires</span>
          <span className="kpi-sub">envoyés sur les semaines attendues</span>
        </div>
        <div className="kpi">
          <span className="kpi-value">{pct(i.culte_rate)}</span>
          <span className="kpi-label">Présence au culte</span>
          <span className="kpi-sub">selon ses rapports</span>
        </div>
        <div className="kpi">
          <span className="kpi-value">{i.meetings_held}/{sent}</span>
          <span className="kpi-label">Rencontres du GEM tenues</span>
          <span className="kpi-sub">présence : {pct(i.meeting_rate)}</span>
        </div>
        {i.fiss_filled !== null && (
          <div className="kpi">
            <span className="kpi-value">{i.fiss_filled}/{i.members}</span>
            <span className="kpi-label">FISS du mois</span>
            <span className="kpi-sub">mois précédent : {i.fiss_previous}/{i.members}</span>
          </div>
        )}
        <div className={`kpi ${i.active < i.members ? 'kpi-warn' : ''}`}>
          <span className="kpi-value">{i.members}</span>
          <span className="kpi-label">Membres du GEM</span>
          <span className="kpi-sub">{i.active} actif(s) · {i.members - i.active} inactif(s)</span>
        </div>
        <div className="kpi">
          <span className="kpi-value">{i.followups + i.evaluations + i.requests + i.welcomed}</span>
          <span className="kpi-label">Actions de suivi</span>
          <span className="kpi-sub">{i.activity_days} derniers jours</span>
        </div>
      </div>

      <div className="report-lists mt">
        <section className="panel">
          <div className="panel-head"><h3>Membres du GEM <span className="count-soft">{page.members.length}</span></h3></div>
          {page.members.length === 0 ? <p className="helper">Aucun membre rattaché à ce GEM.</p> : (
            <div className="table-scroll">
              <table className="gr-table gr-read">
                <thead><tr><th scope="col">Membre</th>{seesFiss && <th scope="col">FISS du mois</th>}<th scope="col">Culte</th><th scope="col">Rencontre</th></tr></thead>
                <tbody>
                  {page.members.map((m) => (
                    <tr key={m.user_id}>
                      <th scope="row">
                        <button className="btn-link" onClick={() => navigate(`/admin/membres/${m.user_id}`)}>{m.name}</button>
                        {m.is_garde && <span className="chip-soft ld-tag">Garde</span>}
                        {m.status === 'inactive' && <span className="chip-soft warn ld-tag">Inactif</span>}
                      </th>
                      {seesFiss && <td className={m.fiss_current ? 'gr-yes' : 'gr-no'}>{m.fiss_current ? 'Remplie' : 'Non remplie'}</td>}
                      <td>{m.culte}/{sent}</td>
                      <td>{m.rencontre}/{i.meetings_held}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
          {sent > 0 && <p className="helper">Présences comptées sur {sent} rapport(s) reçu(s).</p>}
        </section>

        <section className="panel">
          <div className="panel-head"><h3>Activité récente</h3><span className="count-soft">{i.activity_days} jours</span></div>
          <div className="chip-list ld-counts">
            <span className="chip-soft">{i.followups} suivi(s)</span>
            <span className="chip-soft">{i.attendance_sheets} appel(s)</span>
            <span className="chip-soft">{i.evaluations} note(s)</span>
            <span className="chip-soft">{i.requests} demande(s) traitée(s)</span>
            <span className="chip-soft">{i.welcomed} accueil(s)</span>
          </div>
          {page.timeline.length === 0 ? <p className="helper">Aucune action enregistrée sur cette période.</p> : (
            <ul className="people-list">
              {page.timeline.map((t, k) => <li key={k}><span>{t.label}</span><small>{fmtDay(t.at)}</small></li>)}
            </ul>
          )}
        </section>
      </div>

      <section className="panel mt">
        <div className="panel-head"><h3>Rapports hebdomadaires</h3></div>
        {page.weeks.length === 0 ? (
          <p className="helper">{page.garde ? "Aucun rapport n'est encore attendu de ce Garde." : 'Sans Garde, ce GEM ne peut pas envoyer de rapport.'}</p>
        ) : (
          <div className="fiss-admin-list">
            {page.weeks.map((w) => w.status === 'submitted' ? (
              <details key={w.week_start} className="fiss-admin-item">
                <summary>
                  <span className="fiss-hist-period">Semaine {w.label}</span>
                  <span className="fiss-hist-scores">Culte {w.culte_count}/{w.members_count}{w.meeting_held ? ` · Rencontre ${w.meeting_count}/${w.members_count}` : ' · Pas de rencontre'}</span>
                </summary>
                <div className="fiss-admin-detail"><WeekReportView report={w} /></div>
              </details>
            ) : (
              <div key={w.week_start} className="fiss-admin-item ld-week-missing">
                <span className="fiss-hist-period">Semaine {w.label}</span>
                <span className="status-pill due">Non reçu</span>
              </div>
            ))}
          </div>
        )}
      </section>
    </div>
  )
}

function GardeRow({ g, onOpen }: { g: LeaderGarde; onOpen: () => void }) {
  return (
    <li>
      <button className="ld-row ld-row-btn" onClick={onOpen}>
        {g.garde ? <Avatar person={g.garde} /> : <span className="mini-avatar ld-vacant">?</span>}
        <span className="ld-row-main">
          <strong>{g.garde ? g.garde.name : 'Aucun Garde désigné'}</strong>
          <small>{g.gem} · {g.members_count} membre(s)</small>
        </span>
        {g.week_report && <span className={`status-pill ${g.week_report === 'submitted' ? 'on' : 'due'}`}>{g.week_report === 'submitted' ? 'Rapport reçu' : 'Rapport non reçu'}</span>}
      </button>
    </li>
  )
}

/** Annuaire des responsables et de leur appartenance ; un Garde ouvre sa fiche. */
export default function Leaders() {
  const [params, setParams] = useSearchParams()
  const gemId = Number(params.get('gem')) || null
  const [data, setData] = useState<LeadersData | null>(null)
  const [denied, setDenied] = useState(false)
  const [query, setQuery] = useState('')

  const load = useCallback(() => {
    api<LeadersData>('/admin/leaders').then((d) => { setData(d); setDenied(false) }).catch(() => setDenied(true))
  }, [])
  useEffect(() => { if (!gemId) load() }, [load, gemId])
  usePulse(['gem_reports', 'members'], load, !gemId)

  const q = plain(query.trim())
  const match = useCallback((...texts: (string | null | undefined)[]) => !q || texts.some((t) => t && plain(t).includes(q)), [q])
  const gardesByTribe = useMemo(() => {
    const groups = new Map<string, LeaderGarde[]>()
    for (const g of data?.gardes ?? []) {
      if (!match(g.garde?.name, g.gem, g.tribe)) continue
      const key = g.tribe ?? 'Sans tribu'
      groups.set(key, [...(groups.get(key) ?? []), g])
    }
    return [...groups.entries()]
  }, [data, match])

  if (gemId) {
    return <AppLayout title="Fiche du Garde"><GardeDetail gemId={gemId} onBack={() => setParams({})} /></AppLayout>
  }

  const church = data?.scope === 'church'
  const assistants = data?.assistants.filter((p) => match(p.name, ...p.tribes)) ?? []
  const patriarchs = data?.patriarchs.filter((p) => match(p.name, ...p.tribes)) ?? []
  const respos = data?.department_leaders.filter((p) => match(p.name, ...p.departments)) ?? []
  const gardeCount = gardesByTribe.reduce((n, [, list]) => n + list.length, 0)

  return (
    <AppLayout title="Responsables" subtitle={data ? (church ? "Toute l'église" : `Tribu ${data.tribes.map((t) => t.name).join(', ')}`) : undefined}>
      {denied ? (
        <div className="empty-state">
          <Icon name="roles" size={28} />
          <h3>Page réservée</h3>
          <p>L'annuaire des responsables est consulté par les pasteurs et les responsables de tribu.</p>
        </div>
      ) : data === null ? (
        <div className="validation-grid"><SkeletonCard /><SkeletonCard /></div>
      ) : (
        <>
          <div className="toolbar">
            <input className="input search-input" type="search" aria-label="Rechercher un responsable" placeholder="Rechercher un nom, une tribu, un GEM…"
              value={query} onChange={(e) => setQuery(e.target.value)} />
            <span className="helper">Liste tenue à jour automatiquement à partir des rôles et de l'organisation.</span>
          </div>

          <div className="report-lists">
            {church && (
              <Group title="Assistants Pasteurs" count={assistants.length} empty="Aucun Assistant Pasteur.">
                {assistants.map((p) => <PersonRow key={p.user_id} person={p} belonging={p.tribes.map((t) => `Tribu ${t}`)} />)}
              </Group>
            )}
            <Group title="Patriarches" count={patriarchs.length} empty="Aucun patriarche."
              note={data.tribes_without_patriarch.length && !q ? `Sans patriarche : ${data.tribes_without_patriarch.join(', ')}.` : undefined}>
              {patriarchs.map((p) => <PersonRow key={p.user_id} person={p} belonging={p.tribes.map((t) => `Tribu ${t}`)} />)}
            </Group>
            {church && (
              <Group title="Responsables de département" count={respos.length} empty="Aucun responsable de département.">
                {respos.map((p) => <PersonRow key={p.user_id} person={p} belonging={p.departments} />)}
              </Group>
            )}
          </div>

          <section className="panel mt">
            <div className="panel-head">
              <div>
                <h3>Gardes et GEMs <span className="count-soft">{gardeCount}</span></h3>
                <p className="panel-sub">Rapport de la semaine {data.week_label} · ouvrez un Garde pour voir comment il mène son GEM.</p>
              </div>
            </div>
            {gardeCount === 0 ? <p className="helper">Aucun GEM.</p> : gardesByTribe.map(([tribe, list]) => (
              <div key={tribe} className="ld-tribe">
                {(church || gardesByTribe.length > 1) && <p className="lr-context-title">Tribu {tribe}</p>}
                <ul className="ld-list">
                  {list.map((g) => <GardeRow key={g.gem_id} g={g} onOpen={() => setParams({ gem: String(g.gem_id) })} />)}
                </ul>
              </div>
            ))}
          </section>
        </>
      )}
    </AppLayout>
  )
}
