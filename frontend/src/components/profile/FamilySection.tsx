import { useCallback, useEffect, useState } from 'react'
import { api } from '../../api/client'
import type { FamilyOverview, FamilyPerson } from '../../types'

const MONTHS = ['Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre']
const MARITAL = [
  ['celibataire', 'Célibataire'], ['marie', 'Marié(e)'], ['fiance', 'Fiancé(e)'],
  ['veuf', 'Veuf(ve)'], ['divorce', 'Divorcé(e)'], ['concubinage', 'En concubinage'],
]

/** Recherche d'un membre inscrit (nom, tribu, mois de naissance pour distinguer les homonymes). */
function PersonSearch({ onPick, placeholder, excludeMarried }: { onPick: (p: FamilyPerson) => void; placeholder: string; excludeMarried?: boolean }) {
  const [q, setQ] = useState('')
  const [results, setResults] = useState<FamilyPerson[] | null>(null)

  useEffect(() => {
    if (q.trim().length < 2) { setResults(null); return }
    const t = window.setTimeout(() => {
      api<{ results: FamilyPerson[] }>(`/me/family/search?q=${encodeURIComponent(q.trim())}`).then((r) => setResults(r.results)).catch(() => setResults([]))
    }, 300)
    return () => window.clearTimeout(t)
  }, [q])

  return (
    <div className="person-search">
      <input className="input" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} />
      {results && (
        <ul className="person-results">
          {results.map((p) => {
            const blocked = excludeMarried && p.has_spouse
            return (
              <li key={p.user_id}>
                <button type="button" disabled={blocked} onClick={() => { onPick(p); setQ(''); setResults(null) }}>
                  {p.photo_url ? <img src={p.photo_url} alt="" /> : <span className="mini-avatar">{p.full_name[0]}</span>}
                  <span className="person-text">
                    <strong>{p.full_name}</strong>
                    <small>
                      {p.tribe ? `Tribu ${p.tribe}` : 'Sans tribu'}
                      {p.birth_month ? ` · né(e) en ${MONTHS[p.birth_month - 1].toLowerCase()}` : ''}
                      {blocked ? ' · déjà lié(e) à un conjoint' : ''}
                    </small>
                  </span>
                </button>
              </li>
            )
          })}
          {results.length === 0 && <li className="person-empty">Aucun membre trouvé : il/elle n'est peut-être pas encore inscrit(e).</li>}
        </ul>
      )}
    </div>
  )
}

interface ChildRow { name: string; birth_year: string; user_id: number | null; linked?: string }

/**
 * Famille : situation matrimoniale, conjoint(e) (lie seulement apres sa confirmation),
 * date de mariage (ajoutee au calendrier), enfants (lignes generees selon le nombre).
 */
