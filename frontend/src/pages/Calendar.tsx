import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { EventEditor } from '../components/EventEditor'
import { useAutoRefresh } from '../hooks/useAutoRefresh'
import type { CalendarData, CalendarItem, EventOccurrence } from '../types'
import { CAT_LABEL, hhmm, longDay, optimisticRsvp, parseYmd, rsvpOccurrence, timeRange, ymd } from '../utils/events'

type View = 'month' | 'week' | 'list'
const DAYS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche']
const FILTER_KEY = 'evh_calendar_filters'
type Filters = { birthdays: boolean; holidays: boolean; tasks: boolean }

function mondayOf(d: Date): Date {
  const x = new Date(d.getFullYear(), d.getMonth(), d.getDate())
  x.setDate(x.getDate() - ((x.getDay() + 6) % 7))
  return x
}
function addDays(d: Date, n: number): Date {
  const x = new Date(d); x.setDate(x.getDate() + n); return x
}

/** Premier et dernier jour affiches selon la vue. */
function rangeFor(view: View, cursor: Date): { from: Date; to: Date } {
  if (view === 'week') {
    const from = mondayOf(cursor)
    return { from, to: addDays(from, 6) }
  }
  const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1)
  const last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0)
  if (view === 'list') return { from: first, to: last }
  const from = mondayOf(first)
  const to = addDays(mondayOf(last), 6)
  return { from, to }
}

function itemDate(i: CalendarItem): string { return i.kind === 'event' ? i.occurs_on : i.date }

/** Ordre dans une journee : feries, anniversaires, journee entiere, taches, puis par heure. */
function rank(i: CalendarItem): number {
  if (i.kind === 'holiday') return 0
  if (i.kind === 'birthday' || i.kind === 'wedding') return 1
  if (i.kind === 'event' && i.all_day) return 2
  if (i.kind === 'task') return 3
  return 4
}

function chipClass(i: CalendarItem): string {
  if (i.kind === 'event') return `cal-chip chip-evt-${i.category}${i.recurring ? ' is-recurring' : ''}${i.personal ? ' is-personal' : ''}`
  if (i.kind === 'holiday') return `cal-chip chip-holiday chip-holiday-${i.holiday_kind}`
  if (i.kind === 'birthday') return 'cal-chip chip-birthday'
  if (i.kind === 'wedding') return 'cal-chip chip-wedding'
  return `cal-chip chip-task${i.done ? ' is-done' : ''}`
}

function ChipContent({ item, details }: { item: CalendarItem; details: boolean }) {
  if (item.kind === 'event') {
    return (
      <>
        {item.personal && <><span className="recur-icon" aria-label="Privé">🔒</span>{' '}</>}
        {item.recurring && <><span className="recur-icon" aria-label="Récurrent">↻</span>{' '}</>}
        {!item.all_day && <><strong className="chip-time">{hhmm(item.starts_at)}</strong>{' '}</>}
        <span className="chip-title">{item.title}</span>
        {details && (item.location || (item.ends_at && !item.all_day)) && (
          <span className="chip-details">
            {item.ends_at && !item.all_day ? `jusqu'à ${hhmm(item.ends_at)}` : ''}
            {item.location ? `${item.ends_at && !item.all_day ? ' · ' : ''}📍 ${item.location}` : ''}
          </span>
        )}
      </>
    )
  }
  if (item.kind === 'birthday') return <><span aria-hidden>🎂</span> <span className="chip-title">{item.title}</span></>
  if (item.kind === 'wedding') return <><span aria-hidden>💍</span> <span className="chip-title">{item.title}</span></>
  if (item.kind === 'task') return <><span aria-hidden>{item.done ? '✅' : '📝'}</span> <span className="chip-title">{item.title}</span></>
  return <span className="chip-title">{item.title}</span>
}

