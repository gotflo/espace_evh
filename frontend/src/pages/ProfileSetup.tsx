import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { getReference } from '../api/reference'
import { useAuth } from '../auth/AuthContext'
import type { Membership, Profile, Tribe } from '../types'
import { AccountPending } from './AccountPending'
import { Brand } from '../components/Brand'
import { Icon } from '../components/Icon'
import { useDirty } from '../utils/dirty'
import { toast } from '../toast'
import { PhotoCropper } from '../components/PhotoCropper'
import { scrollPageTo } from '../utils/pageScroll'

const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre']

export default function ProfileSetup() {
  const { profile, setProfile, profileCompleted, registration, membership, setMembership } = useAuth()
  const navigate = useNavigate()

  const [tribes, setTribes] = useState<Tribe[]>([])

  const [firstName, setFirstName] = useState(profile?.first_name ?? '')
  const [lastName, setLastName] = useState(profile?.last_name ?? '')
  const [birthDay, setBirthDay] = useState(profile?.birth_day?.toString() ?? '')
  const [birthMonth, setBirthMonth] = useState(profile?.birth_month?.toString() ?? '')
  const [gender, setGender] = useState(profile?.gender === 'homme' || profile?.gender === 'femme' ? profile.gender : '')
  // Courriel (facultatif, conseille) : saisi a l'inscription, il evite le bandeau « Recevez vos rappels par courriel ».
  const [email, setEmail] = useState(profile?.email ?? '')
  const emailInvalid = email.trim() !== '' && !/^\S+@\S+\.\S+$/.test(email.trim())
  const [tribeId, setTribeId] = useState(profile?.tribe_id?.toString() ?? '')
  const [photoFile, setPhotoFile] = useState<File | null>(null)
  const [cropping, setCropping] = useState<File | null>(null)
  const pickedRef = useRef<File | null>(null) // photo d'origine, pour reprendre le cadrage
  const [photoPreview, setPhotoPreview] = useState<string | null>(profile?.photo_url ?? null)

  const [error, setError] = useState('')
  // En modification : bouton actif seulement si quelque chose a change.
  const edits = useDirty({ firstName, lastName, birthDay, birthMonth, gender, email, tribeId, photo: photoFile?.name ?? null })
  const [busy, setBusy] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  // Compte valide pendant que l'ecran d'attente est ouvert : l'espace s'ouvre tout seul.
  const wasWaiting = useRef(membership.status !== 'approved')
  useEffect(() => {
    if (membership.status !== 'approved' || !wasWaiting.current) return
    wasWaiting.current = false
    toast.success('Votre compte est validé. Bienvenue !')
    navigate('/tableau-de-bord', { replace: true })
  }, [membership.status, navigate])

  useEffect(() => {
    getReference()
      .then((r) => setTribes(r.tribes))
      .catch(() => { /* listes vides si echec */ })
  }, [])

  // Membre deja inscrit a qui il ne manque que la tribu : on l'amene directement au choix.
  useEffect(() => {
    if (!registration.reopened || tribes.length === 0) return
    document.getElementById('tribu')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }, [registration.reopened, tribes.length])

  // Photo choisie : cadrage d'abord (PhotoCropper), puis la version reduite remplace l'apercu.
  function onPickPhoto(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    e.target.value = '' // la meme photo peut etre choisie a nouveau
    if (file) { pickedRef.current = file; setCropping(file) }
  }
  function onPhotoReady(photo: File) {
    setCropping(null)
    setPhotoFile(photo)
    setPhotoPreview((old) => { if (old?.startsWith('blob:')) URL.revokeObjectURL(old); return URL.createObjectURL(photo) })
  }

  async function submit(e: FormEvent) {
    e.preventDefault()
    setError(''); setBusy(true)
    try {
      const fd = new FormData()
      fd.append('first_name', firstName)
      fd.append('last_name', lastName)
      if (birthDay) fd.append('birth_day', birthDay)
      if (birthMonth) fd.append('birth_month', birthMonth)
      fd.append('gender', gender)
      fd.append('email', email.trim())
      if (tribeId) fd.append('tribe_id', tribeId)
      if (photoFile) fd.append('photo', photoFile)

      const res = await api<{ profile: Profile; membership?: Membership }>('/profile', { method: 'POST', body: fd })
      setProfile(res.profile)
      // Nouveau compte : l'inscription part chez le pasteur, l'ecran d'attente s'affiche.
      if (res.membership && res.membership.status !== 'approved') {
        setMembership(res.membership)
        scrollPageTo(0)
        return
      }
      // Compte valide (ou valide aussitot en mode d'essai local) : l'espace s'ouvre.
      if (res.membership) setMembership(res.membership)
      navigate('/tableau-de-bord', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Connexion impossible. Vérifiez votre réseau puis réessayez.')
    } finally {
      setBusy(false)
    }
  }

  // Inscription envoyee (ou refusee) : ecran d'attente du pasteur a la place du formulaire.
  if (membership.status === 'rejected' || (membership.status === 'pending' && membership.submitted)) return <AccountPending />

  const initials = (firstName[0] ?? '') + (lastName[0] ?? '')
  const tribeRequired = registration.tribe_required
  const tribeLocked = !!profile?.tribe_id
  // Ce qui manque encore pour terminer l'inscription (affiche sous le bouton).
  const missing = [
    !firstName.trim() && 'vos prénoms', !lastName.trim() && 'votre nom', !gender && 'votre genre',
    tribeRequired && !tribeId && 'votre tribu',
  ].filter(Boolean) as string[]

  return (
    <div className="screen">
      <div className="card wide">
        {profileCompleted && (
          <button className="btn-link" style={{ marginBottom: '1rem', display: 'inline-block' }}
            onClick={() => navigate('/tableau-de-bord')}>
            ← Retour au tableau de bord
          </button>
        )}
        <Brand subtitle={profileCompleted ? 'Modifier mon profil' : 'Compléter mon profil'} />

        <h2 className="section-title">{profileCompleted ? 'Mon profil' : registration.reopened ? 'Une dernière étape' : 'Bienvenue !'}</h2>
        <p className="section-sub">
          {profileCompleted ? 'Mettez à jour vos informations.'
            : registration.reopened ? 'Choisissez votre tribu pour retrouver votre espace. Toutes vos informations sont conservées.'
              : membership.status === 'pending' ? 'Quelques informations, puis le pasteur validera votre compte.'
                : 'Quelques informations pour finaliser votre compte.'}
        </p>

        {error && <div className="alert alert-error">{error}</div>}

        <form onSubmit={submit}>
          <div className="field">
            <label>Photo</label>
            <div className="photo-picker">
              {photoPreview
                ? <img className="photo-preview" src={photoPreview} alt="" />
                : <div className="photo-preview">{initials.toUpperCase() || <Icon name="profile" size={24} />}</div>}
              <div>
                <button type="button" className="btn btn-ghost small" onClick={() => fileRef.current?.click()}>
                  Choisir une photo
                </button>
                <input ref={fileRef} type="file" accept="image/*" hidden onChange={onPickPhoto} />
                {cropping && <PhotoCropper file={cropping} onCancel={() => setCropping(null)} onDone={onPhotoReady} />}
                {photoFile && pickedRef.current && (
                  <button type="button" className="btn-link photo-reframe" onClick={() => setCropping(pickedRef.current)}>Ajuster le cadrage</button>
                )}
                <p className="helper">Facultatif. Toute photo convient : vous la cadrez, puis elle est allégée automatiquement.</p>
              </div>
            </div>
          </div>

          <div className="field-row">
            <div className="field">
              <label htmlFor="fn">Prénoms</label>
              <input id="fn" className="input" value={firstName} onChange={(e) => setFirstName(e.target.value)} required />
            </div>
            <div className="field">
              <label htmlFor="ln">Nom</label>
              <input id="ln" className="input" value={lastName} onChange={(e) => setLastName(e.target.value)} required />
            </div>
          </div>

          <div className="field-row">
            <div className="field">
              <label>Anniversaire <span className="helper" style={{ display: 'inline' }}>(jour et mois)</span></label>
              <div className="field-row" style={{ gap: '0.5rem' }}>
                <select className="select" aria-label="Jour de naissance" value={birthDay} onChange={(e) => setBirthDay(e.target.value)}>
                  <option value="">Jour</option>
                  {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
                <select className="select" aria-label="Mois de naissance" value={birthMonth} onChange={(e) => setBirthMonth(e.target.value)}>
                  <option value="">Mois</option>
                  {MONTHS.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
                </select>
              </div>
            </div>
            <div className="field">
              <label htmlFor="g">Genre</label>
              <select id="g" className="select" required value={gender} onChange={(e) => setGender(e.target.value)}>
                <option value="" disabled hidden>Choisir</option>
                <option value="homme">Homme</option>
                <option value="femme">Femme</option>
              </select>
            </div>
          </div>

          <div className="field">
            <label htmlFor="em">Courriel <span className="helper" style={{ display: 'inline' }}>(conseillé)</span></label>
            <input id="em" className="input" type="email" inputMode="email" autoComplete="email" maxLength={150}
              placeholder="exemple : marie@gmail.com" value={email} onChange={(e) => setEmail(e.target.value)} />
            {emailInvalid
              ? <p className="helper" style={{ color: 'var(--red)' }}>Cette adresse ne semble pas valide.</p>
              : <p className="helper">Pour recevoir la validation de votre compte, votre devoir et vos rappels.</p>}
          </div>

          {/* Tribu : obligatoire a l'inscription ; ensuite, elle ne change que sur demande. */}
          <div className="field" id="tribu">
            <label id="tribe-label">Ma tribu {tribeRequired && <span className="req-star" aria-hidden>*</span>}</label>
            {tribeLocked ? (
              <div className="tribe-locked">
                <strong>{profile?.tribe?.name ?? tribes.find((t) => String(t.id) === tribeId)?.name}</strong>
                <span className="helper">Pour changer de tribu, faites une demande depuis Mon profil.</span>
              </div>
            ) : (
              <>
                <div className="tribe-grid" role="radiogroup" aria-labelledby="tribe-label">
                  {tribes.map((t) => (
                    <button key={t.id} type="button" role="radio" aria-checked={tribeId === String(t.id)}
                      className={`tribe-option ${tribeId === String(t.id) ? 'on' : ''}`} onClick={() => setTribeId(String(t.id))}>
                      {t.name}
                    </button>
                  ))}
                  {!tribeRequired && (
                    <button type="button" role="radio" aria-checked={tribeId === ''} className={`tribe-option muted ${tribeId === '' ? 'on' : ''}`} onClick={() => setTribeId('')}>
                      Aucune
                    </button>
                  )}
                </div>
                <p className="helper">{tribeRequired
                  ? 'Obligatoire. Vous ne pourrez plus la changer vous-même : un changement se fait ensuite sur demande.'
                  : 'Facultatif pour les pasteurs et les Assistants Pasteurs.'}</p>
              </>
            )}
          </div>

          <p className="helper service-hint"><Icon name="serve" size={14} /> Pour servir dans un département, faites votre demande depuis l’onglet « Service » une fois votre inscription terminée : le responsable du département la validera.</p>

          <button className="btn btn-primary mt" disabled={busy || missing.length > 0 || emailInvalid || (profileCompleted && !edits.dirty)}>
            {busy ? <span className="spinner" /> : profileCompleted ? 'Enregistrer mon profil' : membership.status === 'pending' ? 'Envoyer mon inscription' : 'Terminer mon inscription'}
          </button>
          {!profileCompleted && missing.length > 0 && (
            <p className="helper center">Encore à renseigner : {missing.join(', ')}.</p>
          )}
        </form>
      </div>
    </div>
  )
}
