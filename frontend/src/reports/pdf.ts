// Generation des rapports PDF (sur l'appareil, a partir des donnees fournies par le serveur pour l'export :
// droit verifie et export journalise par lui). pdfmake est charge a la demande : aucun poids au demarrage.
import type { Content, ContentTable, TDocumentDefinitions } from 'pdfmake/interfaces'
import { columnChartSvg, hbarChartSvg, lineChartSvg, VIZ } from '../components/charts'
import type { LeaderReportDetail, LeadersData, ReportAnswer, ReportData, ReportMemberRow, ReportQuestion } from '../types'
import { isEmpty, isVisible, KIND_LABEL } from '../utils/leaderReports'

// --------------------------------------------------------------------------- Charte

const TEAL = '#0d5f57'
const TEAL_DARK = '#0a4a44'
const TEAL_SOFT = '#e7f1ef'
const GOLD = '#d9a400'
const INK = '#17211f'
const MUTED = '#586863'
const LINE = '#e1e7e8'
const ZEBRA = '#f6f9f9'
const OK = '#067647'
const WARN = '#9a6200'
const CHURCH = "Vases d'Honneur Chicoutimi"

/** Hauteur du bandeau de la premiere page (points). */
const BAND = 104

async function loadPdfMake() {
  const pdfMake = (await import('pdfmake/build/pdfmake')).default as unknown as {
    vfs: Record<string, string>
    createPdf: (doc: TDocumentDefinitions) => { download: (name: string) => void; open: () => void }
  }
  const fonts = (await import('pdfmake/build/vfs_fonts')) as unknown as { default?: unknown; pdfMake?: { vfs: Record<string, string> }; vfs?: Record<string, string> }
  const mod = (fonts.default ?? fonts) as { pdfMake?: { vfs: Record<string, string> }; vfs?: Record<string, string> } & Record<string, string>
  pdfMake.vfs = mod.pdfMake?.vfs ?? mod.vfs ?? (mod as Record<string, string>)
  return pdfMake
}

async function logoDataUrl(): Promise<string | null> {
  try {
    const blob = await (await fetch('/logo-vh.png')).blob()
    return await new Promise((resolve) => {
      const r = new FileReader()
      r.onload = () => resolve(String(r.result))
      r.onerror = () => resolve(null)
      r.readAsDataURL(blob)
    })
  } catch { return null }
}

const pct = (v: number | null) => (v === null ? '—' : `${Math.round(v)} %`)
const num = (v: number | null, d = 1) => (v === null ? '—' : v.toFixed(d).replace('.', ','))
const date = (iso: string) => (/^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso))
  .toLocaleDateString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric' })
