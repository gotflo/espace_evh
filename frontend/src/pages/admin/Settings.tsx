import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError, peek } from '../../api/client'
import { AppLayout } from '../../components/AppLayout'
import { SkeletonCard } from '../../components/Skeleton'
import { toast } from '../../toast'

type SettingValue = boolean | number | string
interface Setting {
  key: string
  type: 'bool' | 'int' | 'text'
  label: string
  help: string
  value: SettingValue
  default: SettingValue
  changed: boolean
  min?: number
  max?: number
  unit?: string
  lines?: number
}
interface SettingsGroup { id: string; title: string; description: string; settings: Setting[] }
type Payload = { groups: SettingsGroup[] }

const show = (s: Setting, v: SettingValue) => {
  if (s.type === 'text') return v ? `« ${v} »` : 'aucun message'
  if (s.type === 'bool') return v ? 'activé' : 'désactivé'
  return v === 0 && s.min === 0 ? 'jamais' : `${v} ${s.unit ?? ''}`.trim()
}

/**
 * Reglages de la plateforme : rappels automatiques, horaires d'envoi et limites. Chaque ligne dit ce
 * qu'elle fait, sa valeur par defaut, et se remet a zero d'un clic. Rien ne part avant « Enregistrer ».
 * Les textes affiches aux membres se modifient dans la page Apparence (meme editeur, groupe « textes »).
 */
export default function Settings() {
  return (
    <AppLayout title="Réglages" subtitle="Rappels automatiques, horaires et limites de la plateforme">
      <SettingsEditor skip={['textes']} intro={<>
        Chaque réglage s'applique dès l'enregistrement et chaque modification est consignée au journal.
        Un réglage modifié affiche sa valeur d'origine et peut y revenir d'un clic.
        Images et textes affichés aux membres : page <Link to="/admin/apparence">Apparence</Link>.
      </>} />
    </AppLayout>
  )
}

