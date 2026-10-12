import { useCallback, useEffect, useRef, useState, type ChangeEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError, peek } from '../../api/client'
import { refreshAppearance } from '../../appearance'
import { AppLayout } from '../../components/AppLayout'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { Icon } from '../../components/Icon'
import { ImageFramer, type FramePreview, type FrameResult } from '../../components/ImageFramer'
import { SkeletonCard } from '../../components/Skeleton'
import { toast } from '../../toast'
import { SettingsEditor } from './Settings'

interface Img { id: string; small: string; large: string; x: number; y: number; builtin: boolean }
interface Payload { home: Img; home_changed: boolean; login: Img[]; login_changed: boolean; max_login_photos: number; message?: string }

/** Formes reelles des fonds : bandeau du verset (accueil) et ecran de connexion, sur ordinateur et telephone. */
const HOME_PREVIEWS: FramePreview[] = [{ label: 'Ordinateur', ratio: 3.6 }, { label: 'Téléphone', ratio: 2.5 }]
const LOGIN_PREVIEWS: FramePreview[] = [{ label: 'Ordinateur', ratio: 16 / 9 }, { label: 'Téléphone', ratio: 9 / 19 }]

type Framing =
  | { target: 'home'; file?: File; src?: string; x: number; y: number }
  | { target: 'login-new'; file: File }
  | { target: 'login'; id: string; src: string; x: number; y: number }

/**
 * Apparence (compte de gestion et Pasteur Resident, permission settings.manage) : image de l'accueil,
 * photos de l'ecran de connexion et textes affiches aux membres, sans toucher au code. Chaque image est
 * cadree avant l'enregistrement, reduite sur l'appareil puis sur le serveur ; tout revient a l'origine
 * d'un clic. Les ecrans ouverts chez les membres se mettent a jour seuls.
 */
