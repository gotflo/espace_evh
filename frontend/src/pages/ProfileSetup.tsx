import { useEffect, useRef, useState, type ChangeEvent, type FormEvent } from 'react'
import { useNavigate } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { getReference } from '../api/reference'
import { useAuth } from '../auth/AuthContext'
import type { Department, Profile, Tribe } from '../types'
import { Brand } from '../components/Brand'
import { DepartmentPicker } from '../components/DepartmentPicker'

const MONTHS = ['Janvier', 'Fevrier', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Aout', 'Septembre', 'Octobre', 'Novembre', 'Decembre']

export default function ProfileSetup() {
  const { profile, setProfile, profileCompleted } = useAuth()
  const navigate = useNavigate()

  const [tribes, setTribes] = useState<Tribe[]>([])
  const [departments, setDepartments] = useState<Department[]>([])

  const [firstName, setFirstName] = useState(profile?.first_name ?? '')
  const [lastName, setLastName] = useState(profile?.last_name ?? '')
  const [birthDay, setBirthDay] = useState(profile?.birth_day?.toString() ?? '')
  const [birthMonth, setBirthMonth] = useState(profile?.birth_month?.toString() ?? '')
  const [gender, setGender] = useState(profile?.gender ?? '')
  const [tribeId, setTribeId] = useState(profile?.tribe_id?.toString() ?? '')
  const [deptIds, setDeptIds] = useState<number[]>(profile?.departments?.map((d) => d.id) ?? [])
  const [photoFile, setPhotoFile] = useState<File | null>(null)
  const [photoPreview, setPhotoPreview] = useState<string | null>(profile?.photo_url ?? null)

  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const fileRef = useRef<HTMLInputElement>(null)

  useEffect(() => {
    getReference()
      .then((r) => { setTribes(r.tribes); setDepartments(r.departments) })
      .catch(() => { /* listes vides si echec */ })
  }, [])

  function onPickPhoto(e: ChangeEvent<HTMLInputElement>) {
    const file = e.target.files?.[0]
    if (!file) return
    setPhotoFile(file)
    setPhotoPreview(URL.createObjectURL(file))
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
      if (gender) fd.append('gender', gender)
      if (tribeId) fd.append('tribe_id', tribeId)
      deptIds.forEach((id) => fd.append('department_ids[]', String(id)))
      if (photoFile) fd.append('photo', photoFile)

      const res = await api<{ profile: Profile }>('/profile', { method: 'POST', body: fd })
      setProfile(res.profile)
      navigate('/tableau-de-bord', { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur reseau.')
    } finally {
      setBusy(false)
    }
  }

  const initials = (firstName[0] ?? '') + (lastName[0] ?? '')

  return (
    <div className="screen">
      <div className="card wide">
        {profileCompleted && (
          <button className="btn-link" style={{ marginBottom: '1rem', display: 'inline-block' }}
            onClick={() => navigate('/tableau-de-bord')}>
            ← Retour au tableau de bord
          </button>
        )}
        <Brand subtitle={profileCompleted ? 'Modifier mon profil' : 'Completer mon profil'} />

        <h2 className="section-title">{profileCompleted ? 'Mon profil' : 'Bienvenue !'}</h2>
        <p className="section-sub">
          {profileCompleted ? 'Mettez a jour vos informations.' : 'Quelques informations pour finaliser votre compte.'}
        </p>

        {error && <div className="alert alert-error">{error}</div>}

        <form onSubmit={submit}>
          <div className="field">
            <label>Photo</label>
            <div className="photo-picker">
              {photoPreview
                ? <img className="photo-preview" src={photoPreview} alt="" />
                : <div className="photo-preview">{initials.toUpperCase() || '📷'}</div>}
              <div>
                <button type="button" className="btn btn-ghost small" onClick={() => fileRef.current?.click()}>
                  Choisir une photo
                </button>
                <input ref={fileRef} type="file" accept="image/*" hidden onChange={onPickPhoto} />
                <p className="helper">Facultatif. JPG ou PNG, 5 Mo max.</p>
              </div>
            </div>
          </div>

          <div className="field-row">
            <div className="field">
              <label htmlFor="fn">Prenoms</label>
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
                <select className="select" value={birthDay} onChange={(e) => setBirthDay(e.target.value)}>
                  <option value="">Jour</option>
                  {Array.from({ length: 31 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
                </select>
                <select className="select" value={birthMonth} onChange={(e) => setBirthMonth(e.target.value)}>
                  <option value="">Mois</option>
                  {MONTHS.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
                </select>
              </div>
            </div>
            <div className="field">
              <label htmlFor="g">Genre</label>
              <select id="g" className="select" value={gender} onChange={(e) => setGender(e.target.value)}>
                <option value="">Non precise</option>
                <option value="homme">Homme</option>
                <option value="femme">Femme</option>
                <option value="autre">Autre</option>
              </select>
            </div>
          </div>

          <div className="field">
            <label htmlFor="tribe">Tribu</label>
            <select id="tribe" className="select" value={tribeId} onChange={(e) => setTribeId(e.target.value)}>
              <option value="">Aucune</option>
              {tribes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>

          <div className="field">
            <label>Departements <span className="helper" style={{ display: 'inline' }}>(plusieurs possibles)</span></label>
            <DepartmentPicker departments={departments} selected={deptIds} onChange={setDeptIds} />
          </div>

          <button className="btn btn-primary mt" disabled={busy || !firstName.trim() || !lastName.trim()}>
            {busy ? <span className="spinner" /> : 'Enregistrer mon profil'}
          </button>
        </form>
      </div>
    </div>
  )
}
