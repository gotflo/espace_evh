import type { FissForm } from '../types'

const SANCT_VAL: Record<string, number> = { bien: 2, moyen: 1, mal: 0 }

export type FissDiffField = {
  key: string
  label: string
  prev: number | string | null
  cur: number | string | null
  dir: 'up' | 'down' | 'stable'
}
export type FissComparison = {
  overall: 'up' | 'down' | 'stable'
  diff: number
  fields: FissDiffField[]
}

const NUM_FIELDS: [keyof FissForm, string][] = [
  ['meditation', 'Meditation'],
  ['priere', 'Priere'],
  ['jeune', 'Jeune'],
  ['situation_financiere', 'Financiere'],
  ['situation_familiale', 'Familiale'],
  ['situation_conjugale', 'Conjugale'],
]
const SANCT_FIELDS: [keyof FissForm, string][] = [
  ['sanctification_corps', 'Sanctif. corps'],
  ['sanctification_ame', 'Sanctif. ame'],
  ['sanctification_esprit', 'Sanctif. esprit'],
]

function dir(cur: number, prev: number): 'up' | 'down' | 'stable' {
  return cur > prev ? 'up' : cur < prev ? 'down' : 'stable'
}

/** Compare la fiche courante a la precedente : ecart global + par element. */
export function compareFiss(cur: FissForm, prev?: FissForm | null): FissComparison | null {
  if (!prev) return null

  const fields: FissDiffField[] = []
  let curTotal = 0
  let prevTotal = 0

  for (const [k, label] of NUM_FIELDS) {
    const c = (cur[k] as number | null) ?? null
    const p = (prev[k] as number | null) ?? null
    if (c == null && p == null) continue
    const cv = c ?? 0, pv = p ?? 0
    curTotal += cv; prevTotal += pv
    fields.push({ key: k as string, label, prev: p, cur: c, dir: dir(cv, pv) })
  }
  for (const [k, label] of SANCT_FIELDS) {
    const c = (cur[k] as string | null) ?? null
    const p = (prev[k] as string | null) ?? null
    if (!c && !p) continue
    const cv = c ? SANCT_VAL[c] ?? 0 : 0
    const pv = p ? SANCT_VAL[p] ?? 0 : 0
    fields.push({ key: k as string, label, prev: p, cur: c, dir: dir(cv, pv) })
  }

  const diff = curTotal - prevTotal
  return { overall: diff > 0 ? 'up' : diff < 0 ? 'down' : 'stable', diff, fields }
}

export const TREND_LABEL: Record<'up' | 'down' | 'stable', string> = {
  up: 'En hausse', down: 'En baisse', stable: 'Stable',
}
export const TREND_ARROW: Record<'up' | 'down' | 'stable', string> = { up: '↑', down: '↓', stable: '→' }
