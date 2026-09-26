import { useEffect, useRef, useState, type ChangeEvent } from 'react'
import { api, ApiError } from '../api/client'
import { getReference } from '../api/reference'
import { useAuth } from '../auth/AuthContext'
import { AppLayout } from '../components/AppLayout'
import { DepartmentPicker } from '../components/DepartmentPicker'
import { CompletionCard } from '../components/profile/CompletionCard'
import { FamilySection } from '../components/profile/FamilySection'
import { TribeChange } from '../components/profile/TribeChange'
import type { Department, Profile, ProfileCompletion, SpiritualProfileData, Tribe } from '../types'

const CIVILITY = [['dr', 'Dr'], ['reverend', 'Révérend'], ['pasteur', 'Pasteur'], ['m', 'M.'], ['mme', 'Mme'], ['mlle', 'Mlle']]
const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre']
const TSHIRT = ['S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'XXXXL']
const HOLY_SPIRIT = [['oui', 'Oui'], ['non', 'Non'], ['je_ne_sais_pas', 'Je ne sais pas'], ['autre', 'Autre']]
const PRAYER_FREQ = [['quotidien', 'Quotidienne'], ['hebdomadaire', 'Quelques fois par semaine'], ['rare', 'Pas très souvent']]

type Tab = 'identite' | 'famille' | 'perso' | 'spirituel'

/** Onglet a ouvrir pour completer une information manquante. */
const TAB_FOR: Record<string, Tab> = {
  first_name: 'identite', last_name: 'identite', gender: 'identite', birthday: 'identite', tribe: 'identite', photo: 'identite',
  marital_status: 'famille', spouse: 'famille', wedding_date: 'famille', has_children: 'famille', children: 'famille',
  email: 'perso',
}

const emptySpiritual: SpiritualProfileData = {
  conversion_year: null, conversion_verse: null, baptism_immersion_date: null, baptism_holy_spirit: null,
  speaks_tongues: null, tongues_since_year: null, active_member: null, prayer_frequency: null,
  gifts_known: null, gifts_detail: null, last_prayer_subject: null, joyful_service: null, focus_effort: null,
}

export default function MyProfile() {
  const { profile, setProfile, roles } = useAuth()
  const [tab, setTab] = useState<Tab>(() => (window.location.hash === '#famille' ? 'famille' : 'identite'))
  const [completion, setCompletion] = useState<ProfileCompletion | null>(null)
  const [tribes, setTribes] = useState<Tribe[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const fileRef = useRef<HTMLInputElement>(null)

  // --- Etat identite + infos perso ---
  const [firstName, setFirstName] = useState(profile?.first_name ?? '')
  const [lastName, setLastName] = useState(profile?.last_name ?? '')
  const [birthDay, setBirthDay] = useState(profile?.birth_day?.toString() ?? '')
  const [birthMonth, setBirthMonth] = useState(profile?.birth_month?.toString() ?? '')
  const [gender, setGender] = useState(profile?.gender === 'homme' || profile?.gender === 'femme' ? profile.gender : '')
  const [tribeId, setTribeId] = useState(profile?.tribe_id?.toString() ?? '')
  const [deptIds, setDeptIds] = useState<number[]>(profile?.departments?.map((d) => d.id) ?? [])
  const [photoFile, setPhotoFile] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(profile?.photo_url ?? null)
  const [email, setEmail] = useState(profile?.email ?? '')
  const [marital, setMarital] = useState(profile?.marital_status ?? '')
  const [civility, setCivility] = useState(profile?.civility ?? '')
  const [weddingDay, setWeddingDay] = useState(profile?.wedding_day?.toString() ?? '')
  const [weddingMonth, setWeddingMonth] = useState(profile?.wedding_month?.toString() ?? '')
  const [tshirt, setTshirt] = useState(profile?.tshirt_size ?? '')
  const [yearVerse, setYearVerse] = useState(profile?.year_verse ?? '')

  // --- Etat vie spirituelle ---
  const [sp, setSp] = useState<SpiritualProfileData>(emptySpiritual)
  const setSpField = <K extends keyof SpiritualProfileData>(k: K, v: SpiritualProfileData[K]) => setSp((p) => ({ ...p, [k]: v }))

  useEffect(() => {
    getReference().then((r) => { setTribes(r.tribes); setDepartments(r.departments) }).catch(() => {})
    api<{ spiritual: SpiritualProfileData }>('/me/spiritual-profile').then((r) => setSp({ ...emptySpiritual, ...r.spiritual })).catch(() => {})
    api<{ completion: ProfileCompletion | null }>('/profile').then((r) => setCompletion(r.completion)).catch(() => {})
  }, [])

  function onPickPhoto(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setPhotoFile(file); setPhotoPreview(URL.createObjectURL(file))
  }


  async function savePersonal() {
    if (gender !== 'homme' && gender !== 'femme') {
      setError('Veuillez choisir Homme ou Femme.')
      return
    }
    setError(''); setBusy(true)
    try {
      const fd = new FormData()
      fd.append('first_name', firstName)
      fd.append('last_name', lastName)
      if (birthDay) fd.append('birth_day', birthDay)
      if (birthMonth) fd.append('birth_month', birthMonth)
      fd.append('gender', gender)
      if (tribeId && !profile?.tribe_id) fd.append('tribe_id', tribeId)
      if (profile?.tribe_id) fd.append('tribe_id', String(profile.tribe_id))
      deptIds.forEach((id) => fd.append('department_ids[]', String(id)))
      if (photoFile) fd.append('photo', photoFile)
      if (email) fd.append('email', email)
      if (marital) fd.append('marital_status', marital)
      if (civility) fd.append('civility', civility)
      if (marital === 'marie' && weddingDay) fd.append('wedding_day', weddingDay)
      if (marital === 'marie' && weddingMonth) fd.append('wedding_month', weddingMonth)
      if (tshirt) fd.append('tshirt_size', tshirt)
      if (yearVerse) fd.append('year_verse', yearVerse)
      const res = await api<{ profile: Profile; completion: ProfileCompletion }>('/profile', { method: 'POST', body: fd })
      setProfile(res.profile); setPhotoFile(null); setCompletion(res.completion)
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function saveSpiritual() {
    setError(''); setBusy(true)
    try {
      const body: Record<string, unknown> = { ...sp }
      Object.keys(body).forEach((k) => { if (body[k] === '' ) body[k] = null })
      await api('/me/spiritual-profile', { method: 'PUT', body })
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  const initials = ((firstName[0] ?? '') + (lastName[0] ?? '')).toUpperCase()
  const mainRole = roles[0]?.name ?? 'Fidèle'

  return (
    <AppLayout title="Mon profil" subtitle="Vos informations personnelles et spirituelles">
      {error && <div className="alert alert-error">{error}</div>}

      {/* En-tete profil */}
      <section className="profile-hero">
        {photoPreview
          ? <img className="profile-hero-photo" src={photoPreview} alt="" />
          : <span className="profile-hero-photo">{initials || '🙂'}</span>}
        <div className="profile-hero-info">
          <h2>{profile?.full_name || 'Mon profil'}</h2>
          <div className="profile-hero-meta">
            {profile?.matricule && <span className="chip">{profile.matricule}</span>}
            <span className="chip">{mainRole}</span>
            {profile?.tribe?.name && <span className="chip">Tribu {profile.tribe.name}</span>}
          </div>
        </div>
      </section>

      <CompletionCard completion={completion} onGo={(key) => setTab(TAB_FOR[key] ?? 'identite')} />

      {/* Onglets */}
      <div className="tabs2">
        <button className={`tab2 ${tab === 'identite' ? 'on' : ''}`} onClick={() => setTab('identite')}>Identité</button>
        <button className={`tab2 ${tab === 'famille' ? 'on' : ''}`} onClick={() => setTab('famille')}>Famille</button>
        <button className={`tab2 ${tab === 'perso' ? 'on' : ''}`} onClick={() => setTab('perso')}>Infos personnelles</button>
        <button className={`tab2 ${tab === 'spirituel' ? 'on' : ''}`} onClick={() => setTab('spirituel')}>Vie spirituelle</button>
      </div>

      {tab === 'identite' && (
        <section className="panel form-panel">
          <div className="field">
            <label>Photo</label>
            <div className="photo-picker">
              {photoPreview
                ? <img className="photo-preview" src={photoPreview} alt="" />
                : <div className="photo-preview">{initials || '📷'}</div>}
              <div>
                <button type="button" className="btn btn-ghost small" onClick={() => fileRef.current?.click()}>Choisir une photo</button>
                <input ref={fileRef} type="file" accept="image/*" hidden onChange={onPickPhoto} />
                <p className="helper">JPG ou PNG, 5 Mo max.</p>
              </div>
            </div>
          </div>
          <div className="field-row">
            <div className="field"><label>Prénoms</label><input className="input" value={firstName} onChange={(e) => setFirstName(e.target.value)} /></div>
            <div className="field"><label>Nom</label><input className="input" value={lastName} onChange={(e) => setLastName(e.target.value)} /></div>
          </div>
          <div className="field-row">
            <div className="field"><label>Anniversaire <span className="helper" style={{ display: 'inline' }}>(jour / mois)</span></label>
              <div className="field-row" style={{ gap: '0.5rem' }}>
                <select className="select" value={birthDay} onChange={(e) => setBirthDay(e.target.value)}>
                  <option value="">Jour</option>{Array.from({ length: 31 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
                <select className="select" value={birthMonth} onChange={(e) => setBirthMonth(e.target.value)}>
                  <option value="">Mois</option>{MONTHS.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
                </select>
              </div>
            </div>
            <div className="field"><label>Genre</label>
              <select className="select" required value={gender} onChange={(e) => setGender(e.target.value)}>
                <option value="" disabled hidden>Choisir</option><option value="homme">Homme</option><option value="femme">Femme</option>
              </select>
            </div>
          </div>
          {profile?.tribe_id ? (
            <TribeChange currentTribe={profile.tribe} tribes={tribes} />
          ) : (
            <div className="field"><label>Tribu</label>
              <select className="select" value={tribeId} onChange={(e) => setTribeId(e.target.value)}>
                <option value="">Aucune</option>{tribes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
              </select>
              <p className="helper">Choisissez avec soin : un changement ultérieur passe par une demande validée par les responsables.</p>
            </div>
          )}
          <div className="field"><label>Départements <span className="helper" style={{ display: 'inline' }}>(plusieurs possibles)</span></label>
            <DepartmentPicker departments={departments} selected={deptIds} onChange={setDeptIds} />
          </div>
          <button className="btn btn-primary" disabled={busy || !firstName.trim() || !lastName.trim()} onClick={savePersonal}>
            {busy ? <span className="spinner" /> : 'Enregistrer'}
          </button>
        </section>
      )}

      {tab === 'famille' && (
        <section className="panel form-panel" id="famille">
          <FamilySection marital={marital} onMarital={setMarital} weddingDay={weddingDay} weddingMonth={weddingMonth}
            onWedding={(d, m) => { setWeddingDay(d); setWeddingMonth(m) }} onSaveProfile={savePersonal} busy={busy}
            hasChildrenInitial={profile?.has_children ?? null} />
        </section>
      )}

      {tab === 'perso' && (
        <section className="panel form-panel">
          <div className="field"><label>Mon verset de l'année</label>
            <input className="input" value={yearVerse} onChange={(e) => setYearVerse(e.target.value)} placeholder="Ex. Philippiens 4:13" />
          </div>
          <div className="field"><label>E-mail</label><input className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} /></div>
          <div className="field-row">
            <div className="field"><label>Civilité</label>
              <select className="select" value={civility} onChange={(e) => setCivility(e.target.value)}>
                <option value="">Non précisé</option>{CIVILITY.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            </div>
            <div className="field"><label>Taille de t-shirt</label>
              <select className="select" value={tshirt} onChange={(e) => setTshirt(e.target.value)}>
                <option value="">Non précisé</option>{TSHIRT.map((s) => <option key={s} value={s}>{s}</option>)}
              </select>
            </div>
          </div>
          <button className="btn btn-primary" disabled={busy || !firstName.trim() || !lastName.trim()} onClick={savePersonal}>
            {busy ? <span className="spinner" /> : 'Enregistrer'}
          </button>
        </section>
      )}

      {tab === 'spirituel' && (
        <section className="panel form-panel">
          <div className="field-row">
            <div className="field"><label>Année de conversion</label>
              <input className="input" type="number" min="1900" max={new Date().getFullYear()} value={sp.conversion_year ?? ''} onChange={(e) => setSpField('conversion_year', e.target.value ? Number(e.target.value) : null)} />
            </div>
            <div className="field"><label>Passage biblique de ma conversion</label>
              <input className="input" value={sp.conversion_verse ?? ''} onChange={(e) => setSpField('conversion_verse', e.target.value)} placeholder="Ex. Jean 3:16" />
            </div>
          </div>

          <div className="field-row">
            <div className="field"><label>Date de baptême par immersion</label>
              <input className="input" type="date" value={sp.baptism_immersion_date ?? ''} onChange={(e) => setSpField('baptism_immersion_date', e.target.value || null)} />
            </div>
            <div className="field"><label>Baptisé(e) du Saint-Esprit</label>
              <select className="select" value={sp.baptism_holy_spirit ?? ''} onChange={(e) => setSpField('baptism_holy_spirit', e.target.value || null)}>
                <option value="">Non précisé</option>{HOLY_SPIRIT.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
              </select>
            </div>
          </div>

          <div className="field">
            <label>Avez-vous fait l'expérience du parler en langues ?</label>
            <div className="pill-choices">
              <button type="button" className={`pill-choice ${sp.speaks_tongues === true ? 'on' : ''}`} onClick={() => setSpField('speaks_tongues', true)}>Oui</button>
              <button type="button" className={`pill-choice ${sp.speaks_tongues === false ? 'on' : ''}`} onClick={() => setSpField('speaks_tongues', false)}>Non</button>
            </div>
          </div>
          {sp.speaks_tongues === true && (
            <div className="field"><label>Depuis quelle année ?</label>
              <input className="input" type="number" min="1900" max={new Date().getFullYear()} value={sp.tongues_since_year ?? ''} onChange={(e) => setSpField('tongues_since_year', e.target.value ? Number(e.target.value) : null)} />
            </div>
          )}

          <div className="field">
            <label>Êtes-vous membre actif dans votre assemblée ?</label>
            <div className="pill-choices">
              <button type="button" className={`pill-choice ${sp.active_member === true ? 'on' : ''}`} onClick={() => setSpField('active_member', true)}>Oui</button>
              <button type="button" className={`pill-choice ${sp.active_member === false ? 'on' : ''}`} onClick={() => setSpField('active_member', false)}>Non</button>
            </div>
          </div>

          <div className="field"><label>Fréquence de prière et de méditation de la Parole</label>
            <select className="select" value={sp.prayer_frequency ?? ''} onChange={(e) => setSpField('prayer_frequency', e.target.value || null)}>
              <option value="">Non précisé</option>{PRAYER_FREQ.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
            </select>
          </div>

          <div className="field">
            <label>Connaissez-vous vos dons et talents ?</label>
            <div className="pill-choices">
              <button type="button" className={`pill-choice ${sp.gifts_known === true ? 'on' : ''}`} onClick={() => setSpField('gifts_known', true)}>Oui</button>
              <button type="button" className={`pill-choice ${sp.gifts_known === false ? 'on' : ''}`} onClick={() => setSpField('gifts_known', false)}>Non</button>
            </div>
          </div>
          {sp.gifts_known === true && (
            <div className="field"><label>Précisez vos dons</label>
              <textarea className="input" rows={2} value={sp.gifts_detail ?? ''} onChange={(e) => setSpField('gifts_detail', e.target.value)} />
            </div>
          )}

          <div className="field"><label>Dernier sujet pour lequel vous avez cherché l'exaucement</label>
            <input className="input" value={sp.last_prayer_subject ?? ''} onChange={(e) => setSpField('last_prayer_subject', e.target.value)} placeholder="En une phrase" />
          </div>
          <div className="field"><label>Qu'aimez-vous faire avec joie et sans peine dans le Seigneur ?</label>
            <textarea className="input" rows={2} value={sp.joyful_service ?? ''} onChange={(e) => setSpField('joyful_service', e.target.value)} />
          </div>
          <div className="field"><label>À quoi pensez-vous le plus, et pour quoi fournissez-vous le plus d'effort ?</label>
            <textarea className="input" rows={2} value={sp.focus_effort ?? ''} onChange={(e) => setSpField('focus_effort', e.target.value)} />
          </div>

          <button className="btn btn-primary" disabled={busy} onClick={saveSpiritual}>
            {busy ? <span className="spinner" /> : 'Enregistrer ma vie spirituelle'}
          </button>
        </section>
      )}
    </AppLayout>
  )
}