const dateTime = (d: Date) => d.toLocaleString('fr-CA', { day: 'numeric', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' })
const fileSafe = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase()
const plural = (n: number, one: string, many = `${one}s`) => `${n} ${n > 1 ? many : one}`
/** Date courte (« 8 oct. 2026 ») ; mois seul (« oct. 2026 ») pour une periode AAAA-MM. */
const short = (iso: string | null) => {
  if (!iso) return ''
  if (/^\d{4}-\d{2}$/.test(iso)) return new Date(`${iso}-15T12:00:00`).toLocaleDateString('fr-CA', { month: 'short', year: 'numeric' })
  return (/^\d{4}-\d{2}-\d{2}$/.test(iso) ? new Date(`${iso}T12:00:00`) : new Date(iso)).toLocaleDateString('fr-CA', { day: 'numeric', month: 'short', year: 'numeric' })
}
/** Numero lisible : +1 418-555-0200 (Amerique du Nord), sinon tel quel. */
const phone = (p: string | null) => (p && /^\+1\d{10}$/.test(p) ? `+1 ${p.slice(2, 5)}-${p.slice(5, 8)}-${p.slice(8)}` : p ?? '—')

// --------------------------------------------------------------------------- Mise en page commune

/**
 * Cadre de page : bandeau teal (premiere page) avec le logo sur une tuile blanche, filet or ; pages
 * suivantes : liseré teal et rappel du titre en en-tete ; pied de page avec confidentialite et pagination.
 */
function frame(title: string, logo: string | null, landscape = false): Pick<TDocumentDefinitions, 'background' | 'header' | 'footer' | 'pageMargins'> {
  const width = landscape ? 841.89 : 595.28
  const side = landscape ? 34 : 40
  const exportedAt = dateTime(new Date())
  return {
    pageMargins: [side, 52, side, 52],
    background: (page: number) => (page === 1
      ? {
        canvas: [
          { type: 'rect', x: 0, y: 0, w: width, h: BAND, color: TEAL },
          { type: 'rect', x: 0, y: BAND, w: width, h: 3, color: GOLD },
          ...(logo ? [{ type: 'rect' as const, x: side, y: 22, w: 60, h: 60, r: 8, color: '#ffffff' }] : []),
        ],
      }
      : { canvas: [{ type: 'rect', x: 0, y: 0, w: width, h: 5, color: TEAL }, { type: 'rect', x: 0, y: 5, w: width, h: 1.5, color: GOLD }] }),
    header: (page: number) => (page === 1
      ? null
      : {
        columns: [
          { text: title, fontSize: 8, bold: true, color: TEAL },
          { text: CHURCH, fontSize: 8, color: MUTED, alignment: 'right' },
        ],
        margin: [side, 18, side, 0],
      }),
    footer: (page: number, pages: number) => ({
      stack: [
        { canvas: [{ type: 'line', x1: 0, y1: 0, x2: width - 2 * side, y2: 0, lineWidth: 0.6, lineColor: LINE }] },
        {
          columns: [
            { text: `Document confidentiel — usage pastoral · exporté le ${exportedAt}`, fontSize: 7, color: MUTED },
            { text: `Page ${page} / ${pages}`, fontSize: 7, color: MUTED, alignment: 'right', width: 70 },
          ],
          margin: [0, 6, 0, 0],
        },
      ],
      margin: [side, 14, side, 0],
    }),
  }
}

/**
 * Titre de la premiere page, pose sur le bandeau (logo sur sa tuile blanche, nom de l'eglise, titre,
 * sous-titre), puis l'espace laisse sous le bandeau.
 */
function cover(title: string, subtitle: string, logo: string | null, landscape = false): Content[] {
  const side = landscape ? 34 : 40
  const width = (landscape ? 841.89 : 595.28) - 2 * side - (logo ? 78 : 0)
  return [
    ...(logo ? [{ image: logo, width: 50, absolutePosition: { x: side + 5, y: 27 } } as Content] : []),
    {
      stack: [
        { text: CHURCH.toUpperCase(), fontSize: 8, bold: true, color: GOLD, characterSpacing: 1.6 },
        { text: title, fontSize: title.length > 48 ? 15 : 18, bold: true, color: '#ffffff', margin: [0, 4, 0, 3] },
        { text: subtitle, fontSize: 9, color: '#d6ebe7' },
      ],
      width,
      absolutePosition: { x: side + (logo ? 78 : 0), y: 28 },
    } as Content,
    { text: '', margin: [0, BAND - 52 + 14, 0, 0] },
  ]
}

/** Fiche d'identite du document : quatre informations cles sur un fond clair. */
function metaBox(items: [string, string][]): Content {
  return {
    table: {
      widths: items.map(([, value]) => (value.length > 22 ? 'auto' : '*')),
      body: [items.map(([label, value]) => ({
        stack: [
          { text: label.toUpperCase(), fontSize: 6.5, bold: true, color: MUTED, characterSpacing: 0.8 },
          { text: value, fontSize: 9.5, bold: true, color: INK, margin: [0, 2, 0, 0] },
        ],
        fillColor: TEAL_SOFT,
      }))],
    },
    layout: {
      hLineWidth: () => 0, vLineWidth: (i: number, node: ContentTable) => (i > 0 && i < node.table.body[0].length ? 1 : 0),
      vLineColor: () => '#ffffff', paddingLeft: () => 10, paddingRight: () => 10, paddingTop: () => 8, paddingBottom: () => 8,
    },
    margin: [0, 0, 0, 6],
  }
}

/** Titre de section avec repere or a gauche (jamais orphelin en bas de page : voir keepWith). */
function section(title: string, n?: number): Content {
  return {
    table: {
      widths: [3, '*'],
      body: [[
        { text: '', fillColor: GOLD },
        { text: [n ? { text: `${String(n).padStart(2, '0')}  `, color: GOLD } : '', title], fontSize: 12, bold: true, color: TEAL },
      ]],
    },
    layout: { hLineWidth: () => 0, vLineWidth: () => 0, paddingLeft: (i: number) => (i === 0 ? 0 : 8), paddingRight: () => 0, paddingTop: () => 2, paddingBottom: () => 2 },
    margin: [0, 18, 0, 8],
  }
}

/** Section et son premier bloc ensemble (pas de titre seul en bas de page). */
const keep = (...blocks: Content[]): Content => ({ stack: blocks, unbreakable: true })

function kpiGrid(items: { label: string; value: string; sub?: string; tone?: 'ok' | 'warn' }[], perRow = 4): Content {
  const cells = items.map((k) => ({
    stack: [
      { canvas: [{ type: 'rect', x: 0, y: 0, w: 22, h: 2.5, color: k.tone === 'warn' ? WARN : GOLD }] },
      { text: k.value, fontSize: 17, bold: true, color: k.tone === 'ok' ? OK : k.tone === 'warn' ? WARN : INK, margin: [0, 6, 0, 0] },
      { text: k.label, fontSize: 8, bold: true, color: TEAL_DARK, margin: [0, 2, 0, 0] },
      ...(k.sub ? [{ text: k.sub, fontSize: 7, color: MUTED, margin: [0, 1, 0, 0] }] : []),
    ],
    fillColor: '#ffffff',
  }))
  const rows: Content[][] = []
  for (let i = 0; i < cells.length; i += perRow) {
    const row: Content[] = cells.slice(i, i + perRow) as Content[]
    while (row.length < perRow) row.push({ text: '', border: [false, false, false, false] } as Content)
    rows.push(row)
  }
  return {
    table: { widths: Array(perRow).fill('*'), body: rows as never, dontBreakRows: true },
    layout: {
      hLineWidth: () => 0.8, vLineWidth: () => 0.8, hLineColor: () => LINE, vLineColor: () => LINE,
      paddingLeft: () => 10, paddingRight: () => 8, paddingTop: () => 9, paddingBottom: () => 9,
    },
  }
}

function legend(items: { label: string; color: string }[]): Content {
  return {
    columns: items.map((i) => ({
      width: 'auto',
      columns: [{ canvas: [{ type: 'rect', x: 0, y: 2, w: 10, h: 6, r: 2, color: i.color }], width: 14 }, { text: i.label, fontSize: 8, color: MUTED, width: 'auto' }],
      columnGap: 2,
    })),
    columnGap: 14,
    margin: [0, 0, 0, 4],
  }
}

type Cell = string | number | { text: string; color?: string; bold?: boolean }

/**
 * Tableau : en-tete teal repete a chaque page, lignes alternees, filets fins, nombres alignes a droite,
 * une ligne jamais coupee entre deux pages.
 */
function table(headers: string[], rows: Cell[][], widths?: (string | number)[], align?: ('left' | 'right' | 'center')[]): Content {
  const al = (i: number) => align?.[i] ?? 'left'
  return {
    table: {
      headerRows: 1,
      dontBreakRows: true,
      keepWithHeaderRows: 1,
      widths: widths ?? headers.map(() => '*'),
      body: [
        headers.map((h, i) => ({ text: h, bold: true, fontSize: 7.5, color: '#ffffff', fillColor: TEAL, alignment: al(i) })),
        ...rows.map((r, ri) => r.map((c, i) => {
          const cell = typeof c === 'object' ? c : { text: String(c) }
          return { ...cell, fontSize: 8, alignment: al(i), ...(ri % 2 ? { fillColor: ZEBRA } : {}) }
        })),
      ],
    },
    layout: {
      hLineWidth: (i: number, node: ContentTable) => (i === 0 || i === 1 ? 0 : i === node.table.body.length ? 1 : 0.5),
      vLineWidth: () => 0, hLineColor: (i: number, node: ContentTable) => (i === node.table.body.length ? TEAL : LINE),
      paddingTop: () => 5, paddingBottom: () => 5, paddingLeft: () => 6, paddingRight: () => 6,
    },
  }
}

const empty = (text: string): Content => ({ text, italics: true, color: MUTED, fontSize: 8.5, margin: [0, 2, 0, 4] })

function baseDoc(title: string, defaults: Partial<TDocumentDefinitions> = {}): Partial<TDocumentDefinitions> {
  return {
    pageSize: 'A4',
    info: { title, author: CHURCH, creator: 'My vasesdhonneur', subject: title },
    defaultStyle: { font: 'Roboto', fontSize: 9, color: INK, lineHeight: 1.15 },
    ...defaults,
  }
}

// --------------------------------------------------------------------------- Rapport de la portee

/** Document du rapport complet de la portee (tribu, mes tribus, eglise). */
export function buildReportDoc(report: ReportData, logo: string | null): TDocumentDefinitions {
  const k = report.kpis
  const labels = report.monthly.map((m) => m.label)
  const title = `Rapport — ${report.scope.label}`
  let n = 0
  const content: Content[] = [
    ...cover(title, `Période : ${date(report.period.from)} au ${date(report.period.to)}`, logo),
    metaBox([
      ['Périmètre', report.scope.label],
      ['Période', `${date(report.period.from)} au ${date(report.period.to)}`],
      ['Membres', String(k.members)],
      ['Données du', date(report.generated_at)],
    ]),
    keep(section('Chiffres clés', ++n), kpiGrid([
      { label: 'Membres', value: String(k.members), sub: `${k.active} actifs · ${k.inactive} inactifs` },
      { label: 'Vie spirituelle (FISS)', value: pct(k.spiritual_score), sub: 'moyenne des fiches remplies' },
      { label: 'FISS du mois', value: pct(k.fiss_rate), sub: `${plural(k.fiss_missing, 'membre actif', 'membres actifs')} sans fiche`, tone: k.fiss_missing > 0 ? 'warn' : 'ok' },
      { label: 'Assiduité', value: pct(k.attendance_rate), sub: 'présences aux cultes' },
      { label: 'Notes', value: k.vertumetre !== null ? `${num(k.vertumetre)} /20` : '—', sub: 'moyenne des notes' },
      { label: 'Nouveaux membres', value: String(k.new_members), sub: 'sur la période', tone: k.new_members > 0 ? 'ok' : undefined },
      { label: 'Profils complétés', value: k.profile_completion_avg !== null ? `${k.profile_completion_avg} %` : '—', sub: `${plural(k.incomplete_profiles, 'profil incomplet', 'profils incomplets')}` },
      { label: 'Événements', value: String(k.events), sub: plural(k.participations, 'participation') },
    ])),
    keep(
      section('Vie spirituelle et fiches mensuelles', ++n),
      legend([{ label: 'Score de vie spirituelle', color: VIZ.s1 }, { label: 'Taux de FISS remplies', color: VIZ.s2 }]),
      { svg: lineChartSvg(labels, [
        { key: 's', label: 'Score', color: VIZ.s1, values: report.monthly.map((m) => m.spiritual_score) },
        { key: 'f', label: 'FISS', color: VIZ.s2, values: report.monthly.map((m) => m.fiss_rate) },
      ], '%', 100, 520, 135), width: 515 },
    ),
    keep(
      section('Assiduité aux cultes', ++n),
      { svg: lineChartSvg(labels, [{ key: 'a', label: 'Assiduité', color: VIZ.s3, values: report.monthly.map((m) => m.attendance_rate) }], '%', 100, 520, 135), width: 515 },
    ),
    keep(
      section('Nouveaux membres par mois', ++n),
      { svg: columnChartSvg(labels, report.monthly.map((m) => m.new_members), VIZ.s1, 520, 115), width: 515 },
    ),
  ]

  const tribes = report.tribes.filter((t) => t.members > 0)
    .sort((a, b) => (b.spiritual_score ?? -1) - (a.spiritual_score ?? -1) || a.name.localeCompare(b.name))
  if (tribes.length > 0) {
    content.push(
      keep(
        section('Comparaison des tribus — vie spirituelle du mois', ++n),
        { svg: hbarChartSvg(tribes.map((t) => ({ label: t.name, value: t.spiritual_score }))), width: 515 },
        { text: 'Une tribu sans fiche remplie ce mois-ci n’a pas de score (elle n’est pas comptée comme 0).', fontSize: 7, color: MUTED, margin: [0, 4, 0, 8] },
      ),
      table(['Tribu', 'Membres', 'Actifs', 'Inactifs', 'FISS du mois', 'Score', 'Mois préc.', 'Profils'],
        tribes.map((t) => [{ text: t.name, bold: true }, t.members, t.active, t.inactive, pct(t.fiss_rate), pct(t.spiritual_score), pct(t.spiritual_score_previous), t.completion_avg !== null ? `${t.completion_avg} %` : '—']),
        ['*', 44, 36, 40, 52, 40, 50, 40], ['left', 'right', 'right', 'right', 'right', 'right', 'right', 'right']),
    )
  }

  content.push(
    keep(
      section('Détail mensuel', ++n),
      table(['Mois', 'Membres', 'Nouveaux', 'FISS', 'Taux FISS', 'Spirituel', 'Social', 'Notes', 'Assiduité', 'Événem.'],
        report.monthly.map((m) => [{ text: m.label, bold: true }, m.members, m.new_members, m.fiss_filled, pct(m.fiss_rate), pct(m.spiritual_score), pct(m.social_score), m.vertumetre !== null ? num(m.vertumetre) : '—', pct(m.attendance_rate), m.events]),
        [40, 40, 42, 30, 42, 44, 40, 38, 46, '*'], ['left', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right', 'right']),
    ),
  )

  const list = (title: string, items: { name: string; tribe: string | null; extra?: string }[]) => {
    if (items.length === 0) return
    content.push(
      section(`${title} (${items.length})`, ++n),
      table(['Nom', ...(report.tribes.length ? ['Tribu'] : []), ...(items.some((i) => i.extra) ? ['Précision'] : [])],
        items.map((p) => [{ text: p.name, bold: true }, ...(report.tribes.length ? [p.tribe ?? '—'] : []), ...(items.some((i) => i.extra) ? [p.extra ?? ''] : [])]),
        ['*', ...(report.tribes.length ? [130] : []), ...(items.some((i) => i.extra) ? [120] : [])]),
    )
  }
  list('Membres actifs sans FISS ce mois-ci', report.missing_fiss.map((p) => ({ name: p.name, tribe: p.tribe })))
  list('Membres inactifs', report.inactive_members.map((p) => ({ name: p.name, tribe: p.tribe, extra: p.since ? `depuis le ${/^\d{4}-\d{2}-\d{2}/.test(p.since) ? date(p.since) : p.since}` : undefined })))
  list('Nouveaux membres', report.new_members_list.map((p) => ({ name: p.name, tribe: p.tribe, extra: p.date ? `inscrit le ${date(p.date)}` : undefined })))

  content.push(keep(
    section('Définitions', ++n),
    {
      table: {
        widths: ['*'],
        body: [[{
          ul: [
            'Score de vie spirituelle : moyenne (0-100) des composantes renseignées de la FISS (méditation, prière, jeûne sur 20 ; sanctification bien = 100, moyen = 50, mal = 0). Les membres sans fiche ne sont pas comptés comme 0.',
            'Taux de FISS : fiches remplies / membres inscrits à la fin du mois.',
            'Assiduité : présences aux cultes / (sessions pointées × membres actifs).',
            'Membre inactif : aucune connexion, aucune FISS et aucune présence depuis 3 mois.',
            'Notes : moyenne des notes /20 données par les responsables.',
          ].map((t) => ({ text: t, fontSize: 7.5, color: MUTED, margin: [0, 1, 0, 1] })),
          fillColor: ZEBRA,
        }]],
      },
      layout: { hLineWidth: () => 0, vLineWidth: () => 0, paddingLeft: () => 10, paddingRight: () => 10, paddingTop: () => 8, paddingBottom: () => 8 },
    },
  ))

  return {
    ...baseDoc(title),
    ...frame(title, logo),
    content,
  } as TDocumentDefinitions
}

/** Rapport complet telecharge en PDF. */
export async function downloadReportPdf(report: ReportData): Promise<void> {
  const [pdfMake, logo] = await Promise.all([loadPdfMake(), logoDataUrl()])
  pdfMake.createPdf(buildReportDoc(report, logo)).download(`rapport-${fileSafe(report.scope.label)}-${report.period.to}.pdf`)
}

// --------------------------------------------------------------------------- Liste des membres

const STATUS: Record<string, { text: string; color: string }> = {
  active: { text: 'Actif', color: OK },
  inactive: { text: 'Inactif', color: WARN },
}

/** Document de la liste des membres (portee et filtre choisis), mise en page paysage. */
export function buildMembersDoc(label: string, filterLabel: string, rows: ReportMemberRow[], logo: string | null): TDocumentDefinitions {
  const seen = (iso: string | null) => (iso ? short(iso) : 'jamais')
  const active = rows.filter((r) => r.status === 'active').length
  const noFiss = rows.filter((r) => !r.last_fiss).length
  const title = `Liste des membres — ${label}`
  return {
    ...baseDoc(title, { pageOrientation: 'landscape', defaultStyle: { font: 'Roboto', fontSize: 8, color: INK, lineHeight: 1.1 } }),
    ...frame(title, logo, true),
    content: [
      ...cover(title, `${filterLabel} · ${plural(rows.length, 'membre')}`, logo, true),
      metaBox([
        ['Périmètre', label],
        ['Filtre', filterLabel],
        ['Membres', String(rows.length)],
        ['Actifs / inactifs', `${active} / ${rows.length - active}`],
        ['Sans FISS', String(noFiss)],
      ]),
      { text: '', margin: [0, 6, 0, 0] },
      rows.length === 0
        ? empty('Aucun membre ne correspond à ce filtre.')
        : table(['Nom', 'Téléphone', 'Tribu', 'GEM', 'Statut', 'Dernière connexion', 'Profil', 'Dernière FISS', 'Score FISS', 'Présences (3 mois)', 'Notes'],
          rows.map((r) => [
            { text: r.name, bold: true }, phone(r.phone), r.tribe ?? '—', r.gem ?? '—',
            { ...(STATUS[r.status] ?? { text: r.status, color: INK }), bold: true },
            seen(r.last_seen), `${r.completion} %`, r.last_fiss ? short(r.last_fiss) : 'aucune', pct(r.fiss_score), r.attendance_3m, r.vertumetre !== null ? num(r.vertumetre) : '—',
          ]),
          ['*', 80, 66, 66, 44, 66, 38, 58, 46, 58, 40],
          ['left', 'left', 'left', 'left', 'left', 'left', 'right', 'left', 'right', 'right', 'right']),
    ],
  } as TDocumentDefinitions
}

/** Liste des membres telechargee en PDF. */
export async function downloadMembersPdf(label: string, filterLabel: string, rows: ReportMemberRow[]): Promise<void> {
  const [pdfMake, logo] = await Promise.all([loadPdfMake(), logoDataUrl()])
  pdfMake.createPdf(buildMembersDoc(label, filterLabel, rows, logo)).download(`membres-${fileSafe(label)}.pdf`)
}

// --------------------------------------------------------------------------- Rapport mensuel d'un responsable

/** Reponse d'une question du rapport mensuel, mise en forme pour le PDF. */
function answerContent(q: ReportQuestion, value: ReportAnswer | undefined): Content {
  if (isEmpty(value)) return { text: 'Non renseigné', italics: true, color: MUTED, fontSize: 9.5 }
  if (q.type === 'choice') return { text: q.options?.find((o) => o.key === value)?.label ?? String(value), bold: true, fontSize: 9.5, color: TEAL_DARK }
  if (q.type === 'checks' && value && typeof value === 'object' && !Array.isArray(value)) {
    return {
      ul: (q.options ?? []).filter((o) => o.key in value).map((o) => ({
        text: [{ text: o.label, bold: true }, { text: value[o.key] ? ` : ${value[o.key]}` : '' }], fontSize: 9.5, margin: [0, 1, 0, 1],
      })),
    }
  }
  if (Array.isArray(value)) return { text: value.join(', '), fontSize: 9.5 }
  return { text: String(value), fontSize: 9.5 }
}

/** Question et sa reponse dans une carte (filet teal a gauche), jamais coupee entre deux pages. */
function answerCard(label: string, answer: Content): Content {
  return {
    table: {
      widths: [2.5, '*'],
      body: [[
        { text: '', fillColor: TEAL },
        { stack: [{ text: label, fontSize: 8, bold: true, color: MUTED, margin: [0, 0, 0, 3] }, answer], fillColor: ZEBRA },
      ]],
    },
    layout: { hLineWidth: () => 0, vLineWidth: () => 0, paddingLeft: (i: number) => (i === 0 ? 0 : 10), paddingRight: () => 10, paddingTop: () => 7, paddingBottom: () => 7 },
    margin: [0, 0, 0, 6],
    unbreakable: true,
  } as Content
}

/** Document d'un rapport mensuel de tribu ou de departement (questions posees et leurs reponses). */
export function buildLeaderReportDoc(report: LeaderReportDetail, logo: string | null): TDocumentDefinitions {
  const label = `${KIND_LABEL[report.kind]} ${report.scope_name}`
  const others = Array.isArray(report.answers.ames_autres) ? report.answers.ames_autres : []
  const period = `${report.period_label.charAt(0).toUpperCase()}${report.period_label.slice(1)}`
  const title = `Rapport mensuel — ${label}`
  const content: Content[] = [
    ...cover(title, period, logo),
    metaBox([
      [KIND_LABEL[report.kind], report.scope_name],
      ['Mois', period],
      ['Rédigé par', report.author ?? '—'],
      ['Envoyé le', report.submitted_at ? date(report.submitted_at) : 'brouillon'],
    ]),
  ]

  report.steps.forEach((step, i) => {
    const blocks: Content[] = []
    if (step.context === 'souls') {
      const total = report.souls.length + others.length
      blocks.push(kpiGrid([{ label: 'Âmes gagnées durant le mois', value: String(total), tone: total > 0 ? 'ok' : undefined }], 3))
      if (report.souls.length > 0) {
        blocks.push({ text: '', margin: [0, 6, 0, 0] }, table(['Nom', 'Date', 'Accueil'],
          report.souls.map((p) => [{ text: p.name, bold: true }, p.date ? date(p.date) : '—', p.integrated === null ? '—' : p.integrated ? { text: 'Accueilli(e)', color: OK, bold: true } : { text: 'À accueillir', color: WARN, bold: true }]),
          ['*', 110, 90]), { text: '', margin: [0, 0, 0, 6] })
      }
    }
    for (const q of step.questions) {
      if (!isVisible(q, report.answers) || (!q.required && isEmpty(report.answers[q.key]))) continue
      blocks.push(answerCard(q.label, answerContent(q, report.answers[q.key])))
    }
    if (blocks.length === 0) blocks.push(empty('Aucune réponse pour cette partie.'))
    // Le titre de la partie reste avec sa premiere reponse.
    content.push(keep(section(step.title, i + 1), blocks[0]), ...blocks.slice(1))
  })

  // Attestation d'envoi.
  content.push({
    table: {
      widths: ['*'],
      body: [[{
        text: report.submitted_at
          ? [{ text: 'Rapport envoyé', bold: true, color: TEAL }, ` le ${date(report.submitted_at)}${report.author ? ` par ${report.author}` : ''}, via My vasesdhonneur.`]
          : [{ text: 'Brouillon', bold: true, color: WARN }, ' : ce rapport n’a pas encore été envoyé.'],
        fontSize: 8.5, color: INK, fillColor: TEAL_SOFT,
      }]],
    },
    layout: { hLineWidth: () => 0, vLineWidth: () => 0, paddingLeft: () => 10, paddingRight: () => 10, paddingTop: () => 8, paddingBottom: () => 8 },
    margin: [0, 18, 0, 0],
    unbreakable: true,
  } as Content)

  return {
    ...baseDoc(`${title} ${report.period}`),
    ...frame(title, logo),
    content,
  } as TDocumentDefinitions
}

/** Rapport mensuel telecharge en PDF. */
export async function downloadLeaderReportPdf(report: LeaderReportDetail): Promise<void> {
  const [pdfMake, logo] = await Promise.all([loadPdfMake(), logoDataUrl()])
  pdfMake.createPdf(buildLeaderReportDoc(report, logo)).download(`rapport-mensuel-${fileSafe(`${report.kind === 'tribe' ? 'tribu' : 'departement'}-${report.scope_name}`)}-${report.period}.pdf`)
}

// --------------------------------------------------------------------------- Annuaire des responsables

/** Document de l'annuaire des responsables (AP, patriarches, responsables de departement, Gardes). */
export function buildLeadersDoc(data: LeadersData, logo: string | null): TDocumentDefinitions {
  const scopeLabel = data.scope === 'church' ? "Toute l'église" : `Tribu ${data.tribes.map((t) => t.name).join(', ')}`
  const title = `Responsables — ${scopeLabel}`
  const gardes = data.gardes.filter((g) => g.garde)
  const withoutGarde = data.gardes.filter((g) => !g.garde)
  let n = 0
  const content: Content[] = [
    ...cover(title, `${plural(data.assistants.length + data.patriarchs.length + data.department_leaders.length + gardes.length, 'fonction')} · rapport des GEMs : ${data.week_label}`, logo),
    metaBox([
      ['Périmètre', scopeLabel],
      ['Tribus', String(data.tribes.length)],
      ['Patriarches', String(data.patriarchs.length)],
      ['Gardes', `${gardes.length} / ${data.gardes.length} GEMs`],
    ]),
  ]
  const people = (label: string, rows: { name: string; phone: string | null; belonging: string[] }[], belongingLabel: string) => {
    content.push(section(`${label} (${rows.length})`, ++n), rows.length === 0
      ? empty('Aucun pour le moment.')
      : table(['Nom', 'Téléphone', belongingLabel],
        rows.map((r) => [{ text: r.name, bold: true }, phone(r.phone), r.belonging.join(', ') || '—']),
        ['*', 100, '*']))
  }
  if (data.scope === 'church') {
    people('Assistants Pasteurs', data.assistants.map((p) => ({ ...p, belonging: p.tribes })), 'Tribus suivies')
  }
  people('Patriarches', data.patriarchs.map((p) => ({ ...p, belonging: p.tribes })), 'Tribu')
  if (data.tribes_without_patriarch.length > 0) {
    content.push({ text: [{ text: 'Tribus sans patriarche : ', bold: true, color: WARN }, data.tribes_without_patriarch.join(', ')], fontSize: 8.5, margin: [0, 6, 0, 0] })
  }
  if (data.scope === 'church') {
    people('Responsables de département', data.department_leaders.map((p) => ({ ...p, belonging: p.departments })), 'Départements')
  }
  content.push(section(`Gardes des GEMs (${gardes.length})`, ++n), data.gardes.length === 0
    ? empty('Aucun GEM pour le moment.')
    : table(['GEM', 'Tribu', 'Membres', 'Garde', 'Téléphone', 'Rapport hebdo.'],
      data.gardes.map((g) => [
        { text: g.gem, bold: true }, g.tribe ?? '—', g.members_count,
        g.garde ? g.garde.name : { text: 'Sans Garde', color: WARN, bold: true }, phone(g.garde?.phone ?? null),
        g.week_report === 'submitted' ? { text: 'Envoyé', color: OK, bold: true } : g.week_report === 'missing' ? { text: 'À envoyer', color: WARN, bold: true } : '—',
      ]),
      [80, 80, 42, '*', 86, 70], ['left', 'left', 'right', 'left', 'left', 'left']))
  if (withoutGarde.length > 0) {
    content.push({ text: [{ text: 'GEMs sans Garde : ', bold: true, color: WARN }, withoutGarde.map((g) => g.gem).join(', ')], fontSize: 8.5, margin: [0, 6, 0, 0] })
  }

  return {
    ...baseDoc(title),
    ...frame(title, logo),
    content,
  } as TDocumentDefinitions
}

/** Annuaire des responsables telecharge en PDF. */
export async function downloadLeadersPdf(data: LeadersData): Promise<void> {
  const [pdfMake, logo] = await Promise.all([loadPdfMake(), logoDataUrl()])
  pdfMake.createPdf(buildLeadersDoc(data, logo)).download(`responsables-${fileSafe(data.scope === 'church' ? 'eglise' : data.tribes.map((t) => t.name).join('-'))}.pdf`)
}