/** Editeur de reglages (tous les groupes, ou seulement / sauf certains) avec sa barre d'enregistrement. */
export function SettingsEditor({ only, skip, intro, onSaved }: { only?: string[]; skip?: string[]; intro?: ReactNode; onSaved?: () => void }) {
  const [allGroups, setGroups] = useState<SettingsGroup[] | null>(() => peek<Payload>('/admin/settings')?.groups ?? null)
  const [draft, setDraft] = useState<Record<string, SettingValue>>({})
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    api<Payload>('/admin/settings').then((r) => setGroups(r.groups)).catch(() => setGroups((g) => g ?? []))
  }, [])
  useEffect(() => { load() }, [load])

  const groups = useMemo(() => allGroups?.filter((g) => (!only || only.includes(g.id)) && !skip?.includes(g.id)) ?? null,
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [allGroups, only?.join(), skip?.join()])
  const all = useMemo(() => (groups ?? []).flatMap((g) => g.settings), [groups])
  const valueOf = (s: Setting) => (s.key in draft ? draft[s.key] : s.value)
  const pending = all.filter((s) => s.key in draft && draft[s.key] !== s.value)

  function set(s: Setting, v: SettingValue) {
    setErrors((e) => { const next = { ...e }; delete next[s.key]; return next })
    setDraft((d) => {
      const next = { ...d }
      if (v === s.value) delete next[s.key]; else next[s.key] = v
      return next
    })
  }

  async function save() {
    setBusy(true)
    try {
      const values = Object.fromEntries(pending.map((s) => [s.key, draft[s.key]]))
      const r = await api<Payload & { message: string }>('/admin/settings', { method: 'PUT', body: { values }, toast: false })
      setGroups(r.groups)
      setDraft({})
      onSaved?.()
      setErrors({})
      toast.success(pending.length === 1 ? 'Le réglage est enregistré.' : `${pending.length} réglages enregistrés.`, { title: 'Réglages' })
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.errors).length) {
        setErrors(Object.fromEntries(Object.entries(err.errors).map(([k, m]) => [k.replace(/^values\./, ''), m[0]])))
        toast.error('Certaines valeurs ne sont pas acceptées : voyez les messages en rouge.')
      } else {
        toast.error(err instanceof ApiError ? err.firstMessage : 'Enregistrement impossible. Réessayez.')
      }
    } finally { setBusy(false) }
  }

  return (
    <>
      {!groups ? <div className="panel-grid"><SkeletonCard /><SkeletonCard /></div> : (
        <div className="settings-page">
          {intro && <p className="helper settings-intro">{intro}</p>}
          {groups.map((g) => {
            const bools = g.settings.filter((s) => s.type === 'bool')
            const on = bools.filter((s) => valueOf(s) === true).length
            return (
              <section key={g.id} className="panel settings-group" aria-labelledby={`set-${g.id}`}>
                <div className="settings-group-head">
                  <div className="settings-group-title">
                    <h3 id={`set-${g.id}`}>{g.title}</h3>
                    <p className="helper">{g.description}</p>
                  </div>
                  {bools.length > 1 && <span className="settings-count">{on} / {bools.length} actifs</span>}
                </div>
                <ul className="settings-list">
                  {g.settings.map((s) => {
                    const v = valueOf(s)
                    const modified = v !== s.default
                    return (
                      <li key={s.key} className={`settings-row ${s.type === 'text' ? 'settings-row-text' : ''} ${s.key in draft ? 'is-pending' : ''}`}>
                        <div className="settings-text">
                          <span className="settings-label" id={`lbl-${s.key}`}>{s.label}</span>
                          <span className="settings-help">{s.help}</span>
                          {modified && (
                            <span className="settings-default">
                              Valeur d'origine : {show(s, s.default)}
                              <button type="button" className="btn-link" onClick={() => set(s, s.default)}>Rétablir</button>
                            </span>
                          )}
                          {errors[s.key] && <span className="helper editor-error">{errors[s.key]}</span>}
                        </div>
                        <div className="settings-control">
                          {s.type === 'text' ? (
                            <div className="settings-text-field">
                              {(s.lines ?? 1) > 1 ? (
                                <textarea className="input" rows={s.lines} maxLength={s.max} value={String(v)} aria-labelledby={`lbl-${s.key}`}
                                  placeholder={s.default ? undefined : 'Aucun message'} onChange={(e) => set(s, e.target.value)} />
                              ) : (
                                <input className="input" maxLength={s.max} value={String(v)} aria-labelledby={`lbl-${s.key}`}
                                  onChange={(e) => set(s, e.target.value)} />
                              )}
                              <span className="settings-counter" aria-hidden>{String(v).length} / {s.max}</span>
                            </div>
                          ) : s.type === 'bool' ? (
                            <button type="button" role="switch" aria-checked={v === true} aria-labelledby={`lbl-${s.key}`}
                              className={`toggle-switch ${v ? 'on' : ''}`} onClick={() => set(s, !v)}>
                              <span className="toggle-knob" aria-hidden />
                              <span className="toggle-text">{v ? 'Activé' : 'Désactivé'}</span>
                            </button>
                          ) : (
                            <div className="stepper" role="group" aria-labelledby={`lbl-${s.key}`}>
                              <button type="button" className="stepper-btn" aria-label="Diminuer" disabled={Number(v) <= (s.min ?? 0)}
                                onClick={() => set(s, Math.max(s.min ?? 0, Number(v) - 1))}>−</button>
                              <input className="input stepper-input" type="number" inputMode="numeric" min={s.min} max={s.max} value={String(v)}
                                aria-label={`${s.label} (${s.min} à ${s.max} ${s.unit ?? ''})`}
                                onChange={(e) => { const n = parseInt(e.target.value, 10); if (!Number.isNaN(n)) set(s, n) }}
                                onBlur={() => set(s, Math.min(s.max ?? Number(v), Math.max(s.min ?? Number(v), Number(v))))} />
                              <button type="button" className="stepper-btn" aria-label="Augmenter" disabled={Number(v) >= (s.max ?? Infinity)}
                                onClick={() => set(s, Math.min(s.max ?? Infinity, Number(v) + 1))}>+</button>
                              {s.unit && <span className="stepper-unit">{v === 0 && s.min === 0 ? 'jamais' : s.unit}</span>}
                            </div>
                          )}
                        </div>
                      </li>
                    )
                  })}
                </ul>
              </section>
            )
          })}

          {pending.length > 0 && (
            <div className="settings-savebar" role="region" aria-label="Modifications non enregistrées">
              <span>{pending.length === 1 ? '1 modification non enregistrée' : `${pending.length} modifications non enregistrées`}</span>
              <div className="settings-savebar-actions">
                <button type="button" className="btn btn-ghost small" disabled={busy} onClick={() => { setDraft({}); setErrors({}) }}>Annuler</button>
                <button type="button" className="btn btn-primary small" disabled={busy} onClick={save}>{busy ? <span className="spinner" /> : 'Enregistrer'}</button>
              </div>
            </div>
          )}
        </div>
      )}
    </>
  )
}
