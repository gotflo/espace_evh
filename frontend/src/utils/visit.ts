const SINCE_KEY = 'evh_previous_visit'

/** Date de la visite precedente, retenue une seule fois par session (premier /me). */
export function rememberPreviousVisit(lastSeen: string | null | undefined) {
  try {
    if (sessionStorage.getItem(SINCE_KEY) === null) sessionStorage.setItem(SINCE_KEY, lastSeen ?? '')
  } catch { /* stockage indisponible */ }
}

export function previousVisit(): string | null {
  try { return sessionStorage.getItem(SINCE_KEY) || null } catch { return null }
}
