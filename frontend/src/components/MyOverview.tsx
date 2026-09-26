import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { api } from '../api/client'
import type { MyOverviewData } from '../types'

type Mood = 'great' | 'good' | 'meh' | 'bad' | 'idle'
const FACE: Record<Mood, string> = { great: '😄', good: '🙂', meh: '😐', bad: '😞', idle: '🌱' }
const MOOD_WORD: Record<Mood, string> = { great: 'Excellent', good: 'Bien', meh: 'Moyen', bad: 'À améliorer', idle: 'À démarrer' }
const STATUS_LABEL: Record<string, string> = { present: 'Présent', retard: 'En retard', absent_justifie: 'Absence excusée', absent: 'Absent' }

/** Petite courbe d'evolution (une seule serie : le titre de la carte la nomme). */
function Sparkline({ values }: { values: (number | null)[] }) {
  const pts = values.map((v, i) => ({ v, i })).filter((p) => p.v !== null) as { v: number; i: number }[]
  if (pts.length < 2) return null
  const w = 96, h = 28, n = Math.max(1, values.length - 1)
  const x = (i: number) => (i / n) * (w - 6) + 3
  const y = (v: number) => h - 3 - (v / 100) * (h - 6)
  const last = pts[pts.length - 1]
  return (
    <svg className="spark" width={w} height={h} viewBox={`0 0 ${w} ${h}`} aria-hidden>
      <polyline points={pts.map((p) => `${x(p.i)},${y(p.v)}`).join(' ')} fill="none" stroke="currentColor" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
      <circle cx={x(last.i)} cy={y(last.v)} r="3.5" fill="currentColor" stroke="var(--card-bg)" strokeWidth="2" />
    </svg>
  )
}

function StatCard({ mood, value, unit, label, sub, action, onClick, children }: {
  mood: Mood; value: string | number; unit?: string; label: string; sub?: string; action: string; onClick: () => void; children?: React.ReactNode
}) {
  return (
    <button type="button" className={`gstat gstat-${mood} gstat-btn`} onClick={onClick} aria-label={`${label} : ${value}${unit ?? ''}. ${action}`}>
      <div className="gstat-face" role="img" aria-label={MOOD_WORD[mood]}>{FACE[mood]}</div>
      <div className="gstat-value">{value}{unit && <small>{unit}</small>}</div>
      <div className="gstat-label">{label}</div>
      <div className="gstat-mood">{MOOD_WORD[mood]}</div>
      {sub && <div className="gstat-sub">{sub}</div>}
      {children}
      <span className="gstat-action">{action} →</span>
    </button>
  )
}

/**
 * Indicateurs du fidele sous forme de cartes interactives :
 * FISS -> sa fiche ; Vertumetre -> ses notes ; Assiduite / Ponctualite -> le detail ; Parcours -> ses etapes.
 */
