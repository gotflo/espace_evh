const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/** Adresse lue par le bloc « Nos rendez-vous » (7 prochains jours, evenements seulement) ; aussi prechargee par le tableau de bord. */
export function serviceSchedulePath() {
  const from = new Date()
  const to = new Date(from.getTime() + 6 * 86400000)
  return `/calendar?from=${ymd(from)}&to=${ymd(to)}&only=events`
}