export default function Appearance() {
  const [data, setData] = useState<Payload | null>(() => peek<Payload>('/admin/appearance') ?? null)
  const [framing, setFraming] = useState<Framing | null>(null)
  const [confirm, setConfirm] = useState<'home' | 'login' | { remove: Img } | null>(null)
  const [busy, setBusy] = useState(false)
  const homeInput = useRef<HTMLInputElement>(null)
  const loginInput = useRef<HTMLInputElement>(null)

  const load = useCallback(() => {
    api<Payload>('/admin/appearance').then(setData).catch(() => setData((d) => d))
  }, [])
  useEffect(() => { load() }, [load])

  /** Applique la reponse du serveur et met a jour l'apparence de cet appareil. */
  function applied(r: Payload) {
    setData(r)
    void refreshAppearance(true)
    if (r.message) toast.success(r.message, { title: 'Apparence' })
  }

  function fail(err: unknown): never {
    toast.error(err instanceof ApiError ? err.firstMessage : 'L’enregistrement n’a pas abouti. Réessayez.')
    throw err
  }

  const pick = (target: 'home' | 'login-new') => (e: ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setFraming(target === 'home' ? { target: 'home', file, x: 50, y: 50 } : { target: 'login-new', file })
  }

  /** Erreur affichee dans la fenetre de cadrage (qui reste ouverte), sans bulle en double. */
  async function saveFrame(f: Framing, r: FrameResult) {
    if (f.target === 'login') {
      const list = data!.login.map((p) => (p.id === f.id ? { ...p, x: r.x, y: r.y } : p))
      applied(await api<Payload>('/admin/appearance/login', { method: 'PUT', toast: false, body: { items: list.map(({ id, x, y }) => ({ id, x, y })) } }))
    } else if (r.file) {
      const fd = new FormData()
      fd.append('image', r.file)
      fd.append('x', String(r.x))
      fd.append('y', String(r.y))
      applied(await api<Payload>(f.target === 'home' ? '/admin/appearance/home' : '/admin/appearance/login', { method: 'POST', body: fd, toast: false }))
    } else {
      applied(await api<Payload>('/admin/appearance/home', { method: 'PUT', body: { x: r.x, y: r.y }, toast: false }))
    }
    setFraming(null)
  }

  async function arrange(list: Img[]) {
    setBusy(true)
    try {
      applied(await api<Payload>('/admin/appearance/login', { method: 'PUT', toast: false, body: { items: list.map(({ id, x, y }) => ({ id, x, y })) } }))
    } catch (err) { fail(err) } finally { setBusy(false) }
  }

  function move(index: number, delta: number) {
    if (!data) return
    const list = [...data.login]
    const [item] = list.splice(index, 1)
    list.splice(index + delta, 0, item)
    void arrange(list).catch(() => {})
  }

  const thumb = (img: Img, ratio: number, label: string) => (
    <figure className="appearance-thumb" style={{ width: `min(100%, ${Math.round(ratio * 110)}px)` }}>
      <div className="framer-frame" style={{ aspectRatio: String(ratio), cursor: 'default' }}>
        <img src={img.small} alt="" loading="lazy" style={{ objectPosition: `${img.x}% ${img.y}%` }} />
      </div>
      <figcaption>{label}</figcaption>
    </figure>
  )

  return (
    <AppLayout title="Apparence" subtitle="Images et textes vus par les membres, sans toucher au code">
      {!data ? <div className="panel-grid"><SkeletonCard /><SkeletonCard /></div> : (
        <div className="settings-page appearance-page">
          <p className="helper settings-intro">
            Chaque image est cadrée avant l’enregistrement puis allégée automatiquement. Les membres voient le changement
            tout de suite, sans recharger l’application. Rappels, horaires et modules : page <Link to="/admin/reglages">Réglages</Link>.
          </p>

          <section className="panel settings-group" aria-labelledby="app-home">
            <div className="settings-group-head">
              <div className="settings-group-title">
                <h3 id="app-home">Image de l’accueil</h3>
                <p className="helper">Le fond du verset, en haut de l’accueil de chaque membre.</p>
              </div>
              {!data.home_changed && <span className="settings-count">Image d’origine</span>}
            </div>
            <div className="appearance-previews">
              {thumb(data.home, HOME_PREVIEWS[0].ratio, 'Ordinateur')}
              {thumb(data.home, HOME_PREVIEWS[1].ratio, 'Téléphone')}
            </div>
            <div className="appearance-actions">
              <button type="button" className="btn btn-primary small" onClick={() => homeInput.current?.click()}>
                <Icon name="image" size={15} /> Changer l’image
              </button>
              <button type="button" className="btn btn-ghost small" onClick={() => setFraming({ target: 'home', src: data.home.large, x: data.home.x, y: data.home.y })}>Recadrer</button>
              {data.home_changed && <button type="button" className="btn btn-ghost small" onClick={() => setConfirm('home')}>Rétablir l’image d’origine</button>}
              <input ref={homeInput} type="file" accept="image/*" hidden onChange={pick('home')} />
            </div>
          </section>

          <section className="panel settings-group" aria-labelledby="app-login">
            <div className="settings-group-head">
              <div className="settings-group-title">
                <h3 id="app-login">Écran de connexion</h3>
                <p className="helper">Les photos défilent en fondu, dans cet ordre, derrière le formulaire de connexion.</p>
              </div>
              <span className="settings-count">{data.login.length} / {data.max_login_photos} photos</span>
            </div>
            <ol className="appearance-list">
              {data.login.map((p, i) => (
                <li key={p.id} className="appearance-item">
                  {thumb(p, 16 / 10, `Photo ${i + 1}${p.builtin ? ' (d’origine)' : ''}`)}
                  <div className="appearance-item-actions">
                    <button type="button" className="btn btn-ghost small" disabled={busy} onClick={() => setFraming({ target: 'login', id: p.id, src: p.large, x: p.x, y: p.y })}>Recadrer</button>
                    <button type="button" className="btn btn-ghost small" disabled={busy || data.login.length < 2}
                      title={data.login.length < 2 ? 'Gardez au moins une photo' : undefined} onClick={() => setConfirm({ remove: p })}>Retirer</button>
                    <button type="button" className="btn btn-ghost small" disabled={busy || i === 0} aria-label={`Photo ${i + 1} : monter`} onClick={() => move(i, -1)}>↑ Monter</button>
                    <button type="button" className="btn btn-ghost small" disabled={busy || i === data.login.length - 1} aria-label={`Photo ${i + 1} : descendre`} onClick={() => move(i, 1)}>↓ Descendre</button>
                  </div>
                </li>
              ))}
            </ol>
            <div className="appearance-actions">
              <button type="button" className="btn btn-primary small" disabled={busy || data.login.length >= data.max_login_photos} onClick={() => loginInput.current?.click()}>
                <Icon name="image" size={15} /> Ajouter une photo
              </button>
              {data.login_changed && <button type="button" className="btn btn-ghost small" disabled={busy} onClick={() => setConfirm('login')}>Rétablir les photos d’origine</button>}
              <input ref={loginInput} type="file" accept="image/*" hidden onChange={pick('login-new')} />
            </div>
          </section>

          <SettingsEditor only={['textes']} onSaved={() => { void refreshAppearance(true) }} />
        </div>
      )}

      {framing && (
        <ImageFramer
          file={'file' in framing ? framing.file : undefined}
          src={'src' in framing ? framing.src : undefined}
          x={'x' in framing ? framing.x : 50} y={'y' in framing ? framing.y : 50}
          previews={framing.target === 'home' ? HOME_PREVIEWS : LOGIN_PREVIEWS}
          title={framing.target === 'home' ? 'Image de l’accueil' : framing.target === 'login-new' ? 'Nouvelle photo de l’écran de connexion' : 'Recadrer la photo'}
          onCancel={() => setFraming(null)}
          onDone={(r) => saveFrame(framing, r)} />
      )}

      {confirm === 'home' && (
        <ConfirmDialog title="Rétablir l’image d’origine ?" text="L’image envoyée est supprimée ; l’accueil reprend son image d’origine."
          confirmLabel="Rétablir" onClose={() => setConfirm(null)}
          onConfirm={async () => { try { applied(await api<Payload>('/admin/appearance/home', { method: 'DELETE', toast: false })) } catch (err) { fail(err) } }} />
      )}
      {confirm === 'login' && (
        <ConfirmDialog title="Rétablir les photos d’origine ?" text="Les photos envoyées sont supprimées ; l’écran de connexion reprend ses quatre photos d’origine."
          confirmLabel="Rétablir" onClose={() => setConfirm(null)}
          onConfirm={async () => { try { applied(await api<Payload>('/admin/appearance/login', { method: 'DELETE', toast: false })) } catch (err) { fail(err) } }} />
      )}
      {confirm && typeof confirm === 'object' && (
        <ConfirmDialog title="Retirer cette photo ?" danger
          text={confirm.remove.builtin ? 'Elle ne s’affichera plus sur l’écran de connexion (elle reviendra avec « Rétablir les photos d’origine »).' : 'Elle ne s’affichera plus sur l’écran de connexion et sera supprimée.'}
          confirmLabel="Retirer" onClose={() => setConfirm(null)}
          onConfirm={() => arrange(data!.login.filter((p) => p.id !== confirm.remove.id))} />
      )}
    </AppLayout>
  )
}
