import { useEffect, useState } from 'react'
import { api } from '../api/client'
import type { MyOverviewData } from '../types'

type Mood = 'great' | 'good' | 'meh' | 'bad' | 'idle'
const FACE: Record<Mood, string> = { great: '😄', good: '🙂', meh: '😐', bad: '😞', idle: '🌱' }
const MOOD_WORD: Record<Mood, string> = { great: 'Excellent', good: 'Bien', meh: 'Moyen', bad: 'A ameliorer', idle: 'A demarrer' }

function StatCard({ mood, value, unit, label, sub }: {
  mood: Mood; value: string | number; unit?: string; label: string; sub?: string
}) {
  return (
    <div className={`gstat gstat-${mood}`}>
      <div className="gstat-face" role="img" aria-label={MOOD_WORD[mood]}>{FACE[mood]}</div>
      <div className="gstat-value">{value}{unit && <small>{unit}</small>}</div>
      <div className="gstat-label">{label}</div>
      <div className="gstat-mood">{MOOD_WORD[mood]}</div>
      {sub && <div className="gstat-sub">{sub}</div>}
    </div>
  )
}

export function MyOverview() {
  const [d, setD] = useState<MyOverviewData | null>(null)
  useEffect(() => { api<MyOverviewData>('/me/overview').then(setD).catch(() => setD(null)) }, [])
  if (!d) return null

  const assiduiteMood: Mood = d.assiduite >= 6 ? 'great' : d.assiduite >= 3 ? 'good' : d.assiduite >= 1 ? 'meh' : 'idle'
  const noteMood: Mood = d.note_moyenne == null ? 'idle' : d.note_moyenne >= 15 ? 'great' : d.note_moyenne >= 10 ? 'good' : d.note_moyenne >= 6 ? 'meh' : 'bad'
  const r = d.rehearsal
  const puncMood: Mood = r == null || r.punctuality_rate == null ? 'idle'
    : r.punctuality_rate >= 90 ? 'great' : r.punctuality_rate >= 70 ? 'good' : r.punctuality_rate >= 50 ? 'meh' : 'bad'

  return (
    <section className="gstat-grid">
      <StatCard mood={assiduiteMood} value={d.assiduite} label="Assiduite" sub="presences (3 mois)" />
      <StatCard mood={noteMood} value={d.note_moyenne ?? '-'} unit={d.note_moyenne != null ? '/20' : ''} label="Vertumetre" sub="moyenne des notes" />
      <StatCard mood={d.parcours > 0 ? 'good' : 'idle'} value={d.parcours} label="Parcours" sub="etapes franchies" />
      {r && (
        <StatCard mood={puncMood} value={r.punctuality_rate ?? '-'} unit={r.punctuality_rate != null ? '%' : ''} label="Ponctualite"
          sub={`${r.present} present · ${r.retard} retard · ${r.absent_justifie + r.absent} absent`} />
      )}
    </section>
  )
}
