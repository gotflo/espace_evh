// Rapports mensuels : memes regles que le serveur (App\Support\LeaderReportCatalog) pour
// savoir quelles questions sont posees et lesquelles attendent encore une reponse.
import type { LeaderReportKind, ReportAnswer, ReportAnswers, ReportQuestion, ReportStep } from '../types'

export const KIND_LABEL: Record<LeaderReportKind, string> = { tribe: 'Tribu', department: 'Département' }

/** Adresse du questionnaire d'un rapport. */
export function fillUrl(kind: LeaderReportKind, scopeId: number, period: string): string {
  return `/admin/rapports-mensuels?remplir=${kind}:${scopeId}&mois=${period}`
}

/** La question est-elle posee, compte tenu des autres reponses ? */
export function isVisible(q: ReportQuestion, answers: ReportAnswers): boolean {
  const cond = q.show_if
  if (!cond) return true
  const value = answers[cond.key]
  if (value === null || value === undefined || value === '') return false
  if (cond.in) return typeof value === 'string' && cond.in.includes(value)
  const n = Number(value)
  return (cond.min === undefined || n >= cond.min) && (cond.max === undefined || n <= cond.max)
}

export function isEmpty(value: ReportAnswer | undefined): boolean {
  if (value === null || value === undefined) return true
  if (typeof value === 'string') return value.trim() === ''
  if (Array.isArray(value)) return value.length === 0
  if (typeof value === 'object') return Object.keys(value).length === 0
  return false
}

/** Message si la reponse manque, sinon null. */
export function missing(q: ReportQuestion, value: ReportAnswer | undefined): string | null {
  if (q.type === 'checks' && value && typeof value === 'object' && !Array.isArray(value)) {
    const blank = q.options?.find((o) => o.key in value && value[o.key].trim() === '')
    if (blank) return `Précisez en quelques mots : ${blank.label}.`
  }
  if (!q.required || !isEmpty(value)) return null
  return q.type === 'checks' ? 'Cochez au moins une proposition.' : 'Cette réponse est attendue.'
}

/** Questions de l'etape sans reponse (cle => message). */
export function stepErrors(step: ReportStep, answers: ReportAnswers): Record<string, string> {
  const errors: Record<string, string> = {}
  for (const q of step.questions) {
    if (!isVisible(q, answers)) continue
    const error = missing(q, answers[q.key])
    if (error) errors[q.key] = error
  }
  return errors
}

/** Reponses envoyees au serveur : uniquement celles des questions posees. */
export function visibleAnswers(steps: ReportStep[], answers: ReportAnswers): ReportAnswers {
  const out: ReportAnswers = {}
  for (const q of steps.flatMap((s) => s.questions)) {
    if (isVisible(q, answers) && !isEmpty(answers[q.key])) out[q.key] = answers[q.key]
  }
  return out
}

export function fmtDay(iso?: string | null): string {
  return iso ? new Date(iso.length === 10 ? `${iso}T12:00:00` : iso).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long' }) : ''
}
