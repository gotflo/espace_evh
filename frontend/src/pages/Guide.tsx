import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { AppLayout } from '../components/AppLayout'
import { Icon } from '../components/Icon'
import { ModalLayer } from '../components/ModalLayer'
import { GUIDE_CHAPTERS, GUIDE_FAQ, type GuideChapter, type GuideSection, type GuideShot } from '../data/guide'

/** Texte sans accents ni majuscules, pour la recherche. */
const norm = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()

/** Tout le texte d'une section (recherche). */
function sectionText(s: GuideSection): string {
  return [
    s.title, s.intro, s.privacy, s.warning,
    ...(s.steps ?? []).flatMap((st) => [st.title, st.text]),
    ...(s.tips ?? []),
    ...(s.shots ?? []).map((sh) => sh.caption),
    ...(s.timeline ?? []).flatMap((t) => [t.when, t.what]),
    ...(s.table?.rows ?? []).flat(),
    ...(s.compare ? [...s.compare.sees, ...s.compare.doesNotSee] : []),
    ...(s.checklist ?? []).flatMap((c) => [c.when, c.what]),
  ].filter(Boolean).join(' ')
}

const src = (name: string) => `/guide-captures/${name}.webp`
const src2x = (name: string) => `/guide-captures/${name}@2x.webp`

/** Captures iPhone d'une section : rangee qui defile sur telephone, touchees pour agrandir. */
function Shots({ shots, onOpen }: { shots: GuideShot[]; onOpen: (index: number) => void }) {
  return (
    <div className="gd-shots" role="list">
      {shots.map((shot, i) => (
        <figure key={shot.name} className="gd-shot" role="listitem">
          <button type="button" className="gd-shot-btn" onClick={() => onOpen(i)} aria-label={`Agrandir : ${shot.caption}`}>
            <img src={src(shot.name)} srcSet={`${src(shot.name)} 480w, ${src2x(shot.name)} 960w`} sizes="(max-width: 600px) 58vw, 230px"
              width={480} height={986} loading="lazy" decoding="async" alt={shot.caption} />
          </button>
          <figcaption>{shot.caption}</figcaption>
        </figure>
      ))}
    </div>
  )
}

function SectionView({ section, showLink, onZoom }: { section: GuideSection; showLink: boolean; onZoom: (shots: GuideShot[], index: number) => void }) {
  return (
    <section className="gd-section" id={`guide-${section.id}`} aria-labelledby={`guide-${section.id}-title`}>
      <h4 id={`guide-${section.id}-title`} className="gd-section-title">
        {section.title}
        {section.audience === 'garde' && <span className="gd-badge"><Icon name="roles" size={12} />Garde</span>}
      </h4>
      {section.intro && <p className="gd-intro">{section.intro}</p>}
      {section.shots && <Shots shots={section.shots} onOpen={(i) => onZoom(section.shots!, i)} />}
      {section.steps && (
        <ol className="gd-steps">
          {section.steps.map((st, i) => (
            <li key={st.title}>
              <span className="gd-step-num" aria-hidden="true">{i + 1}</span>
              <div><strong>{st.title}</strong><p>{st.text}</p></div>
            </li>
          ))}
        </ol>
      )}
      {section.timeline && (
        <ol className="gd-timeline">
          {section.timeline.map((t) => (
            <li key={t.when} className={t.tone ? `gd-tl-${t.tone}` : undefined}>
              <span className="gd-tl-dot" aria-hidden="true" />
              <div><strong>{t.when}</strong><p>{t.what}</p></div>
            </li>
          ))}
        </ol>
      )}
      {section.table && (
        <div className="table-scroll">
          <table className="gd-table">
            <thead><tr><th scope="col">{section.table.head[0]}</th><th scope="col">{section.table.head[1]}</th></tr></thead>
            <tbody>{section.table.rows.map(([a, b]) => <tr key={a}><th scope="row">{a}</th><td>{b}</td></tr>)}</tbody>
          </table>
        </div>
      )}
      {section.compare && (
        <div className="gd-compare">
          <div className="gd-compare-col gd-yes">
            <h5><Icon name="eye" size={16} />Le Garde voit</h5>
            <ul>{section.compare.sees.map((t) => <li key={t}><Icon name="check" size={15} />{t}</li>)}</ul>
          </div>
          <div className="gd-compare-col gd-no">
            <h5><Icon name="lock" size={16} />Le Garde ne voit pas</h5>
            <ul>{section.compare.doesNotSee.map((t) => <li key={t}><Icon name="close" size={15} />{t}</li>)}</ul>
          </div>
        </div>
      )}
      {section.checklist && (
        <ul className="gd-checklist">
          {section.checklist.map((c) => (
            <li key={c.when}><Icon name="validate" size={18} /><div><strong>{c.when}</strong><p>{c.what}</p></div></li>
          ))}
        </ul>
      )}
      {section.warning && <p className="gd-box gd-warning"><Icon name="alert" size={18} /><span>{section.warning}</span></p>}
      {section.privacy && <p className="gd-box gd-privacy"><Icon name="lock" size={18} /><span>{section.privacy}</span></p>}
      {section.tips?.map((t) => <p key={t} className="gd-box gd-tip"><Icon name="help" size={18} /><span>{t}</span></p>)}
      {showLink && section.link && (
        <Link to={section.link.to} className="btn btn-ghost small gd-open">{section.link.label}<Icon name="chevron" size={16} /></Link>
      )}
    </section>
  )
}

