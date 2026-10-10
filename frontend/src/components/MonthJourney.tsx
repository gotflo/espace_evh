import { useCallback, useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, peek } from '../api/client'
import { usePulse } from '../pulse'
import type { MyMonthData } from '../types'
import { Icon } from './Icon'
import type { IconName } from '../utils/icons'

interface Step { key: string; to: string; icon: IconName; title: string; status: string; done: boolean; urgent?: boolean }

/**
 * « Mon mois » : les trois rendez-vous personnels du mois (FISS, résolutions, devoir), avec ce
 * qui reste a faire. Un appui ouvre directement le bon ecran ; tout fait : la carte se fait discrete.
 */
export function MonthJourney() {
  const [data, setData] = useState<MyMonthData | null>(() => peek<MyMonthData>('/me/month') ?? null)
  const load = useCallback(() => { api<MyMonthData>('/me/month', { toast: false }).then(setData).catch(() => setData(null)) }, [])
  useEffect(() => { load() }, [load])
  usePulse(['fiss', 'resolutions', 'assignments', 'appointments'], load)

  if (!data) return null
  const appt = data.appointment
  const apptLine = appt ? (
    <Link to="/rendez-vous" className={`appt-dash ${appt.status === 'confirmed' ? 'is-confirmed' : ''} ${appt.today ? 'is-today' : ''}`}>
      <span className="appt-dash-icon"><Icon name="clock" size={18} /></span>
      <span className="appt-dash-text">
        <small>{appt.today ? 'Aujourd\'hui' : 'Rendez-vous avec le pasteur'}</small>
        <strong>{appt.status === 'confirmed' && appt.when ? appt.when.charAt(0).toUpperCase() + appt.when.slice(1) : 'Demande envoyée · heure à fixer'}</strong>
      </span>
      <Icon name="chevron" size={18} className="month-step-go" />
    </Link>
  ) : null
  const { fiss, resolutions, assignment, vertumetre } = data.steps
  const urgent = !fiss.done && data.days_left <= 3

  const steps: Step[] = [
    {
      key: 'fiss', to: '/ma-fiche', icon: 'fiss', title: 'Ma fiche de santé spirituelle', done: fiss.done, urgent,
      status: fiss.done ? 'Remplie' : urgent ? `Derniers jours · ${data.days_left} j` : 'À remplir',
    },
    ...(vertumetre ? [{
      key: 'vt', to: '/vertumetre', icon: 'reports' as IconName, title: 'Mon Vertumètre', done: vertumetre.done, urgent: !vertumetre.done && data.days_left <= 3,
      status: vertumetre.done ? 'Rempli' : data.days_left <= 3 ? `Derniers jours · ${data.days_left} j` : 'À remplir',
    }] : []),
    ...(resolutions ? [{
      key: 'rm', to: '/mes-resolutions', icon: 'target' as IconName, title: 'Mes résolutions du mois', done: resolutions.done,
      status: resolutions.done
        ? (resolutions.review_needed ? 'Prises · bilan du mois passé à faire' : 'Prises')
        : (resolutions.review_needed ? 'Bilan et résolutions à faire' : 'À prendre'),
    }] : []),
  ]
  if (assignment?.available && assignment.id) {
    steps.push({
      key: 'ds', to: `/mon-devoir?d=${assignment.id}`, icon: assignment.media_type === 'audio' ? 'headphones' : 'devoir',
      title: `Mon devoir : ${assignment.title}`, done: assignment.done, status: assignment.done ? 'Fait' : 'À faire',
    })
  }
  const allDone = data.done >= data.total && !resolutions?.review_needed

  if (allDone) {
    return (
      <>
        {apptLine}
        <section className="month-journey month-done" aria-label="Mon mois">
          <span className="month-done-icon"><Icon name="check" size={18} /></span>
          <p><strong>Tout est fait pour {data.period_label}.</strong></p>
        </section>
      </>
    )
  }

  const pct = Math.round((data.done / Math.max(1, data.total)) * 100)
  return (
    <>
    {apptLine}
    <section className={`month-journey ${urgent ? 'is-urgent' : ''}`} aria-label="Mon mois">
      <header className="month-head">
        <div>
          <span className="month-kicker">Mon mois</span>
          <h3>{data.period_label.charAt(0).toUpperCase() + data.period_label.slice(1)}</h3>
        </div>
        <div className="month-ring" style={{ ['--pct' as string]: `${pct}%` }} role="img" aria-label={`${data.done} sur ${data.total} fait`}>
          <span>{data.done}<small>/{data.total}</small></span>
        </div>
      </header>
      <ol className="month-steps">
        {steps.map((s) => (
          <li key={s.key}>
            <Link to={s.to} className={`month-step ${s.done ? 'done' : ''} ${s.urgent ? 'urgent' : ''}`}>
              <span className="month-step-icon">{s.done ? <Icon name="check" size={18} /> : <Icon name={s.icon} size={18} />}</span>
              <span className="month-step-text">
                <strong>{s.title}</strong>
                <small>{s.status}</small>
              </span>
              {!s.done && <Icon name="chevron" size={18} className="month-step-go" />}
            </Link>
          </li>
        ))}
      </ol>
    </section>
    </>
  )
}
