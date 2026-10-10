import { useEffect, useState } from 'react'
import { api } from '../api/client'
import { EmailInvite } from './EmailInvite'

interface Category { key: string; label: string; enabled: boolean }
interface Channel extends Category { available: boolean; detail: string }
interface Prefs { categories: Category[]; channels?: Channel[] }

/**
 * Choix des notifications : par categorie (telephone, courriel et texto suivent le meme choix), et
 * canaux « par courriel » / « par texto ». Une categorie coupee reste visible dans la cloche.
 */
export function NotificationPrefs() {
  const [open, setOpen] = useState(false)
  const [prefs, setPrefs] = useState<Prefs | null>(null)

  useEffect(() => {
    if (open && !prefs) api<Prefs>('/me/notification-prefs').then(setPrefs).catch(() => setPrefs({ categories: [] }))
  }, [open, prefs])

  async function toggle(key: string, enabled: boolean) {
    const before = prefs
    const flip = <T extends Category>(list: T[] = []) => list.map((i) => (i.key === key ? { ...i, enabled: !enabled } : i))
    setPrefs(prefs && { categories: flip(prefs.categories), channels: flip(prefs.channels) })
    try {
      setPrefs(await api<Prefs>('/me/notification-prefs', { method: 'PUT', body: { prefs: { [key]: !enabled } }, toast: false }))
    } catch { setPrefs(before) }
  }

  return (
    <section className="panel notif-prefs">
      <button className="panel-head panel-toggle" onClick={() => setOpen(!open)} aria-expanded={open}>
        <h3>Choisir mes notifications</h3><span aria-hidden>{open ? '▴' : '▾'}</span>
      </button>
      {open && (
        prefs === null ? <span className="spinner" /> : (
          <>
            {prefs.channels && prefs.channels.length > 0 && (
              <>
                <p className="helper">En plus de l'application : votre devoir, vos rappels (fiche, rapport) et les messages importants.</p>
                <ul className="prefs-list prefs-channels">
                  {prefs.channels.map((c) => (
                    <li key={c.key}>
                      <label className={`switch-line ${c.available ? '' : 'is-off'}`}>
                        <input type="checkbox" checked={c.enabled && c.available} disabled={!c.available} onChange={() => toggle(c.key, c.enabled)} />
                        <span><b>{c.label}</b><small className="helper">{c.detail}</small></span>
                      </label>
                    </li>
                  ))}
                </ul>
                {prefs.channels.some((c) => c.key === 'channel_email' && !c.available) && <EmailInvite compact />}
              </>
            )}
            <p className="helper">Par sujet : ce que vous recevez sur votre téléphone et par courriel. Tout reste visible ici, dans vos notifications.</p>
            <ul className="prefs-list">
              {prefs.categories.map((c) => (
                <li key={c.key}>
                  <label className="switch-line">
                    <input type="checkbox" checked={c.enabled} onChange={() => toggle(c.key, c.enabled)} />
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
