/**
 * Nom d'une personne mis en valeur : prenom puis nom, chacun sur sa ligne, taille adaptee a la
 * longueur (les noms composes « Marie-Christine-Éléonore Tremblay-Bouchard » restent lisibles et
 * se coupent aux traits d'union, jamais au milieu d'une syllabe).
 */
export function PersonName({ first, last, fallback, className = '' }: {
  first?: string | null
  last?: string | null
  fallback: string
  className?: string
}) {
  const f = first?.trim() ?? ''
  const l = last?.trim() ?? ''
  const length = Math.max(f.length, l.length)
  const size = length > 26 ? 'pn-xl' : length > 18 ? 'pn-l' : ''
  return (
    <h2 className={`person-name ${size} ${className}`.trim()}>
      {f || l
        ? <><span className="pn-first">{f}</span>{f && l ? ' ' : ''}<span className="pn-last">{l}</span></>
        : fallback}
    </h2>
  )
}
