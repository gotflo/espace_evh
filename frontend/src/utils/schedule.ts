const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`

/** Adresse lue par le bloc « Nos rendez-vous » (7 prochains jours, evenements seulement) ; aussi prechargee par le tableau de bord. */
export function serviceSchedulePath() {
  const from = new Date()
  // Jours de calendrier (et non 6 x 24 h) : toujours 7 jours, meme a un changement d'heure.
  const to = new Date(from)
  to.setDate(to.getDate() + 6)
  return `/calendar?from=${ymd(from)}&to=${ymd(to)}&only=events`
}