export function FamilySection({ marital, onMarital, weddingDay, weddingMonth, onWedding, onSaveProfile, busy, hasChildrenInitial }: {
  marital: string
  onMarital: (v: string) => void
  weddingDay: string
  weddingMonth: string
  onWedding: (day: string, month: string) => void
  onSaveProfile: () => Promise<void>
  busy: boolean
  hasChildrenInitial?: boolean | null
}) {
  const [family, setFamily] = useState<FamilyOverview | null>(null)
  const [pick, setPick] = useState<FamilyPerson | null>(null)
  const [nameOnly, setNameOnly] = useState(false)
  const [spouseName, setSpouseName] = useState('')
  const [hasChildren, setHasChildren] = useState<boolean | null>(null)
  const [rows, setRows] = useState<ChildRow[]>([])
  const [working, setWorking] = useState(false)

  const apply = useCallback((f: FamilyOverview) => {
    setFamily(f)
    setSpouseName(f.spouse_name ?? '')
    setRows(f.children.map((c) => ({ name: c.name, birth_year: c.birth_year?.toString() ?? '', user_id: c.user_id, linked: c.user_id ? c.name : undefined })))
    setHasChildren(f.children.length > 0 ? true : (hasChildrenInitial ?? null))
  }, [hasChildrenInitial])
  const load = useCallback(() => { api<FamilyOverview>('/me/family').then(apply).catch(() => {}) }, [apply])
  useEffect(() => { load() }, [load])

  async function run(fn: () => Promise<{ family?: FamilyOverview } | unknown>) {
    setWorking(true)
    try {
      const r = await fn() as { family?: FamilyOverview }
      if (r?.family) apply(r.family); else load()
    } catch { /* toast */ } finally { setWorking(false) }
  }

  const designate = (p: FamilyPerson) => run(() => api('/me/family/spouse', { method: 'PUT', body: { user_id: p.user_id } })).then(() => setPick(null))
  const saveName = () => run(() => api('/me/family/spouse', { method: 'PUT', body: { name: spouseName.trim() || null } })).then(() => setNameOnly(false))
  const removeSpouse = () => { if (confirm('Retirer ce lien conjugal ? Il sera retiré des deux côtés.')) run(() => api('/me/family/spouse', { method: 'PUT', body: {} })) }
  const respond = (id: number, ok: boolean) => run(() => api(ok ? `/me/family/links/${id}/confirm` : `/me/family/links/${id}/decline`, { method: 'POST' }))

  function setCount(n: number) {
    setRows((prev) => Array.from({ length: n }, (_, i) => prev[i] ?? { name: '', birth_year: '', user_id: null }))
  }
  const saveChildren = () => run(() => api('/me/family/children', {
    method: 'PUT',
    body: {
      has_children: !!hasChildren,
      children: hasChildren ? rows.filter((r) => r.name.trim()).map((r) => ({ name: r.name.trim(), birth_year: r.birth_year ? Number(r.birth_year) : null, user_id: r.user_id })) : [],
    },
  }))

  const year = new Date().getFullYear()
  const spouse = family?.spouse

  return (
    <div className="family">
      {family && family.incoming.length > 0 && (
        <div className="family-incoming">
          {family.incoming.map((l) => (
            <div key={l.id} className="lock-card open">
              <span className="lock-icon" aria-hidden>{l.relation === 'spouse' ? '💍' : '👨‍👧'}</span>
              <div className="lock-text">
                <strong>{l.from} vous a indiqué comme {l.relation === 'spouse' ? 'conjoint(e)' : 'son enfant'}</strong>
                <span>Confirmez seulement s'il s'agit bien de vous.</span>
              </div>
              <div className="decision-actions">
                <button className="btn btn-ghost small" disabled={working} onClick={() => respond(l.id, false)}>Ce n'est pas moi</button>
                <button className="btn btn-primary small" disabled={working} onClick={() => respond(l.id, true)}>Confirmer</button>
              </div>
            </div>
          ))}
        </div>
      )}

      <div className="field"><label>Situation matrimoniale</label>
        <select className="select" value={marital} onChange={(e) => onMarital(e.target.value)}>
          <option value="">Non précisé</option>{MARITAL.map(([k, l]) => <option key={k} value={k}>{l}</option>)}
        </select>
      </div>

      {marital === 'marie' && (
        <div className="family-block">
          <div className="field">
            <label>Date de mariage <span className="helper" style={{ display: 'inline' }}>(ajoutée au calendrier)</span></label>
            <div className="field-row" style={{ gap: '0.5rem' }}>
              <select className="select" value={weddingDay} onChange={(e) => onWedding(e.target.value, weddingMonth)}>
                <option value="">Jour</option>{Array.from({ length: 31 }, (_, i) => i + 1).map((d) => <option key={d} value={d}>{d}</option>)}
              </select>
              <select className="select" value={weddingMonth} onChange={(e) => onWedding(weddingDay, e.target.value)}>
                <option value="">Mois</option>{MONTHS.map((m, i) => <option key={i} value={i + 1}>{m}</option>)}
              </select>
            </div>
          </div>

          <div className="field">
            <label>Conjoint(e)</label>
            {spouse ? (
              <div className="spouse-card">
                {spouse.photo_url ? <img src={spouse.photo_url} alt="" /> : <span className="mini-avatar">{(spouse.name ?? '?')[0]}</span>}
                <div className="person-text">
                  <strong>{spouse.name}</strong>
                  <small>{spouse.tribe ? `Tribu ${spouse.tribe} · ` : ''}{spouse.status === 'confirmed' ? 'Lien confirmé ✓' : spouse.status === 'pending' ? 'En attente de sa confirmation' : 'Non confirmé'}</small>
                </div>
                <button type="button" className="btn-link" onClick={removeSpouse}>Retirer</button>
              </div>
            ) : pick ? (
              <div className="spouse-card">
                <span className="mini-avatar">{pick.full_name[0]}</span>
                <div className="person-text">
                  <strong>{pick.full_name}</strong>
                  <small>{pick.tribe ? `Tribu ${pick.tribe}` : ''} · il/elle devra confirmer ce lien</small>
                </div>
                <div className="decision-actions">
                  <button type="button" className="btn btn-ghost small" onClick={() => setPick(null)}>Annuler</button>
                  <button type="button" className="btn btn-primary small" disabled={working} onClick={() => designate(pick)}>Confirmer mon choix</button>
                </div>
              </div>
            ) : nameOnly ? (
              <div className="field-row">
                <input className="input" value={spouseName} onChange={(e) => setSpouseName(e.target.value)} placeholder="Nom complet de votre conjoint(e)" />
                <div className="decision-actions">
                  <button type="button" className="btn btn-ghost small" onClick={() => setNameOnly(false)}>Retour</button>
                  <button type="button" className="btn btn-primary small" disabled={working || spouseName.trim().length < 3} onClick={saveName}>Enregistrer</button>
                </div>
              </div>
            ) : (
              <>
                <PersonSearch placeholder="Rechercher votre conjoint(e) parmi les membres…" onPick={setPick} excludeMarried />
                {family?.suggestions && family.suggestions.length > 0 && (
                  <div className="suggestions">
                    <span className="helper">Est-ce votre conjoint(e) ?</span>
                    {family.suggestions.map((s) => (
                      <button key={s.user_id} type="button" className="chip-toggle" onClick={() => setPick(s)}>{s.full_name}{s.tribe ? ` · ${s.tribe}` : ''}</button>
                    ))}
                  </div>
                )}
                <button type="button" className="btn-link mt-sm" onClick={() => setNameOnly(true)}>
                  {family?.spouse_name ? `Non inscrit : ${family.spouse_name} (modifier)` : "Mon conjoint(e) n'est pas encore inscrit(e)"}
                </button>
              </>
            )}
          </div>
        </div>
      )}

      <button className="btn btn-primary" disabled={busy} onClick={() => onSaveProfile()}>
        {busy ? <span className="spinner" /> : 'Enregistrer la situation'}
      </button>

      <div className="family-block mt">
        <div className="field">
          <label>Avez-vous des enfants ?</label>
          <div className="pill-choices">
            <button type="button" className={`pill-choice ${hasChildren === true ? 'on' : ''}`} onClick={() => { setHasChildren(true); if (rows.length === 0) setCount(1) }}>Oui</button>
            <button type="button" className={`pill-choice ${hasChildren === false ? 'on' : ''}`} onClick={() => setHasChildren(false)}>Non</button>
          </div>
        </div>
        {hasChildren && (
          <>
            <div className="field"><label>Combien ?</label>
              <select className="select" value={rows.length} onChange={(e) => setCount(Number(e.target.value))}>
                {Array.from({ length: 12 }, (_, i) => i + 1).map((n) => <option key={n} value={n}>{n}</option>)}
              </select>
            </div>
            <div className="children-rows">
              {rows.map((r, i) => (
                <div key={i} className="child-row">
                  <span className="child-index">{i + 1}</span>
                  <input className="input" value={r.name} placeholder="Nom de l'enfant"
                    onChange={(e) => setRows((p) => p.map((x, j) => j === i ? { ...x, name: e.target.value } : x))} />
                  <input className="input child-year" type="number" inputMode="numeric" min={1900} max={year} value={r.birth_year} placeholder="Année"
                    onChange={(e) => setRows((p) => p.map((x, j) => j === i ? { ...x, birth_year: e.target.value } : x))} />
                  {r.user_id
                    ? <button type="button" className="chip-toggle on" title="Lien avec son profil" onClick={() => setRows((p) => p.map((x, j) => j === i ? { ...x, user_id: null, linked: undefined } : x))}>🔗 {r.linked ?? 'Lié'} ×</button>
                    : <details className="child-link"><summary>Lier à un membre</summary>
                        <PersonSearch placeholder="Son nom dans l'application…" onPick={(p) => setRows((prev) => prev.map((x, j) => j === i ? { ...x, user_id: p.user_id, linked: p.full_name, name: x.name || p.full_name } : x))} />
                      </details>}
                </div>
              ))}
            </div>
          </>
        )}
        {hasChildren !== null && (
          <button className="btn btn-ghost" disabled={working || (hasChildren && rows.some((r) => !r.name.trim()))} onClick={saveChildren}>
            {working ? <span className="spinner" /> : 'Enregistrer les enfants'}
          </button>
        )}
      </div>
    </div>
  )
}
