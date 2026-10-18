import { useCallback, useEffect, useRef, useState } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { SkeletonCard } from '../components/Skeleton'
import { LoadError } from '../components/LoadError'
import { Icon } from '../components/Icon'
import { MeditationSheetView } from '../components/MeditationSheetView'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import { filledOn, MEDITATION_FIELDS, MEDITATION_MAX } from '../utils/meditation'
import type { MeditationField, MeditationSheet, MeditationSummary } from '../types'
import { scrollPageTo } from '../utils/pageScroll'

type Texts = Record<MeditationField, string>

const DRAFT = 'meditation:new'
const EMPTY: Texts = { verse: '', observation: '', rhema: '', action: '', prayer: '' }

/** Saisie de depart : le brouillon garde sur ce telephone (jamais perdu si l'envoi echoue), sinon vide. */
function initialTexts(): Texts {
  const draft = readDraft<Partial<Texts>>(DRAFT)
  const texts = { ...EMPTY }
  for (const [key] of MEDITATION_FIELDS) {
    if (typeof draft?.[key] === 'string') texts[key] = draft[key]
  }
  return texts
}

/**
 * Meditation : « Mes fiches de méditation » (historique, une fiche s'ouvre d'un appui) et « Remplir ma
 * fiche ». Onglet et fiche ouverte sont dans l'adresse : le retour arriere du telephone revient a la liste.
 */
export default function MyMeditation() {
  const [params, setParams] = useSearchParams()
  const location = useLocation()
  const navigate = useNavigate()
  const filling = params.get('onglet') === 'remplir'
  const openId = Number(params.get('fiche')) || null

  // Page deja ouverte : affichee tout de suite (derniere lecture), puis mise a jour en silence.
  const [sheets, setSheets] = useState<MeditationSummary[] | null>(() => peek<{ sheets: MeditationSummary[] }>('/me/meditations')?.sheets ?? null)
  const [loadError, setLoadError] = useState<string | null>(null)
  const [texts, setTexts] = useState<Texts>(initialTexts)
  const [busy, setBusy] = useState(false)
  // Garde immediate contre le double appui (avant meme que le bouton soit redessine desactive).
  const sending = useRef(false)

  const load = useCallback(() => {
    setLoadError(null)
    api<{ sheets: MeditationSummary[] }>('/me/meditations')
      .then((r) => setSheets(r.sheets))
      .catch((err) => setLoadError(err instanceof ApiError ? err.firstMessage : ''))
  }, [])
  useEffect(() => { load() }, [load])

  useDraft(DRAFT, texts, (v) => MEDITATION_FIELDS.every(([key]) => !v[key].trim()))

  const missing = MEDITATION_FIELDS.filter(([key]) => !texts[key].trim()).length

  function showTab(fill: boolean) {
    setParams(fill ? { onglet: 'remplir' } : {}, { replace: true })
  }

  function openSheet(id: number) {
    setParams({ fiche: String(id) }, { state: { fromList: true } })
  }

  function closeSheet() {
    if ((location.state as { fromList?: boolean } | null)?.fromList) navigate(-1)
    else setParams({}, { replace: true })
  }

  async function submit() {
    if (sending.current || missing > 0) return
    sending.current = true
    setBusy(true)
    try {
      const r = await api<{ sheet: MeditationSheet }>('/me/meditations', { method: 'POST', body: texts })
      clearDraft(DRAFT)
      setTexts({ ...EMPTY })
      // La nouvelle fiche en tete de l'historique, avec la date enregistree par le serveur.
      setSheets((list) => [{ id: r.sheet.id, submitted_at: r.sheet.submitted_at }, ...(list ?? []).filter((s) => s.id !== r.sheet.id)])
      setParams({}, { replace: true })
      scrollPageTo(0, true)
      load()
    } catch {
      /* message d'erreur affiche automatiquement ; la saisie et son brouillon restent */
    } finally {
      sending.current = false
      setBusy(false)
    }
  }

  return (
    <AppLayout title="Méditation">
      <div className="seg medit-tabs" role="tablist" aria-label="Méditation">
        <button type="button" role="tab" aria-selected={!filling} className={!filling ? 'on' : ''} onClick={() => showTab(false)}>
          Mes fiches de méditation
        </button>
        <button type="button" role="tab" aria-selected={filling} className={filling ? 'on' : ''} onClick={() => showTab(true)}>
          Remplir ma fiche
        </button>
      </div>

      {filling ? (
        <section className="panel medit-form" aria-labelledby="medit-form-title">
          <div className="panel-head"><h3 id="medit-form-title">Méditation de la Parole de Dieu</h3></div>
          <p className="helper medit-private">
            <Icon name="lock" size={13} /> Personnelle : seuls vous, votre patriarche, le Pasteur Résident et les Pasteurs Assistants la lisent.
          </p>
          {MEDITATION_FIELDS.map(([key, label]) => (
            <div key={key} className={`rm-field ${texts[key].trim() ? 'filled' : ''}`}>
              <div className="rm-field-head"><label htmlFor={`medit-${key}`}>{label}</label></div>
              <textarea id={`medit-${key}`} className="input" rows={key === 'verse' ? 5 : 3} maxLength={MEDITATION_MAX}
                value={texts[key]} readOnly={busy} onChange={(e) => setTexts((t) => ({ ...t, [key]: e.target.value }))} />
            </div>
          ))}
          <div className="rm-save">
            <span className="helper" aria-live="polite">
              {missing > 0
                ? (missing === 1 ? 'Encore 1 rubrique à remplir.' : `Encore ${missing} rubriques à remplir.`)
                : 'Votre fiche sera datée du jour de l’envoi.'}
            </span>
            <div className="rm-save-actions">
              <button type="button" className="btn btn-primary" disabled={busy || missing > 0} aria-busy={busy} onClick={submit}>
                {busy ? <span className="spinner" /> : 'Valider'}
              </button>
            </div>
          </div>
        </section>
      ) : openId ? (
        <SheetDetail key={openId} id={openId} onBack={closeSheet} />
      ) : sheets === null ? (
        loadError !== null ? <section className="panel"><LoadError message={loadError} onRetry={load} /></section> : <SkeletonCard />
      ) : sheets.length === 0 ? (
        <section className="panel">
          <div className="empty-state">
            <Icon name="book" size={28} />
            <h3>Aucune fiche de méditation</h3>
            <p>Vos fiches enregistrées apparaîtront ici, les plus récentes en premier.</p>
            <button type="button" className="btn btn-primary small mt-sm" onClick={() => showTab(true)}>Remplir ma fiche</button>
          </div>
        </section>
      ) : (
        <section className="panel" aria-label="Mes fiches de méditation">
          <ul className="medit-list">
            {sheets.map((s) => (
              <li key={s.id}>
                <button type="button" className="medit-row" onClick={() => openSheet(s.id)}>
                  <span className="medit-row-icon"><Icon name="book" size={18} /></span>
                  <span className="medit-row-text">{filledOn(s.submitted_at)}</span>
                  <Icon name="chevron" size={18} className="medit-row-chevron" />
                </button>
              </li>
            ))}
          </ul>
        </section>
      )}
    </AppLayout>
  )
}

