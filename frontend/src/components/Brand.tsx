export function Brand({ subtitle }: { subtitle?: string }) {
  return (
    <div className="brand">
      <div className="brand-logo">
        <img src="/logo-vh.png" alt="Vases d'Honneur Chicoutimi" />
      </div>
      <h1>Vases d'<span className="accent">Honneur</span></h1>
      <p className="brand-city">Chicoutimi</p>
      {subtitle && <p className="brand-sub">{subtitle}</p>}
    </div>
  )
}
