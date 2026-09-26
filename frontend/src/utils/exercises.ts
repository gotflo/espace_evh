/** « À faire avant le jeudi 8 octobre à 21h00 », « Se ferme aujourd'hui à 21h00 », « Fermé depuis… ». */
export function deadlineLabel(iso: string | null, closed: boolean): string | null {
  if (!iso) return null
  const d = new Date(iso)
  const when = d.toLocaleDateString('fr-CA', { weekday: 'long', day: 'numeric', month: 'long' })
  const time = `${d.getHours()}h${String(d.getMinutes()).padStart(2, '0')}`
  if (closed) return `Fermé depuis le ${when} à ${time}`
  const hours = (d.getTime() - Date.now()) / 3600000
  if (hours < 24) return `Se ferme ${d.toDateString() === new Date().toDateString() ? "aujourd'hui" : 'demain'} à ${time}`
  return `À faire avant le ${when} à ${time}`
}