export default function Calendar() {
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const initialDate = params.get('date')
  const [view, setView] = useState<View>(() => {
    const v = params.get('vue') as View | null
    if (v === 'month' || v === 'week' || v === 'list') return v
    return window.matchMedia('(max-width: 640px)').matches ? 'list' : 'month'
  })
  const [cursor, setCursor] = useState<Date>(() => (initialDate && /^\d{4}-\d{2}-\d{2}$/.test(initialDate) ? parseYmd(initialDate) : new Date()))
  const [selectedDay, setSelectedDay] = useState<string>(() => initialDate ?? ymd(new Date()))
  const [details, setDetails] = useState(false)
  const [filters, setFilters] = useState<Filters>(() => {
    try { return { birthdays: true, holidays: true, tasks: true, ...JSON.parse(localStorage.getItem(FILTER_KEY) ?? '{}') } }
    catch { return { birthdays: true, holidays: true, tasks: true } }
  })
  const [data, setData] = useState<CalendarData | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [modal, setModal] = useState<CalendarItem | null>(null)
  // Editeur : { date } pour programmer un nouveau, { event } pour modifier.
  const [editor, setEditor] = useState<{ date?: string; event?: EventOccurrence } | null>(null)
  const [shareOpen, setShareOpen] = useState(false)
  const [feed, setFeed] = useState<string | null>(null)
  const [copied, setCopied] = useState('')
  const monthInput = useRef<HTMLInputElement>(null)
  const shareRef = useRef<HTMLDivElement>(null)

  const { from, to } = useMemo(() => rangeFor(view, cursor), [view, cursor])
  const fromS = ymd(from)
  const toS = ymd(to)
  const today = ymd(new Date())

  const load = useCallback(() => {
    api<CalendarData>(`/calendar?from=${fromS}&to=${toS}`)
      .then((r) => { setData(r); setError('') })
      .catch((e) => setError(e instanceof ApiError ? e.firstMessage : 'Impossible de charger le calendrier.'))
      .finally(() => setLoading(false))
  }, [fromS, toS])

  useEffect(() => { setLoading(true); load() }, [load])
  useAutoRefresh(load, 60000)

  // L'URL reflete la vue : lien partageable, retour arriere, liens des notifications.
  useEffect(() => {
    setParams({ date: ymd(cursor), vue: view }, { replace: true })
  }, [cursor, view, setParams])

  useEffect(() => {
    try { localStorage.setItem(FILTER_KEY, JSON.stringify(filters)) } catch { /* ignore */ }
  }, [filters])

  useEffect(() => {
    function onDoc(e: MouseEvent) {
      if (shareRef.current && !shareRef.current.contains(e.target as Node)) setShareOpen(false)
    }
    document.addEventListener('mousedown', onDoc)
    return () => document.removeEventListener('mousedown', onDoc)
  }, [])

  const move = useCallback((dir: -1 | 1) => {
    setCursor((c) => view === 'week' ? addDays(c, dir * 7) : new Date(c.getFullYear(), c.getMonth() + dir, 1))
  }, [view])

  function goToday() {
    const now = new Date()
    setCursor(now); setSelectedDay(ymd(now))
  }

  // Raccourcis clavier : fleches (precedent/suivant), T (aujourd'hui), N (programmer).
  useEffect(() => {
    function onKey(e: KeyboardEvent) {
      const tag = (e.target as HTMLElement)?.tagName
      if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || modal || editor) return
      if (e.key === 'ArrowLeft') move(-1)
      else if (e.key === 'ArrowRight') move(1)
      else if (e.key.toLowerCase() === 't') goToday()
      else if (e.key.toLowerCase() === 'n') { e.preventDefault(); setEditor({ date: selectedDay }) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [move, modal, editor, selectedDay])

  /** Elements par jour (les evenements de plusieurs jours apparaissent chaque jour). */
  const byDay = useMemo(() => {
    const map = new Map<string, CalendarItem[]>()
    const push = (d: string, i: CalendarItem) => { const l = map.get(d); if (l) l.push(i); else map.set(d, [i]) }
    if (!data) return map
    for (const e of data.events) {
      push(e.occurs_on, e)
      if (e.ends_at) {
        const end = ymd(new Date(e.ends_at))
        let d = addDays(parseYmd(e.occurs_on), 1)
        for (let n = 0; ymd(d) <= end && n < 31; n++, d = addDays(d, 1)) push(ymd(d), e)
      }
    }
    if (filters.holidays) data.holidays.forEach((h) => push(h.date, h))
    if (filters.birthdays) data.birthdays.forEach((b) => push(b.date, b))
    if (filters.birthdays) (data.weddings ?? []).forEach((w) => push(w.date, w))
    if (filters.tasks) data.tasks.forEach((t) => push(t.date, t))
    map.forEach((list) => list.sort((a, b) => rank(a) - rank(b)
      || (a.kind === 'event' && b.kind === 'event' ? a.starts_at.localeCompare(b.starts_at) : 0)))
    return map
  }, [data, filters])

  async function rsvp(ev: EventOccurrence, response: 'present' | 'absent', volunteer?: boolean) {
    const optimistic = optimisticRsvp(ev, response, volunteer)
    const patch = (next: EventOccurrence) => {
      setModal((m) => m && m.key === ev.key ? next : m)
      setData((d) => d ? { ...d, events: d.events.map((x) => x.key === ev.key ? next : x) } : d)
    }
    patch(optimistic)
    try { patch(await rsvpOccurrence(ev, response, volunteer)) } catch { load() }
  }

  async function openShare() {
    setShareOpen(!shareOpen)
    if (!feed) api<{ url: string }>('/calendar/feed-url').then((r) => setFeed(r.url)).catch(() => {})
  }

  async function copy(text: string, label: string) {
    try { await navigator.clipboard.writeText(text); setCopied(label) }
    catch { window.prompt('Copiez ce lien :', text) }
    window.setTimeout(() => setCopied(''), 2500)
  }

  async function shareView() {
    const url = window.location.href
    if (navigator.share) {
      try { await navigator.share({ title: "Calendrier – Vases d'Honneur", url }); return } catch { /* annule */ }
    }
    copy(url, 'view')
  }

  async function resetFeed() {
    if (!confirm("Générer un nouveau lien ? L'ancien lien d'abonnement cessera de fonctionner.")) return
    const r = await api<{ url: string }>('/calendar/feed-url/reset', { method: 'POST', toast: "Nouveau lien d'abonnement généré." })
    setFeed(r.url)
  }

  const label = view === 'week'
    ? `${from.toLocaleDateString('fr-CA', { day: 'numeric', month: 'short' })} – ${to.toLocaleDateString('fr-CA', { day: 'numeric', month: 'short', year: 'numeric' })}`
    : cursor.toLocaleDateString('fr-CA', { month: 'long', year: 'numeric' })
  const labelCap = label.charAt(0).toUpperCase() + label.slice(1)

  const days: Date[] = []
  for (let d = new Date(from); ymd(d) <= toS; d = addDays(d, 1)) days.push(new Date(d))
  const webcal = feed?.replace(/^https?:/, 'webcal:')

  function openItem(i: CalendarItem) {
    if (i.kind === 'task') { navigate(i.url); return }
    setModal(i)
  }

  function dayCell(d: Date) {
    const key = ymd(d)
    const items = byDay.get(key) ?? []
    const outside = view === 'month' && d.getMonth() !== cursor.getMonth()
    const max = details ? 3 : 4
    return (
      <div key={key}
        className={`cal-cell ${outside ? 'outside' : ''} ${key === today ? 'is-today' : ''} ${key === selectedDay ? 'is-selected' : ''} ${key < today ? 'is-past' : ''}`}
        onClick={() => setSelectedDay(key)} onDoubleClick={() => setEditor({ date: key })}
        role="gridcell" aria-label={longDay(key)}>
        <span className="cal-num">{String(d.getDate()).padStart(2, '0')}</span>
        <button className="cal-add" aria-label={`Programmer le ${longDay(key)}`} title="Programmer ce jour"
          onClick={(e) => { e.stopPropagation(); setSelectedDay(key); setEditor({ date: key }) }}>+</button>
        <div className="cal-chips">
          {items.slice(0, view === 'week' ? 50 : max).map((i) => (
            <button key={`${i.key}-${key}`} className={chipClass(i)} onClick={(e) => { e.stopPropagation(); openItem(i) }}>
              <ChipContent item={i} details={details || view === 'week'} />
            </button>
          ))}
          {view === 'month' && items.length > max && (
            <button className="cal-more" onClick={(e) => { e.stopPropagation(); setSelectedDay(key) }}>+{items.length - max} autre(s)</button>
          )}
        </div>
        {items.length > 0 && (
          <div className="cal-dots" aria-hidden>
            {items.slice(0, 4).map((i) => <span key={`${i.key}-dot`} className={`cal-dot ${chipClass(i)}`} />)}
          </div>
        )}
      </div>
    )
  }

  const selectedItems = byDay.get(selectedDay) ?? []
  const listDays = days.filter((d) => (byDay.get(ymd(d)) ?? []).length > 0)

  return (
    <AppLayout title="Calendrier" subtitle="Tout ce qui est programmé dans l'église">
      <div className="cal">
        <div className="cal-toolbar">
          <button className="cal-btn primary" onClick={() => setEditor({ date: selectedDay >= today ? selectedDay : today })} title="Programmer (N)">
            <span aria-hidden>＋</span> Programmer
          </button>
          <div className="cal-nav">
            <button className="cal-btn" onClick={goToday}>Aujourd'hui</button>
            <button className="cal-btn icon" onClick={() => move(-1)} aria-label="Précédent">◀</button>
            <button className="cal-btn icon" onClick={() => move(1)} aria-label="Suivant">▶</button>
          </div>
          <button className="cal-label" onClick={() => monthInput.current?.showPicker?.() ?? monthInput.current?.focus()}>
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M8 2v4M16 2v4M3 10h18M5 4h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z" /></svg>
            <span>{labelCap}</span>
            <input ref={monthInput} type="month" className="cal-month-input" tabIndex={-1} aria-hidden
              value={`${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`}
              onChange={(e) => { if (e.target.value) { const [y, m] = e.target.value.split('-').map(Number); setCursor(new Date(y, m - 1, 1)) } }} />
          </button>
          <label className="cal-switch">
            <input type="checkbox" checked={details} onChange={(e) => setDetails(e.target.checked)} />
            <span className="cal-switch-track" aria-hidden />
            Détails
          </label>
          {loading && <span className="spinner cal-spinner" aria-label="Chargement" />}

          <div className="cal-actions">
            <button className="cal-btn outline" onClick={() => window.print()}>
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2M6 14h12v8H6z" /></svg>
              <span>Imprimer</span>
            </button>
            <div className="cal-share" ref={shareRef}>
              <button className="cal-btn outline" onClick={openShare} aria-expanded={shareOpen}>
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8M16 6l-4-4-4 4M12 2v13" /></svg>
                <span>Partager</span>
              </button>
              {shareOpen && (
                <div className="cal-share-menu">
                  <button className="cal-share-item" onClick={shareView}>
                    🔗 {copied === 'view' ? 'Lien copié !' : 'Partager cette vue'}
                  </button>
                  <div className="cal-share-sep">S'abonner (mise à jour automatique)</div>
                  {feed ? (
                    <>
                      <a className="cal-share-item" href={`https://calendar.google.com/calendar/r?cid=${encodeURIComponent(webcal ?? '')}`} target="_blank" rel="noreferrer">📆 Google Agenda</a>
                      <a className="cal-share-item" href={webcal}>🍎 Calendrier iPhone / Mac</a>
                      <a className="cal-share-item" href={`https://outlook.live.com/calendar/0/addfromweb?url=${encodeURIComponent(feed)}&name=${encodeURIComponent("Vases d'Honneur")}`} target="_blank" rel="noreferrer">📧 Outlook</a>
                      <button className="cal-share-item" onClick={() => copy(feed, 'feed')}>📋 {copied === 'feed' ? 'Lien copié !' : "Copier le lien d'abonnement"}</button>
                      <button className="cal-share-item soft" onClick={resetFeed}>↺ Générer un nouveau lien</button>
                      <p className="cal-share-note">Ce lien est personnel : il affiche les événements qui vous concernent.</p>
                    </>
                  ) : <p className="cal-share-note">Préparation du lien…</p>}
                </div>
              )}
            </div>
            <select className="cal-view" value={view} onChange={(e) => setView(e.target.value as View)} aria-label="Affichage">
              <option value="month">Mois</option>
              <option value="week">Semaine</option>
              <option value="list">Liste</option>
            </select>
          </div>
        </div>

        {error && <div className="alert alert-error">{error}</div>}

        {view !== 'list' && (
          <div className={`cal-grid ${view === 'week' ? 'week' : 'month'} ${details ? 'with-details' : ''}`} role="grid">
            {DAYS.map((d) => (
              <div key={d} className="cal-head" role="columnheader">
                <span className="full">{d}</span><span className="short">{d.slice(0, 3)}</span>
              </div>
            ))}
            {days.map(dayCell)}
          </div>
        )}

        {view !== 'list' && (
          <section className="cal-day-panel">
            <div className="cal-day-head">
              <h3>{longDay(selectedDay)}{selectedDay === today ? " · Aujourd'hui" : ''}</h3>
              <button className="btn btn-ghost small" onClick={() => setEditor({ date: selectedDay })}>＋ Programmer ce jour</button>
            </div>
            {selectedItems.length === 0
              ? <p className="helper">Rien de prévu ce jour-là. Double-cliquez sur un jour ou touchez « Programmer » pour ajouter quelque chose.</p>
              : <AgendaList items={selectedItems} onOpen={openItem} details />}
          </section>
        )}

        {view === 'list' && (
          <div className="cal-list">
            {!loading && listDays.length === 0 && (
              <div className="empty-state"><span aria-hidden>📅</span><p>Rien de programmé pour {labelCap.toLowerCase()}.</p></div>
            )}
            {listDays.map((d) => {
              const key = ymd(d)
              return (
                <div key={key} className={`cal-list-day ${key === today ? 'is-today' : ''} ${key < today ? 'is-past' : ''}`}>
                  <div className="cal-list-date">
                    <span className="num">{d.getDate()}</span>
                    <span className="dow">{d.toLocaleDateString('fr-CA', { weekday: 'short' }).replace('.', '')}</span>
                  </div>
                  <AgendaList items={byDay.get(key) ?? []} onOpen={openItem} details={details} />
                </div>
              )
            })}
          </div>
        )}

        <div className="cal-legend">
          <span className="legend-item"><i className="legend-swatch chip-evt-culte" /> Culte</span>
          <span className="legend-item"><i className="legend-swatch chip-evt-priere" /> Prière</span>
          <span className="legend-item"><i className="legend-swatch chip-evt-formation" /> Formation</span>
          <span className="legend-item"><i className="legend-swatch chip-evt-reunion" /> Réunion</span>
          <span className="legend-item"><i className="legend-swatch chip-evt-sortie" /> Sortie</span>
          <span className="legend-item"><span className="recur-icon">↻</span> Récurrent</span>
          <label className="legend-item toggle"><input type="checkbox" checked={filters.holidays} onChange={(e) => setFilters({ ...filters, holidays: e.target.checked })} /><i className="legend-swatch chip-holiday" /> Jours fériés et fêtes</label>
          <label className="legend-item toggle"><input type="checkbox" checked={filters.birthdays} onChange={(e) => setFilters({ ...filters, birthdays: e.target.checked })} /><i className="legend-swatch chip-birthday" /> Anniversaires <i className="legend-swatch chip-wedding" /> et mariages</label>
          <label className="legend-item toggle"><input type="checkbox" checked={filters.tasks} onChange={(e) => setFilters({ ...filters, tasks: e.target.checked })} /><i className="legend-swatch chip-task" /> Mes échéances</label>
        </div>
      </div>

      {editor && (
        <EventEditor date={editor.date} event={editor.event ?? null}
          onClose={() => setEditor(null)} onSaved={() => { load() }} />
      )}

      {modal && (
        <div className="modal-overlay" onClick={() => setModal(null)}>
          <div className="modal-box cal-modal" role="dialog" aria-modal="true" onClick={(e) => e.stopPropagation()}>
            <div className="panel-head">
              <h3>{modal.kind === 'birthday' ? '🎂 Anniversaire' : modal.kind === 'wedding' ? '💍 Anniversaire de mariage' : modal.title}</h3>
              <button className="btn-link" onClick={() => setModal(null)}>Fermer</button>
            </div>
            {modal.kind === 'event' && (
              <>
                <div className="cal-modal-tags">
                  <span className={`cat-badge evt-${modal.category}`}>{CAT_LABEL[modal.category]}</span>
                  {modal.recurring && <span className="chip">↻ {modal.recurrence_label}</span>}
                  {modal.personal && <span className="chip">🔒 Privé</span>}
                </div>
                <p className="event-meta mt">🕒 {longDay(modal.occurs_on)} · {timeRange(modal)}</p>
                {modal.location && <p className="event-meta">📍 {modal.location}</p>}
                <p className="event-meta-soft">👥 {modal.target}</p>
                {modal.description && <p className="event-desc">{modal.description}</p>}
                {modal.image_url && <img className="event-image mt" src={modal.image_url} alt={modal.title} />}
                {modal.can_edit && (
                  <div className="editor-actions mt">
                    <button className="btn btn-ghost small" onClick={() => { setEditor({ event: modal }); setModal(null) }}>✏️ Modifier{modal.recurring ? ' la série' : ''}</button>
                  </div>
                )}
                {modal.occurs_on >= today && !modal.personal && (
                  <>
                    <div className="rsvp">
                      <button className={`rsvp-btn ${modal.my_response === 'present' ? 'on' : ''}`} onClick={() => rsvp(modal, 'present')}>Je serai présent</button>
                      <button className={`rsvp-btn ${modal.my_response === 'absent' ? 'off' : ''}`} onClick={() => rsvp(modal, 'absent')}>Absent</button>
                      {modal.going_count > 0 && <span className="rsvp-count">{modal.going_count} inscrit(s)</span>}
                    </div>
                    {modal.my_response === 'present' && (
                      <label className="rsvp-volunteer">
                        <input type="checkbox" checked={modal.my_volunteer} onChange={(e) => rsvp(modal, 'present', e.target.checked)} />
                        Je souhaite me porter volontaire
                      </label>
                    )}
                  </>
                )}
              </>
            )}
            {modal.kind === 'birthday' && (
              <>
                <p className="event-meta">{longDay(modal.date)}</p>
                <ul className="cal-people">
                  {modal.people.map((p) => <li key={p.user_id}>🎉 {p.name}</li>)}
                </ul>
                <p className="helper">Pensez à leur souhaiter une bonne fête !</p>
              </>
            )}
            {modal.kind === 'wedding' && (
              <>
                <p className="event-meta">{longDay(modal.date)}</p>
                <ul className="cal-people">
                  {modal.people.map((p) => <li key={p.user_id}>💍 {p.name}</li>)}
                </ul>
                <p className="helper">Une belle occasion de bénir ce couple !</p>
              </>
            )}
            {modal.kind === 'holiday' && (
              <p className="event-meta">{longDay(modal.date)} · {modal.holiday_kind === 'ferie' ? 'Jour férié' : modal.holiday_kind === 'chretien' ? 'Fête chrétienne' : 'Journée spéciale'}</p>
            )}
          </div>
        </div>
      )}
    </AppLayout>
  )
}

function AgendaList({ items, onOpen, details }: { items: CalendarItem[]; onOpen: (i: CalendarItem) => void; details: boolean }) {
  return (
    <div className="agenda">
      {items.map((i) => (
        <button key={`${i.key}-${itemDate(i)}`} className={`agenda-row ${chipClass(i)}`} onClick={() => onOpen(i)}>
          <span className="agenda-time">
            {i.kind === 'event' ? (i.all_day ? 'Journée' : hhmm(i.starts_at)) : i.kind === 'task' ? 'Échéance' : 'Journée'}
          </span>
          <span className="agenda-text">
            <span className="agenda-title">
              {i.kind === 'event' && i.recurring && <span className="recur-icon">↻</span>}
              {i.kind === 'birthday' && '🎂 '}
              {i.kind === 'wedding' && '💍 '}
              {i.kind === 'task' && (i.done ? '✅ ' : '📝 ')}
              {i.title}
            </span>
            {i.kind === 'event' && (
              <span className="agenda-meta">
                {CAT_LABEL[i.category]}{i.location ? ` · 📍 ${i.location}` : ''}{i.my_response === 'present' ? ' · ✓ Inscrit' : ''}
                {details && i.description ? ` — ${i.description.slice(0, 120)}` : ''}
              </span>
            )}
            {i.kind === 'holiday' && <span className="agenda-meta">{i.holiday_kind === 'ferie' ? 'Jour férié' : i.holiday_kind === 'chretien' ? 'Fête chrétienne' : 'Journée spéciale'}</span>}
            {i.kind === 'task' && <span className="agenda-meta">{i.done ? 'Fait' : 'À faire'}</span>}
          </span>
        </button>
      ))}
    </div>
  )
}
