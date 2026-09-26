import { useCallback, useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { api, ApiError } from '../../api/client'
import { getReference } from '../../api/reference'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import { DepartmentPicker } from '../../components/DepartmentPicker'
import { SpiritualPanel } from '../../components/SpiritualPanel'
import { EvaluationsPanel } from '../../components/EvaluationsPanel'
import { MemberFissPanel } from '../../components/MemberFissPanel'
import { MemberCompletion, MemberFamily, MemberFissHistory } from '../../components/MemberExtras'
import type { Department, Gem, MemberDetailData, MemberListItem, RoleOption, Tribe } from '../../types'

export default function MemberDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { hasPermission } = useAuth()
  const canAssign = hasPermission('roles.assign')
  const canEdit = hasPermission('members.edit')
  const canViewSpiritual = hasPermission('spiritual.view')
  const canManageEval = hasPermission('evaluations.manage')
  const canRecordSpiritual = hasPermission('spiritual.record')
  const canFissHistory = hasPermission('fiss.review') || hasPermission('audit.view')

  const [data, setData] = useState<MemberDetailData | null>(null)
  const [roleOptions, setRoleOptions] = useState<RoleOption[]>([])
  const [tribes, setTribes] = useState<Tribe[]>([])
  const [departments, setDepartments] = useState<Department[]>([])
  const [gems, setGems] = useState<Gem[]>([])
  const [memberOptions, setMemberOptions] = useState<MemberListItem[] | null>(null)
  const [memberQuery, setMemberQuery] = useState('')

  const [roleKey, setRoleKey] = useState('')
  const [scopeId, setScopeId] = useState('')
  const [belongTribe, setBelongTribe] = useState('')
  const [belongGem, setBelongGem] = useState('')
  const [belongDepts, setBelongDepts] = useState<number[]>([])
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api<MemberDetailData>(`/admin/members/${id}`).then((d) => {
      setData(d)
      setBelongTribe(d.profile?.tribe_id?.toString() ?? '')
      setBelongGem(d.profile?.gem_id?.toString() ?? '')
      setBelongDepts(d.profile?.departments?.map((x) => x.id) ?? [])
    }).catch(() => setData(null))
  }, [id])

  useEffect(() => { load() }, [load])

  useEffect(() => {
    if (canAssign) {
      api<{ roles: RoleOption[] }>('/admin/roles').then((r) => setRoleOptions(r.roles)).catch(() => {})
    }
    if (canAssign || canEdit) {
      getReference().then((r) => { setTribes(r.tribes); setDepartments(r.departments); setGems(r.gems) }).catch(() => {})
    }
  }, [canAssign, canEdit])

  const selectedRole = roleOptions.find((r) => r.key === roleKey)
  const needsScope = selectedRole && selectedRole.scope_kind !== 'none'

  // Role « fidele precis » : liste des membres pour choisir le fidele confie.
  useEffect(() => {
    if (selectedRole?.scope_kind === 'member' && memberOptions === null) {
      api<{ members: MemberListItem[] }>('/admin/members').then((r) => setMemberOptions(r.members)).catch(() => setMemberOptions([]))
    }
  }, [selectedRole, memberOptions])
  const q = memberQuery.trim().toLowerCase()
  const memberChoices = (memberOptions ?? [])
    .filter((m) => String(m.user_id) !== id && (!q || m.full_name.toLowerCase().includes(q) || (m.phone ?? '').includes(q)))
    .slice(0, 50)

  async function assign() {
    setError(''); setBusy(true)
    try {
      await api(`/admin/members/${id}/roles`, {
        method: 'POST',
        body: { role_key: roleKey, scope_id: needsScope ? Number(scopeId) : null },
      })
      setRoleKey(''); setScopeId(''); load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  async function removeRole(assignmentId: number) {
    setError('')
    try { await api(`/admin/members/${id}/roles/${assignmentId}`, { method: 'DELETE' }); load() }
    catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  async function setActivity(status: 'active' | 'inactive' | 'auto') {
    setError('')
    try {
      await api(`/admin/members/${id}/status`, { method: 'PATCH', body: { status } })
      load()
    } catch (err) { setError(err instanceof ApiError ? err.firstMessage : 'Erreur.') }
  }

  async function saveBelonging() {
    setError(''); setBusy(true)
    try {
      await api(`/admin/members/${id}/belonging`, {
        method: 'PATCH',
        body: { tribe_id: belongTribe ? Number(belongTribe) : null, gem_id: belongGem ? Number(belongGem) : null, department_ids: belongDepts },
      })
      load()
    } catch (err) {
      setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setBusy(false) }
  }

  const back = <button className="btn btn-ghost small" onClick={() => navigate('/admin/membres')}>← Membres</button>

  if (!data) {
    return <AppLayout title="Fiche membre" actions={back}><span className="spinner" /></AppLayout>
  }

  const p = data.profile
  const sp = data.spiritual_profile
  // Consultation seule (ex. AP hors de ses tribus) : les actions sont masquees.
  const manage = data.can_manage
  const canEditHere = canEdit && manage
  const initials = ((p?.first_name?.[0] ?? '') + (p?.last_name?.[0] ?? '')).toUpperCase()

  return (
    <AppLayout title="Fiche membre" actions={back}>
      {error && <div className="alert alert-error">{error}</div>}
      {!manage && (
        <div className="readonly-banner" role="note">
          <span aria-hidden>👁️</span>
          <div>
            <strong>Consultation seule</strong>
            <span>Vous pouvez voir cette fiche mais pas la modifier.</span>
          </div>
        </div>
      )}

      <div className="detail-grid">
        {/* Colonne infos */}
        <section className="panel">
          <div className="member-hero">
            {p?.photo_url
              ? <img className="member-hero-photo" src={p.photo_url} alt="" />
              : <div className="member-hero-photo">{initials || '🙂'}</div>}
            <h2 className="member-hero-name">{p?.full_name || data.user.phone}</h2>
            <span className={`status-pill ${data.user.activity === 'active' ? 'on' : 'off'}`}>
              {data.user.activity === 'active' ? 'Actif' : 'Inactif'}
            </span>
          </div>

          <div className="detail-list">
            {p?.matricule && <div className="detail-line"><span>Matricule</span><strong>{p.matricule}</strong></div>}
            <div className="detail-line"><span>Téléphone</span><strong>{data.user.phone}</strong></div>
            {p?.email && <div className="detail-line"><span>E-mail</span><strong>{p.email}</strong></div>}
            <div className="detail-line"><span>Tribu</span><strong>{p?.tribe?.name ?? 'Aucune'}</strong></div>
            {p?.gem?.name && <div className="detail-line"><span>GEM</span><strong>{p.gem.name}</strong></div>}
            <div className="detail-line">
              <span>Départements</span>
              <strong>{p?.departments && p.departments.length > 0 ? p.departments.map((d) => d.name).join(', ') : 'Aucun'}</strong>
            </div>
            {(p?.birth_day || p?.birth_month) && <div className="detail-line"><span>Anniversaire</span><strong>{p?.birth_day ?? '?'} / {p?.birth_month ?? '?'}</strong></div>}
            {p?.year_verse && <div className="detail-line"><span>Verset de l'année</span><strong>{p.year_verse}</strong></div>}
            {data.led_departments.length > 0 && <div className="detail-line"><span>Responsable de</span><strong>{data.led_departments.map((d) => d.name).join(', ')}</strong></div>}
            <div className="detail-line"><span>Dernière activité</span><strong>{data.user.last_seen ?? 'Jamais vu'}</strong></div>
          </div>

          {canEditHere && (
            <div className="mt">
              <span className="mini-label">Statut d'activité</span>
              <p className="helper" style={{ marginTop: 0, marginBottom: '0.6rem' }}>
                {data.user.activity_override
                  ? `Force manuellement : ${data.user.activity === 'active' ? 'Actif' : 'Inactif'}`
                  : `Calcul automatique (${data.user.activity === 'active' ? 'Actif' : 'Inactif'})`}
              </p>
              <div className="activity-buttons">
                <button className="btn btn-ghost small" onClick={() => setActivity('active')}>Actif</button>
                <button className="btn btn-ghost small" onClick={() => setActivity('inactive')}>Inactif</button>
                <button className="btn btn-ghost small" onClick={() => setActivity('auto')}>Automatique</button>
              </div>
            </div>
          )}
        </section>

        {/* Colonne roles */}
        <section className="panel">
          <div className="panel-head"><h3>Roles &amp; fonctions</h3></div>
          <div className="role-chips">
            {data.roles.map((r) => (
              <span key={r.assignment_id} className={`role-chip ${r.key === 'super_admin' ? 'badge-gold' : ''}`}>
                {r.name}{r.scope_name ? ` · ${r.scope_name}` : ''}
                {canAssign && <button className="role-chip-x" onClick={() => removeRole(r.assignment_id)} aria-label="Retirer">×</button>}
              </span>
            ))}
            {data.roles.length === 0 && <p className="helper">Aucun rôle.</p>}
          </div>

          {canAssign && (
            <div className="assign-box mt">
              <div className="field-row">
                <div className="field" style={{ marginBottom: 0 }}>
                  <label>Ajouter un rôle</label>
                  <select className="select" value={roleKey} onChange={(e) => { setRoleKey(e.target.value); setScopeId('') }}>
                    <option value="">Choisir un rôle...</option>
                    {roleOptions.map((r) => <option key={r.key} value={r.key}>{r.name}</option>)}
                  </select>
                </div>
                {needsScope && (
                  <div className="field" style={{ marginBottom: 0 }}>
                    <label>{selectedRole!.scope_kind === 'tribe' ? 'Tribu' : selectedRole!.scope_kind === 'gem' ? 'GEM' : selectedRole!.scope_kind === 'member' ? 'Fidèle confié' : 'Département'}</label>
                    {selectedRole!.scope_kind === 'member' ? (
                      <>
                        <input className="input" placeholder="Rechercher un fidèle…" value={memberQuery} onChange={(e) => setMemberQuery(e.target.value)} />
                        <select className="select mt-sm" value={scopeId} onChange={(e) => setScopeId(e.target.value)} size={Math.min(6, Math.max(2, memberChoices.length + 1))}>
                          <option value="">{memberOptions === null ? 'Chargement…' : 'Choisir le fidèle…'}</option>
                          {memberChoices.map((m) => <option key={m.user_id} value={m.user_id}>{m.full_name}{m.tribe ? ` · ${m.tribe}` : ''}</option>)}
                        </select>
                        <p className="helper">{p?.first_name ?? 'Ce responsable'} pourra agir (suivi, notes, demandes) uniquement sur ce fidèle.</p>
                      </>
                    ) : (
                    <select className="select" value={scopeId} onChange={(e) => setScopeId(e.target.value)}>
                      <option value="">Choisir...</option>
                      {(selectedRole!.scope_kind === 'tribe'
                        ? tribes
                        : selectedRole!.scope_kind === 'gem'
                          ? gems.filter((g) => g.tribe_id === data.profile?.tribe_id)
                          : departments)
                        .map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </select>
                    )}
                    {selectedRole!.scope_kind === 'gem' && !data.profile?.tribe_id && (
                      <p className="helper">Ce membre doit d'abord appartenir à une tribu.</p>
                    )}
                  </div>
                )}
              </div>
              <button className="btn btn-primary mt" disabled={busy || !roleKey || (needsScope && !scopeId)} onClick={assign}>
                {busy ? <span className="spinner" /> : 'Attribuer le rôle'}
              </button>
            </div>
          )}
        </section>
      </div>

      {data.completion && <MemberCompletion completion={data.completion} />}
      <MemberFamily family={data.family} maritalStatus={p?.marital_status}
        wedding={p?.wedding_day && p?.wedding_month ? new Date(2000, p.wedding_month - 1, p.wedding_day).toLocaleDateString('fr-CA', { day: 'numeric', month: 'long' }) : null} />

      {canEditHere && (
        <section className="panel mt">
          <div className="panel-head"><h3>Appartenance</h3></div>
          <p className="section-sub">Assignez la tribu et les départements de ce membre.</p>
          <div className="field">
            <label>Tribu</label>
            <select className="select" value={belongTribe} onChange={(e) => { setBelongTribe(e.target.value); setBelongGem('') }}>
              <option value="">Aucune</option>
              {tribes.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </div>
          <div className="field">
            <label>GEM <span className="helper" style={{ display: 'inline' }}>(groupe dans la tribu)</span></label>
            <select className="select" value={belongGem} onChange={(e) => setBelongGem(e.target.value)} disabled={!belongTribe}>
              <option value="">Aucun</option>
              {gems.filter((g) => g.tribe_id === Number(belongTribe)).map((g) => <option key={g.id} value={g.id}>{g.name}</option>)}
            </select>
          </div>
          <div className="field">
            <label>Départements <span className="helper" style={{ display: 'inline' }}>(plusieurs possibles)</span></label>
            <DepartmentPicker departments={departments} selected={belongDepts} onChange={setBelongDepts} />
          </div>
          <button className="btn btn-primary" disabled={busy} onClick={saveBelonging}>
            {busy ? <span className="spinner" /> : "Enregistrer l'appartenance"}
          </button>
        </section>
      )}

      {canViewSpiritual && sp && (
        <section className="panel mt">
          <div className="panel-head"><h3>Profil spirituel</h3></div>
          <div className="info-grid">
            {sp.conversion_year && <div className="info-tile"><span className="info-label">Conversion</span><span className="info-value">{sp.conversion_year}{sp.conversion_verse ? ` · ${sp.conversion_verse}` : ''}</span></div>}
            {sp.baptism_immersion_date && <div className="info-tile"><span className="info-label">Baptême immersion</span><span className="info-value">{sp.baptism_immersion_date}</span></div>}
            {sp.baptism_holy_spirit && <div className="info-tile"><span className="info-label">Baptême du Saint-Esprit</span><span className="info-value">{sp.baptism_holy_spirit.replace(/_/g, ' ')}</span></div>}
            {sp.speaks_tongues != null && <div className="info-tile"><span className="info-label">Parler en langues</span><span className="info-value">{sp.speaks_tongues ? `Oui${sp.tongues_since_year ? ` (${sp.tongues_since_year})` : ''}` : 'Non'}</span></div>}
            {sp.prayer_frequency && <div className="info-tile"><span className="info-label">Prière / méditation</span><span className="info-value">{sp.prayer_frequency}</span></div>}
            {sp.active_member != null && <div className="info-tile"><span className="info-label">Membre actif</span><span className="info-value">{sp.active_member ? 'Oui' : 'Non'}</span></div>}
          </div>
          {sp.gifts_detail && <div className="detail-line" style={{ marginTop: '0.8rem' }}><span>Dons et talents</span><strong>{sp.gifts_detail}</strong></div>}
          {sp.joyful_service && <div className="detail-line"><span>Sert avec joie</span><strong>{sp.joyful_service}</strong></div>}
          {sp.last_prayer_subject && <div className="detail-line"><span>Dernier sujet de prière</span><strong>{sp.last_prayer_subject}</strong></div>}
          {sp.focus_effort && <div className="detail-line"><span>Priorité / effort</span><strong>{sp.focus_effort}</strong></div>}
        </section>
      )}

      {canViewSpiritual && id && <MemberFissPanel userId={id} />}
      {canFissHistory && id && <MemberFissHistory userId={id} />}
      {canViewSpiritual && id && <SpiritualPanel userId={id} canRecord={canRecordSpiritual && manage} />}
      {canManageEval && id && <EvaluationsPanel userId={id} canManage={manage} />}
    </AppLayout>
  )
}
