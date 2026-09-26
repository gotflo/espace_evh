import { useEffect, useState } from 'react'
import { api } from '../api/client'

interface Category { key: string; label: string; enabled: boolean }

/**
 * Choix des notifications recues sur le telephone (push), par categorie.
 * Une categorie coupee reste visible dans la cloche : rien n'est jamais perdu.
 */
export function NotificationPrefs() {
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState<Category[] | null>(null)

  useEffect(() => {
    if (open && !items) api<{ categories: Category[] }>('/me/notification-prefs').then((r) => setItems(r.categories)).catch(() => setItems([]))
  }, [open, items])

  async function toggle(c: Category) {
    const next = (items ?? []).map((i) => (i.key === c.key ? { ...i, enabled: !i.enabled } : i))
    setItems(next)
    try {
      const r = await api<{ categories: Category[] }>('/me/notification-prefs', { method: 'PUT', body: { prefs: { [c.key]: !c.enabled } }, toast: false })
      setItems(r.categories)
    } catch { setItems(items) }
  }

  return (
    <section className="panel notif-prefs">
      <button className="panel-head panel-toggle" onClick={() => setOpen(!open)} aria-expanded={open}>
        <h3>Choisir mes notifications</h3><span aria-hidden>{open ? '▴' : '▾'}</span>
      </button>
      {open && (
        items === null ? <span className="spinner" /> : (
          <>
            <p className="helper">Ce que vous recevez sur votre téléphone. Tout reste visible ici, dans vos notifications.</p>
            <ul className="prefs-list">
              {items.map((c) => (
                <li key={c.key}>
                  <label className="switch-line">
                    <input type="checkbox" checked={c.enabled} onChange={() => toggle(c)} />
                    <span>{c.label}</span>
                  </label>
                </li>
              ))}
            </ul>
            <p className="helper">Les messages importants (vos demandes, votre fiche FISS, votre famille, vos fonctions) arrivent toujours.</p>
          </>
        )
      )}
    </section>
  )
}
