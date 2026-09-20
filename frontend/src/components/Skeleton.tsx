/** Bloc de chargement anime (effet shimmer). */
export function Skeleton({ w, h, r, className, style }: {
  w?: number | string
  h?: number | string
  r?: number | string
  className?: string
  style?: React.CSSProperties
}) {
  return (
    <span
      className={`skeleton ${className ?? ''}`}
      style={{ width: w, height: h, borderRadius: r, ...style }}
    />
  )
}

/** Carte de chargement generique (pour les fils / listes). */
export function SkeletonCard() {
  return (
    <div className="skeleton-card">
      <Skeleton w={48} h={48} r={12} />
      <div style={{ flex: 1, display: 'flex', flexDirection: 'column', gap: 8 }}>
        <Skeleton w="45%" h={13} r={6} />
        <Skeleton w="80%" h={11} r={6} />
        <Skeleton w="60%" h={11} r={6} />
      </div>
    </div>
  )
}