export function MyOverview() {
  const navigate = useNavigate()
  const [d, setD] = useState<MyOverviewData | null>(null)
  const [sheet, setSheet] = useState<'attendance' | 'rehearsal' | null>(null)
  useEffect(() => { api<MyOverviewData>('/me/overview').then(setD).catch(() => setD(null)) }, [])
  if (!d) return <section className="gstat-grid">{[0, 1, 2, 3].map((i) => <div key={i} className="skeleton skeleton-tile" />)}</section>

  const scrollTo = (id: string) => document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  const fissScore = d.fiss.score
  const fissMood: Mood = !d.fiss.filled ? 'idle' : fissScore == null ? 'good' : fissScore >= 75 ? 'great' : fissScore >= 55 ? 'good' : fissScore >= 35 ? 'meh' : 'bad'
  const assiduiteMood: Mood = d.assiduite >= 6 ? 'great' : d.assiduite >= 3 ? 'good' : d.assiduite >= 1 ? 'meh' : 'idle'
  const noteMood: Mood = d.note_moyenne == null ? 'idle' : d.note_moyenne >= 15 ? 'great' : d.note_moyenne >= 10 ? 'good' : d.note_moyenne >= 6 ? 'meh' : 'bad'
  const r = d.rehearsal
  const puncMood: Mood = r == null || r.punctuality_rate == null ? 'idle'
    : r.punctuality_rate >= 90 ? 'great' : r.punctuality_rate >= 70 ? 'good' : r.punctuality_rate >= 50 ? 'meh' : 'bad'

  return (
    <>
      <section className="gstat-grid">
        <StatCard mood={fissMood} value={d.fiss.filled ? (fissScore != null ? Math.round(fissScore) : '✓') : '—'} unit={d.fiss.filled && fissScore != null ? '%' : ''}
          label="FISS" sub={d.fiss.filled ? `vie spirituelle · ${d.fiss.period_label}` : `à remplir · ${d.fiss.period_label}`}
          action={d.fiss.filled ? 'Voir ma fiche' : 'Remplir'} onClick={() => navigate('/ma-fiche')}>
          <Sparkline values={d.fiss.trend.map((t) => t.score)} />
        </StatCard>
        <StatCard mood={noteMood} value={d.note_moyenne ?? '—'} unit={d.note_moyenne != null ? '/20' : ''} label="Vertumètre"
          sub="moyenne des notes" action="Voir mes notes" onClick={() => scrollTo('mes-notes')} />
        <StatCard mood={assiduiteMood} value={d.assiduite} label="Assiduité" sub="présences (3 mois)" action="Détails" onClick={() => setSheet('attendance')} />
        <StatCard mood={d.parcours > 0 ? 'good' : 'idle'} value={d.parcours} label="Parcours" sub="étapes franchies" action="Mes étapes" onClick={() => scrollTo('mon-parcours')} />
        {r && (
          <StatCard mood={puncMood} value={r.punctuality_rate ?? '—'} unit={r.punctuality_rate != null ? '%' : ''} label="Ponctualité"
            sub="répétitions (3 mois)" action="Détails" onClick={() => setSheet('rehearsal')} />
        )}
      </section>

      {sheet && (
        <div className="modal-overlay" onClick={() => setSheet(null)}>
          <div className="modal-box" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head">
              <h3>{sheet === 'attendance' ? 'Mon assiduité' : 'Ma ponctualité aux répétitions'}</h3>
              <button className="btn-link" onClick={() => setSheet(null)}>Fermer</button>
            </div>
            {sheet === 'attendance' ? (
              <>
                <div className="kpi-mini-row">
                  <div className="kpi-mini"><strong>{d.attendance.present}</strong><span>présence(s)</span></div>
                  <div className="kpi-mini"><strong>{d.attendance.sessions}</strong><span>culte(s) pointé(s)</span></div>
                  <div className="kpi-mini"><strong>{d.attendance.rate != null ? `${d.attendance.rate} %` : '—'}</strong><span>taux</span></div>
                </div>
                <p className="helper">Sur les 3 derniers mois, d'après les feuilles de présence de vos responsables.</p>
                <ul className="att-list">
                  {d.attendance.recent.filter((a) => a.kind === 'culte').map((a, i) => (
                    <li key={i}><span>{new Date(a.date + 'T00:00').toLocaleDateString('fr-CA', { weekday: 'short', day: 'numeric', month: 'short' })} · {a.event}</span>
                      <span className={`att-status att-${a.status}`}>{STATUS_LABEL[a.status] ?? a.status}</span></li>
                  ))}
                  {d.attendance.recent.filter((a) => a.kind === 'culte').length === 0 && <li className="helper">Aucune présence enregistrée récemment.</li>}
                </ul>
              </>
            ) : r && (
              <>
                <div className="kpi-mini-row">
                  <div className="kpi-mini"><strong>{r.present}</strong><span>à l'heure</span></div>
                  <div className="kpi-mini"><strong>{r.retard}</strong><span>en retard</span></div>
                  <div className="kpi-mini"><strong>{r.absent_justifie + r.absent}</strong><span>absence(s)</span></div>
                </div>
                <ul className="att-list">
                  {d.attendance.recent.filter((a) => a.kind === 'repetition').map((a, i) => (
                    <li key={i}><span>{new Date(a.date + 'T00:00').toLocaleDateString('fr-CA', { weekday: 'short', day: 'numeric', month: 'short' })} · {a.event}</span>
                      <span className={`att-status att-${a.status}`}>{STATUS_LABEL[a.status] ?? a.status}</span></li>
                  ))}
                </ul>
              </>
            )}
          </div>
        </div>
      )}
    </>
  )
}