/** Fiche ouverte : ses cinq rubriques, et le retour a la liste. */
function SheetDetail({ id, onBack }: { id: number; onBack: () => void }) {
  const [sheet, setSheet] = useState<MeditationSheet | null>(() => peek<{ sheet: MeditationSheet }>(`/me/meditations/${id}`)?.sheet ?? null)
  const [error, setError] = useState<string | null>(null)

  const load = useCallback(() => {
    setError(null)
    api<{ sheet: MeditationSheet }>(`/me/meditations/${id}`)
      .then((r) => setSheet(r.sheet))
      .catch((err) => setError(err instanceof ApiError ? err.firstMessage : ''))
  }, [id])
  useEffect(() => { load() }, [load])

  const back = <button type="button" className="btn btn-ghost small" onClick={onBack}>← Mes fiches</button>

  if (!sheet) {
    return error !== null
      ? <section className="panel"><LoadError message={error} onRetry={load} /><div className="medit-back">{back}</div></section>
      : <SkeletonCard />
  }
  return (
    <section className="panel medit-detail" aria-labelledby="medit-detail-title">
      <div className="panel-head">
        <div className="medit-detail-head">
          <h3 id="medit-detail-title">Méditation de la Parole de Dieu</h3>
          <p className="helper">{filledOn(sheet.submitted_at)}</p>
        </div>
        {back}
      </div>
      <MeditationSheetView sheet={sheet} />
    </section>
  )
}