/**
 * Guide de l'application (repris du PowerPoint « Guide du Garde ») : chapitres illustres de captures iPhone,
 * etapes, astuces et « qui voit quoi ». Partie « tous » pour chaque fidele ; partie Gardes pour les Gardes et
 * leurs responsables. Recherche sans accents ; un lien /guide#rapport ouvre directement le chapitre.
 */
export default function Guide() {
  const { roles, hasPermission, moduleOn } = useAuth()
  const location = useLocation()
  const isGarde = roles.some((r) => r.key === 'garde' && r.scope_kind === 'gem')
  // Responsables qui encadrent des Gardes : pasteurs (toute l'eglise), patriarches et AP (leurs tribus).
  const supervisesGardes = hasPermission('members.view_all') || roles.some((r) => r.scope_kind === 'tribe' && r.key !== 'department_leader')
  const seesGarde = isGarde || supervisesGardes

  const [query, setQuery] = useState('')
  const [open, setOpen] = useState<Set<string>>(() => new Set())
  const [zoom, setZoom] = useState<{ shots: GuideShot[]; index: number } | null>(null)
  const closeRef = useRef<HTMLButtonElement>(null)
  const returnFocus = useRef<HTMLElement | null>(null)

  // Chapitres et sections visibles pour cette personne (role, modules actives).
  const chapters = useMemo(() => GUIDE_CHAPTERS
    .filter((c) => c.part === 'tous' || seesGarde)
    .map((c) => ({ ...c, sections: c.sections.filter((s) => (s.audience !== 'garde' || seesGarde) && (!s.module || moduleOn(s.module))) }))
    .filter((c) => c.sections.length > 0), [seesGarde, moduleOn])
  const faq = GUIDE_FAQ.filter((f) => f.audience === 'tous' || seesGarde)

  const q = norm(query.trim())
  const matches = (c: GuideChapter) => !q || norm([c.title, c.summary, ...c.sections.map(sectionText)].join(' ')).includes(q)
  const shown = chapters.filter(matches)
  const shownFaq = faq.filter((f) => !q || norm(f.q + ' ' + f.a).includes(q))

  // Lien direct (#chapitre ou #section) : le chapitre s'ouvre et vient a l'ecran.
  useEffect(() => {
    const id = decodeURIComponent(location.hash.replace(/^#/, ''))
    if (!id) return
    const chapter = chapters.find((c) => c.id === id || c.sections.some((s) => s.id === id))
    if (!chapter) return
    setOpen((o) => new Set(o).add(chapter.id))
    const target = chapter.id === id ? `guide-chapter-${id}` : `guide-${id}`
    requestAnimationFrame(() => document.getElementById(target)?.scrollIntoView({ block: 'start', behavior: 'smooth' }))
  }, [location.hash, chapters])

  const toggle = (id: string) => setOpen((o) => {
    const next = new Set(o)
    if (next.has(id)) next.delete(id)
    else next.add(id)
    return next
  })

  // Agrandissement : Echap ferme, fleches pour passer d'une capture a l'autre ; le focus revient au bouton.
  useEffect(() => {
    if (!zoom) return
    closeRef.current?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setZoom(null)
      if (e.key === 'ArrowRight') setZoom((z) => z && { ...z, index: Math.min(z.shots.length - 1, z.index + 1) })
      if (e.key === 'ArrowLeft') setZoom((z) => z && { ...z, index: Math.max(0, z.index - 1) })
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [zoom])
  const openZoom = (shots: GuideShot[], index: number) => {
    returnFocus.current = document.activeElement as HTMLElement | null
    setZoom({ shots, index })
  }
  const closeZoom = () => {
    setZoom(null)
    returnFocus.current?.focus()
  }

  const renderChapter = (c: GuideChapter, number: number) => {
    const expanded = !!q || open.has(c.id)
    return (
      <article key={c.id} className={`gd-chapter${expanded ? ' open' : ''}`} id={`guide-chapter-${c.id}`}>
        <button type="button" className="gd-chapter-head" aria-expanded={expanded} aria-controls={`guide-body-${c.id}`}
          onClick={() => { toggle(c.id); history.replaceState(null, '', expanded ? location.pathname : `#${c.id}`) }} disabled={!!q}>
          <span className="gd-chapter-num" aria-hidden="true">{String(number).padStart(2, '0')}</span>
          <span className="gd-chapter-icon" aria-hidden="true"><Icon name={c.icon} size={20} /></span>
          <span className="gd-chapter-text">
            <strong>{c.title}</strong>
            <small>{c.summary}</small>
          </span>
          <Icon name="chevron" size={18} className="gd-chevron" />
        </button>
        {expanded && (
          <div className="gd-chapter-body" id={`guide-body-${c.id}`}>
            {c.sections.map((s) => <SectionView key={s.id} section={s} showLink={c.part === 'tous' || isGarde} onZoom={openZoom} />)}
          </div>
        )}
      </article>
    )
  }

  const everyone = shown.filter((c) => c.part === 'tous')
  const gardes = shown.filter((c) => c.part === 'garde')
  const current = zoom ? zoom.shots[zoom.index] : null

  return (
    <AppLayout title="Guide" subtitle="L’application, écran par écran">
      <div className="gd-page">
        <section className="panel gd-hero">
          <p>Tout ce que vous pouvez faire dans My vasesdhonneur, en chapitres courts. Touchez une capture pour l’agrandir.</p>
          <label className="gd-search">
            <Icon name="search" size={18} />
            <input type="search" className="input" placeholder="Rechercher (rapport, code SMS, fiche…)" value={query}
              onChange={(e) => setQuery(e.target.value)} aria-label="Rechercher dans le guide" />
          </label>
          <p className="helper">Les captures montrent des personnes fictives.</p>
        </section>

        {q && shown.length === 0 && shownFaq.length === 0 && (
          <p className="gd-empty">Aucun résultat pour « {query.trim()} ». Essayez un autre mot (ex. : rapport, profil, notifications).</p>
        )}

        {everyone.length > 0 && (
          <div className="gd-part">
            <h3 className="gd-part-title">Pour tous</h3>
            {everyone.map((c) => renderChapter(c, chapters.indexOf(c) + 1))}
          </div>
        )}

        {gardes.length > 0 && (
          <div className="gd-part">
            <h3 className="gd-part-title"><Icon name="roles" size={18} />Pour les Gardes</h3>
            {!isGarde && <p className="helper gd-part-note">Vous encadrez des Gardes : ces chapitres montrent ce qu’ils voient et font dans l’application.</p>}
            {gardes.map((c) => renderChapter(c, chapters.indexOf(c) + 1))}
          </div>
        )}

        {shownFaq.length > 0 && (
          <section className="panel gd-faq" id="guide-faq" aria-labelledby="guide-faq-title">
            <div className="panel-head"><h3 id="guide-faq-title">Questions fréquentes</h3></div>
            {shownFaq.map((f) => (
              <details key={f.q} open={!!q}>
                <summary>{f.q}{f.audience === 'garde' && <span className="gd-badge">Garde</span>}</summary>
                <p>{f.a}</p>
              </details>
            ))}
            <p className="helper">Une autre question ? Écrivez à un responsable depuis <Link to="/contact">Nous contacter</Link>.</p>
          </section>
        )}
      </div>

      {zoom && current && (
        <ModalLayer className="modal-overlay gd-zoom" role="dialog" aria-modal="true" aria-label={current.caption} onClick={closeZoom}>
          <div className="gd-zoom-box" onClick={(e) => e.stopPropagation()}>
            <img src={src2x(current.name)} width={960} height={1972} alt={current.caption} />
            <p className="gd-zoom-caption">{current.caption}</p>
            <div className="gd-zoom-actions">
              <button type="button" className="btn btn-ghost small" disabled={zoom.index === 0}
                onClick={() => setZoom({ ...zoom, index: zoom.index - 1 })} aria-label="Capture précédente"><Icon name="back" size={16} /></button>
              <span className="helper">{zoom.index + 1} / {zoom.shots.length}</span>
              <button type="button" className="btn btn-ghost small" disabled={zoom.index === zoom.shots.length - 1}
                onClick={() => setZoom({ ...zoom, index: zoom.index + 1 })} aria-label="Capture suivante"><Icon name="chevron" size={16} /></button>
              <button type="button" ref={closeRef} className="btn btn-primary small" onClick={closeZoom}>Fermer</button>
            </div>
          </div>
        </ModalLayer>
      )}
    </AppLayout>
  )
}
