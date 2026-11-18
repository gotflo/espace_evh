// Texte enrichi des devoirs : document de l'editeur (format TipTap / ProseMirror, en JSON).
// Le serveur le reconstruit a partir d'une liste fermee (App\Support\RichText) : memes blocs, styles et couleurs ici.

export interface RichMark { type: string; attrs?: { color?: string } }
export interface RichNode {
  type: string
  attrs?: { level?: number; start?: number }
  content?: RichNode[]
  text?: string
  marks?: RichMark[]
}
export interface RichDoc { type: 'doc'; content?: RichNode[] }

/** Couleurs du texte : memes valeurs que RichText::COLORS (serveur). */
export const TEXT_COLORS: { value: string; label: string }[] = [
  { value: '#c0392b', label: 'Rouge' },
  { value: '#d35400', label: 'Orange' },
  { value: '#b7950b', label: 'Or' },
  { value: '#1e8449', label: 'Vert' },
  { value: '#0d5f57', label: 'Sarcelle' },
  { value: '#1f618d', label: 'Bleu' },
  { value: '#7d3c98', label: 'Violet' },
  { value: '#5d6d7e', label: 'Gris' },
]

/** Surlignages : memes valeurs que RichText::HIGHLIGHTS (serveur). */
export const HIGHLIGHTS: { value: string; label: string }[] = [
  { value: '#fff3a3', label: 'Jaune' },
  { value: '#ffd8a8', label: 'Pêche' },
  { value: '#c3f0ca', label: 'Vert' },
  { value: '#c5e4ff', label: 'Bleu' },
  { value: '#f3d1ff', label: 'Lilas' },
  { value: '#ffd1dc', label: 'Rose' },
]

const COLOR_SET = new Set(TEXT_COLORS.map((c) => c.value))
const HIGHLIGHT_SET = new Set(HIGHLIGHTS.map((c) => c.value))
export const isTextColor = (c?: string) => !!c && COLOR_SET.has(c.toLowerCase())
export const isHighlight = (c?: string) => !!c && HIGHLIGHT_SET.has(c.toLowerCase())

/** Texte brut d'un document, calcule comme sur le serveur (longueurs minimales et maximales identiques). */
export function docText(doc: RichDoc | null | undefined): string {
  if (!doc) return ''
  const lines: string[] = []
  const inline = (nodes?: RichNode[]) => (nodes ?? []).map((n) => (n.type === 'hardBreak' ? '\n' : n.text ?? '')).join('')
  const walk = (nodes: RichNode[] | undefined, prefix: string) => {
    for (const node of nodes ?? []) {
      if (node.type === 'paragraph' || node.type === 'heading') lines.push(prefix + inline(node.content))
      else if (node.type === 'blockquote') walk(node.content, prefix + '> ')
      else if (node.type === 'bulletList' || node.type === 'orderedList') {
        let n = node.attrs?.start ?? 1
        for (const item of node.content ?? []) {
          const bullet = node.type === 'bulletList' ? '- ' : `${n++}. `
          const inner: string[] = []
          const before = lines.length
          walk(item.content, '')
          inner.push(...lines.splice(before))
          inner.forEach((line, i) => lines.push(prefix + (i === 0 ? bullet : ' '.repeat(bullet.length)) + line))
        }
      } else if (node.type === 'horizontalRule') lines.push(prefix + '---')
    }
  }
  walk(doc.content, '')
  return lines.join('\n').trim()
}

/** Ancien texte simple (ou brouillon d'une version precedente) en document : un paragraphe par ligne. */
export function textToDoc(text: string | null | undefined): RichDoc | null {
  const t = (text ?? '').replace(/\r\n?/g, '\n').trim()
  if (!t) return null
  return { type: 'doc', content: t.split('\n').map((line) => (line ? { type: 'paragraph', content: [{ type: 'text', text: line }] } : { type: 'paragraph' })) }
}

/** Document a afficher ou a modifier : le document enregistre, sinon l'ancien texte. */
export function docOf(doc: RichDoc | null | undefined, text?: string | null): RichDoc | null {
  return doc && Array.isArray(doc.content) && doc.content.length ? doc : textToDoc(text)
}

/**
 * Document pret a l'envoi : couleurs hors palette retirees (texte colle d'ailleurs), surlignage sans couleur
 * (raccourci ==texte==) mis en jaune, paragraphes vides de la fin retires. Rend null pour un texte vide.
 */
export function normalizeDoc(doc: RichDoc | null | undefined): RichDoc | null {
  if (!doc || !docText(doc)) return null
  const fix = (nodes?: RichNode[]): RichNode[] | undefined => nodes?.map((node) => {
    const out: RichNode = { ...node }
    if (node.marks) {
      out.marks = node.marks.flatMap((m) => {
        if (m.type === 'textStyle') return isTextColor(m.attrs?.color) ? [{ type: 'textStyle', attrs: { color: m.attrs!.color!.toLowerCase() } }] : []
        if (m.type === 'highlight') return [{ type: 'highlight', attrs: { color: isHighlight(m.attrs?.color) ? m.attrs!.color!.toLowerCase() : HIGHLIGHTS[0].value } }]
        return [{ type: m.type }]
      })
      if (!out.marks.length) delete out.marks
    }
    if (node.content) out.content = fix(node.content)
    return out
  })
  const content = fix(doc.content) ?? []
  while (content.length && content[content.length - 1].type === 'paragraph' && !content[content.length - 1].content?.length) content.pop()
  return { type: 'doc', content }
}

/** Deux documents identiques (pour savoir si quelque chose a change). */
export function sameDoc(a: RichDoc | null, b: RichDoc | null): boolean {
  return JSON.stringify(a ?? null) === JSON.stringify(b ?? null)
}

/** Texte decoupe en morceaux : texte simple et liens http(s) (jamais d'autre protocole). */
export function linkParts(text: string): { text: string; href?: string }[] {
  const out: { text: string; href?: string }[] = []
  const re = /https?:\/\/[^\s<>"']+/gi
  let last = 0
  for (let m = re.exec(text); m; m = re.exec(text)) {
    const url = m[0].replace(/[.,;:!?)\]]+$/, '')
    if (m.index > last) out.push({ text: text.slice(last, m.index) })
    out.push({ text: url, href: url })
    last = m.index + url.length
    re.lastIndex = last
  }
  if (last < text.length) out.push({ text: text.slice(last) })
  return out
}
