import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import type { AuditEntry } from '../../types'

interface AuditResponse { logs: AuditEntry[]; has_more: boolean; actions: { key: string; label: string }[] }

const GROUPS: { key: string; label: string }[] = [
  { key: '', label: 'Toutes les actions' },
  { key: 'fiss.', label: 'FISS' },
  { key: 'tribe', label: 'Tribus' },
  { key: 'member.', label: 'Membres' },
  { key: 'family.', label: 'Famille' },
  { key: 'profile.', label: 'Profils' },
  { key: 'role.', label: 'Rôles' },
  { key: 'department.', label: 'Départements' },
  { key: 'announcement.', label: 'Annonces' },
  { key: 'event.', label: 'Événements' },
  { key: 'exercise.', label: 'Exercices' },
  { key: 'evaluation.', label: 'Notes' },
  { key: 'report.', label: 'Rapports' },
]

function fmt(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'oui' : 'non'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}

/** Detail lisible : ancienne valeur -> nouvelle valeur, champ par champ. */
function Changes({ entry }: { entry: AuditEntry }) {
  const keys = Array.from(new Set([...Object.keys(entry.old ?? {}), ...Object.keys(entry.new ?? {})]))
  const ctx = Object.entries(entry.context ?? {})
  if (keys.length === 0 && ctx.length === 0) return <p className="helper">Aucun détail supplémentaire.</p>
  return (
    <div className="audit-detail">
      {keys.length > 0 && (
        <table>
          <thead><tr><th>Champ</th><th>Avant</th><th>Après</th></tr></thead>
          <tbody>
            {keys.map((k) => (
              <tr key={k}><td>{k}</td><td className="old">{fmt(entry.old?.[k])}</td><td className="new">{fmt(entry.new?.[k])}</td></tr>
            ))}
          </tbody>
        </table>
      )}
      {ctx.length > 0 && (
        <dl>{ctx.map(([k, v]) => <div key={k}><dt>{k}</dt><dd>{fmt(v)}</dd></div>)}</dl>
      )}
      {entry.subject && <p className="helper">Objet : {entry.subject}{entry.ip ? ` · IP ${entry.ip}` : ''}</p>}
    </div>
  )
}

export default function AuditLog() {
  const [group, setGroup] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [logs, setLogs] = useState<AuditEntry[] | null>(null)
  const [hasMore, setHasMore] = useState(false)
  const [loadingMore, setLoadingMore] = useState(false)
  const [open, setOpen] = useState<number | null>(null)

  const query = useCallback((before?: number) => {
    const p = new URLSearchParams()
    if (group) p.set('action', group)
    if (from) p.set('from', from)
    if (to) p.set('to', to)
    if (before) p.set('before', String(before))
    return api<AuditResponse>(`/admin/audit?${p.toString()}`)
  }, [group, from, to])

  useEffect(() => {
    setLogs(null)
    query().then((r) => { setLogs(r.logs); setHasMore(r.has_more) }).catch(() => setLogs([]))
  }, [query])

  function more() {
    if (!logs?.length) return
    setLoadingMore(true)
    query(logs[logs.length - 1].id)
      .then((r) => { setLogs([...logs, ...r.logs]); setHasMore(r.has_more) })
      .finally(() => setLoadingMore(false))
  }

  return (
    <AppLayout title="Journal d'audit" subtitle="Historique des actions sensibles (lecture seule)">
      <div className="audit-filters">
        <select className="select" value={group} onChange={(e) => setGroup(e.target.value)} aria-label="Type d'action">
          {GROUPS.map((g) => <option key={g.key} value={g.key}>{g.label}</option>)}
        </select>
        <label className="date-field"><span>Du</span><input className="input" type="date" value={from} onChange={(e) => setFrom(e.target.value)} /></label>
        <label className="date-field"><span>Au</span><input className="input" type="date" value={to} onChange={(e) => setTo(e.target.value)} /></label>
      </div>

      {logs === null ? (
        <div className="audit-list">{[0, 1, 2, 3, 4].map((i) => <div key={i} className="skeleton skeleton-row" />)}</div>
      ) : logs.length === 0 ? (
        <div className="empty-state compact"><span aria-hidden>🗂️</span><p>Aucune action enregistrée pour ces critères.</p></div>
      ) : (
        <ul className="audit-list">
          {logs.map((l) => (
            <li key={l.id} className={`audit-item ${open === l.id ? 'open' : ''}`}>
              <button className="audit-row" onClick={() => setOpen(open === l.id ? null : l.id)} aria-expanded={open === l.id}>
                <span className={`audit-tag t-${l.action.split('.')[0].replace('_', '-')}`}>{l.label}</span>
                <span className="audit-who">
                  <strong>{l.actor}</strong>
                  {l.member && <> → {l.member}</>}
                </span>
                <time dateTime={l.created_at ?? undefined}>{l.created_at ? new Date(l.created_at).toLocaleString('fr-CA', { dateStyle: 'medium', timeStyle: 'short' }) : ''}</time>
              </button>
              {open === l.id && (
                <div className="audit-body">
                  <Changes entry={l} />
                  {l.member_user_id && <Link className="btn-link" to={`/admin/membres/${l.member_user_id}`}>Voir la fiche du membre</Link>}
                </div>
              )}
            </li>
          ))}
        </ul>
      )}
      {hasMore && (
        <div className="center mt">
          <button className="btn btn-ghost" disabled={loadingMore} onClick={more}>{loadingMore ? <span className="spinner" /> : 'Charger plus'}</button>
        </div>
      )}
      <p className="helper mt">Ce journal ne peut être ni modifié ni supprimé depuis l'application.</p>
    </AppLayout>
  )
}
