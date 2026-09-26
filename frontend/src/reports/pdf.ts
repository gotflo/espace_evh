// Generation des rapports PDF (sur l'appareil, a partir des donnees autorisees par le serveur).
// pdfmake est charge a la demande : aucun poids ajoute au demarrage de l'application.
import type { Content, TDocumentDefinitions } from 'pdfmake/interfaces'
import { columnChartSvg, hbarChartSvg, lineChartSvg, VIZ } from '../components/charts'
import type { ReportData, ReportMemberRow } from '../types'

const TEAL = '#0d5f57'
const GOLD = '#d9a400'
const MUTED = '#586863'

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
const fileSafe = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-zA-Z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase()

function header(logo: string | null, title: string, subtitle: string): Content {
  return {
    columns: [
      logo ? { image: logo, width: 46 } : { text: '' , width: 0 },
      {
        stack: [
          { text: "VASES D'HONNEUR CHICOUTIMI", fontSize: 8, bold: true, color: GOLD, characterSpacing: 1.5 },
          { text: title, fontSize: 18, bold: true, color: TEAL, margin: [0, 2, 0, 2] },
          { text: subtitle, fontSize: 9, color: MUTED },
        ],
        margin: [logo ? 10 : 0, 2, 0, 0],
      },
    ],
    margin: [0, 0, 0, 6],
  }
}

const rule: Content = { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 515, y2: 0, lineWidth: 1.5, lineColor: GOLD }], margin: [0, 4, 0, 14] }

function section(title: string): Content {
  return { text: title, fontSize: 12, bold: true, color: TEAL, margin: [0, 14, 0, 6] }
}

