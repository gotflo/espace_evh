import { useNavigate } from 'react-router-dom'

export function AdminHeader({ title, subtitle, backTo = '/tableau-de-bord' }: {
  title: string
  subtitle?: string
  backTo?: string
}) {
  const navigate = useNavigate()
  return (
    <div className="admin-header">
      <button className="back-btn" onClick={() => navigate(backTo)} aria-label="Retour">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
          strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M19 12H5M12 19l-7-7 7-7" /></svg>
      </button>
      <div>
        <h2 className="section-title" style={{ margin: 0 }}>{title}</h2>
        {subtitle && <p className="section-sub" style={{ margin: 0 }}>{subtitle}</p>}
      </div>
    </div>
  )
}