function kpiGrid(items: { label: string; value: string; sub?: string }[]): Content {
  const cells = items.map((k) => ({
    stack: [
      { text: k.value, fontSize: 17, bold: true, color: '#17211f' },
      { text: k.label, fontSize: 8, color: MUTED, margin: [0, 2, 0, 0] },
      ...(k.sub ? [{ text: k.sub, fontSize: 7, color: MUTED }] : []),
    ],
    fillColor: '#f4f7f7',
    margin: [8, 7, 8, 7],
  }))
  const rows: Content[][] = []
  for (let i = 0; i < cells.length; i += 4) {
    const row = cells.slice(i, i + 4)
    while (row.length < 4) row.push({ stack: [{ text: '', fontSize: 1, color: '#ffffff' }], fillColor: '#ffffff', margin: [0, 0, 0, 0] } as never)
    rows.push(row as Content[])
  }
  return {
    table: { widths: ['*', '*', '*', '*'], body: rows as never },
    layout: { hLineWidth: () => 4, vLineWidth: () => 4, hLineColor: () => '#ffffff', vLineColor: () => '#ffffff' },
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

function table(headers: string[], rows: (string | number)[][], widths?: (string | number)[]): Content {
  return {
    table: {
      headerRows: 1,
      widths: widths ?? headers.map(() => '*'),
      body: [
        headers.map((h) => ({ text: h, bold: true, fontSize: 8, color: '#ffffff', fillColor: TEAL })),
        ...rows.map((r, i) => r.map((c) => ({ text: String(c), fontSize: 8, ...(i % 2 ? { fillColor: '#f6f8f8' } : {}) }))),
      ],
    },
    layout: { hLineWidth: () => 0, vLineWidth: () => 0, paddingTop: () => 4, paddingBottom: () => 4, paddingLeft: () => 5, paddingRight: () => 5 },
  }
}

function footer(label: string) {
  return (page: number, pages: number): Content => ({
    columns: [
      { text: `Vases d'Honneur Chicoutimi · ${label} · document confidentiel`, fontSize: 7, color: MUTED },
      { text: `Page ${page} / ${pages}`, fontSize: 7, color: MUTED, alignment: 'right' },
    ],
    margin: [40, 10, 40, 0],
  })
}

/** Document du rapport complet de la portee (tribu, mes tribus, eglise). */
export function buildReportDoc(report: ReportData, logo: string | null): TDocumentDefinitions {
  const k = report.kpis
  const labels = report.monthly.map((m) => m.label)
  const content: Content[] = [
    header(logo, `Rapport — ${report.scope.label}`, `Période : ${date(report.period.from)} au ${date(report.period.to)} · généré le ${date(report.generated_at)}`),
    rule,
    section('Chiffres clés'),
    kpiGrid([
      { label: 'Membres', value: String(k.members), sub: `${k.active} actifs · ${k.inactive} inactifs` },
      { label: 'Vie spirituelle (FISS)', value: pct(k.spiritual_score), sub: 'moyenne des fiches remplies' },
      { label: 'FISS du mois', value: pct(k.fiss_rate), sub: `${k.fiss_missing} membre(s) actif(s) sans fiche` },
      { label: 'Assiduité', value: pct(k.attendance_rate), sub: 'présences aux cultes' },
      { label: 'Vertumètre', value: k.vertumetre !== null ? `${num(k.vertumetre)} /20` : '—', sub: 'moyenne des notes' },
      { label: 'Nouveaux membres', value: String(k.new_members), sub: 'sur la période' },
      { label: 'Profils complétés', value: k.profile_completion_avg !== null ? `${k.profile_completion_avg} %` : '—', sub: `${k.incomplete_profiles} profil(s) incomplet(s)` },
      { label: 'Événements', value: String(k.events), sub: `${k.participations} participation(s)` },
    ]),
    // Titre et graphique restent ensemble (pas de titre orphelin en bas de page).
    { stack: [
      section('Vie spirituelle et fiches mensuelles'),
      legend([{ label: 'Score de vie spirituelle', color: VIZ.s1 }, { label: 'Taux de FISS remplies', color: VIZ.s2 }]),
      { svg: lineChartSvg(labels, [
        { key: 's', label: 'Score', color: VIZ.s1, values: report.monthly.map((m) => m.spiritual_score) },
        { key: 'f', label: 'FISS', color: VIZ.s2, values: report.monthly.map((m) => m.fiss_rate) },
      ], '%', 100, 520, 135), width: 515 },
    ], unbreakable: true },
    { stack: [
      section('Assiduité aux cultes'),
      { svg: lineChartSvg(labels, [{ key: 'a', label: 'Assiduité', color: VIZ.s3, values: report.monthly.map((m) => m.attendance_rate) }], '%', 100, 520, 135), width: 515 },
    ], unbreakable: true },
    { stack: [
      section('Nouveaux membres par mois'),
      { svg: columnChartSvg(labels, report.monthly.map((m) => m.new_members), VIZ.s1, 520, 115), width: 515 },
    ], unbreakable: true },
  ]

  const tribes = report.tribes.filter((t) => t.members > 0)
    .sort((a, b) => (b.spiritual_score ?? -1) - (a.spiritual_score ?? -1) || a.name.localeCompare(b.name))
  if (tribes.length > 0) {
    content.push(
      { text: '', pageBreak: 'before' },
      section('Comparaison des tribus — vie spirituelle du mois'),
      { svg: hbarChartSvg(tribes.map((t) => ({ label: t.name, value: t.spiritual_score }))), width: 515 },
      { text: 'Une tribu sans fiche remplie ce mois-ci n’a pas de score (elle n’est pas comptée comme 0).', fontSize: 7, color: MUTED, margin: [0, 4, 0, 8] },
      table(['Tribu', 'Membres', 'Actifs', 'Inactifs', 'FISS du mois', 'Score', 'Mois préc.', 'Profils'],
        tribes.map((t) => [t.name, t.members, t.active, t.inactive, pct(t.fiss_rate), pct(t.spiritual_score), pct(t.spiritual_score_previous), t.completion_avg !== null ? `${t.completion_avg} %` : '—']),
        ['*', 44, 36, 40, 50, 38, 48, 38]),
    )
  }

  content.push(
    section('Détail mensuel'),
    table(['Mois', 'Membres', 'Nouveaux', 'FISS', 'Taux FISS', 'Spirituel', 'Social', 'Vertumètre', 'Assiduité', 'Événem.'],
      report.monthly.map((m) => [m.label, m.members, m.new_members, m.fiss_filled, pct(m.fiss_rate), pct(m.spiritual_score), pct(m.social_score), m.vertumetre !== null ? num(m.vertumetre) : '—', pct(m.attendance_rate), m.events]),
      [36, 38, 42, 26, 40, 42, 42, 48, 44, 47]),
  )

  const list = (title: string, items: { name: string; tribe: string | null; extra?: string }[]) => {
    if (items.length === 0) return
    content.push(section(`${title} (${items.length})`), {
      columns: [0, 1, 2].map((c) => ({
        ul: items.filter((_, i) => i % 3 === c).map((p) => ({ text: [p.name, { text: p.tribe && report.tribes.length ? ` · ${p.tribe}` : '', color: MUTED }, { text: p.extra ? ` · ${p.extra}` : '', color: MUTED }], fontSize: 8 })),
      })),
      columnGap: 10,
    })
  }
  list('Membres actifs sans FISS ce mois-ci', report.missing_fiss.map((p) => ({ name: p.name, tribe: p.tribe })))
  list('Membres inactifs', report.inactive_members.map((p) => ({ name: p.name, tribe: p.tribe, extra: p.since ? `depuis le ${p.since}` : undefined })))
  list('Nouveaux membres', report.new_members_list.map((p) => ({ name: p.name, tribe: p.tribe, extra: p.date })))

  content.push(
    section('Définitions'),
    {
      ul: [
        'Score de vie spirituelle : moyenne (0-100) des composantes renseignées de la FISS (méditation, prière, jeûne sur 20 ; sanctification bien = 100, moyen = 50, mal = 0). Les membres sans fiche ne sont pas comptés comme 0.',
        'Taux de FISS : fiches remplies / membres inscrits à la fin du mois.',
        'Assiduité : présences aux cultes / (sessions pointées × membres actifs).',
        'Membre inactif : aucune connexion, aucune FISS et aucune présence depuis 3 mois.',
        'Vertumètre : moyenne des notes /20 données par les responsables.',
      ].map((t) => ({ text: t, fontSize: 7.5, color: MUTED })),
    },
  )

  return {
    pageSize: 'A4',
    pageMargins: [40, 40, 40, 44],
    info: { title: `Rapport ${report.scope.label}`, author: "Vases d'Honneur Chicoutimi" },
    defaultStyle: { font: 'Roboto', fontSize: 9, color: '#17211f' },
    footer: footer(report.scope.label),
    content,
  }
}

/** Rapport complet telecharge en PDF. */
export async function downloadReportPdf(report: ReportData): Promise<void> {
  const [pdfMake, logo] = await Promise.all([loadPdfMake(), logoDataUrl()])
  pdfMake.createPdf(buildReportDoc(report, logo)).download(`rapport-${fileSafe(report.scope.label)}-${report.period.to}.pdf`)
}

const STATUS: Record<string, string> = { active: 'Actif', inactive: 'Inactif' }

/** Document de la liste des membres (portee et filtre choisis), mise en page paysage. */
export function buildMembersDoc(label: string, filterLabel: string, rows: ReportMemberRow[], logo: string | null): TDocumentDefinitions {
  const seen = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('fr-CA') : 'jamais')
  return {
    pageSize: 'A4',
    pageOrientation: 'landscape',
    pageMargins: [34, 34, 34, 44],
    info: { title: `Membres ${label}`, author: "Vases d'Honneur Chicoutimi" },
    defaultStyle: { font: 'Roboto', fontSize: 8, color: '#17211f' },
    footer: footer(`Membres — ${label}`),
    content: [
      header(logo, `Liste des membres — ${label}`, `${filterLabel} · ${rows.length} membre(s) · généré le ${date(new Date().toISOString())}`),
      { canvas: [{ type: 'line', x1: 0, y1: 0, x2: 773, y2: 0, lineWidth: 1.5, lineColor: GOLD }], margin: [0, 4, 0, 12] },
      table(['Nom', 'Téléphone', 'Tribu', 'GEM', 'Statut', 'Dernière connexion', 'Profil', 'Dernière FISS', 'Score FISS', 'Présences (3 mois)', 'Vertumètre'],
        rows.map((r) => [r.name, r.phone ?? '', r.tribe ?? '', r.gem ?? '', STATUS[r.status] ?? r.status, seen(r.last_seen), `${r.completion} %`, r.last_fiss ?? 'aucune', pct(r.fiss_score), r.attendance_3m, r.vertumetre !== null ? num(r.vertumetre) : '—']),
        ['*', 78, 60, 60, 42, 66, 38, 54, 44, 58, 50]),
    ],
  }
}

/** Liste des membres telechargee en PDF. */
export async function downloadMembersPdf(label: string, filterLabel: string, rows: ReportMemberRow[]): Promise<void> {
  const [pdfMake, logo] = await Promise.all([loadPdfMake(), logoDataUrl()])
  pdfMake.createPdf(buildMembersDoc(label, filterLabel, rows, logo)).download(`membres-${fileSafe(label)}.pdf`)
}
