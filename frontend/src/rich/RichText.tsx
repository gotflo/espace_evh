import type { CSSProperties, ReactNode } from 'react'
import { docOf, isHighlight, isTextColor, linkParts, type RichDoc, type RichNode } from './doc'
import '../rich.css'

/**
 * Affichage d'un texte enrichi, element par element (jamais comme du HTML) : seuls les blocs, styles et couleurs
 * connus sont rendus, le reste est ignore. Sans document (ancienne reponse), le texte simple avec ses retours a la ligne.
 */
export function RichText({ doc, text, className }: { doc?: RichDoc | null; text?: string | null; className?: string }) {
  const shown = docOf(doc, text)
  if (!shown) return null
  return <div className={`rich-text${className ? ` ${className}` : ''}`}>{blocks(shown.content, 'b')}</div>
}

function blocks(nodes: RichNode[] | undefined, key: string): ReactNode[] {
  return (nodes ?? []).map((node, i) => {
    const k = `${key}.${i}`
    switch (node.type) {
      case 'paragraph':
        return <p key={k}>{node.content?.length ? inline(node.content, k) : <br />}</p>
      case 'heading':
        return node.attrs?.level === 3 ? <h5 key={k}>{inline(node.content, k)}</h5> : <h4 key={k}>{inline(node.content, k)}</h4>
      case 'bulletList':
        return <ul key={k}>{blocks(node.content, k)}</ul>
      case 'orderedList':
        return <ol key={k} start={node.attrs?.start && node.attrs.start > 1 ? node.attrs.start : undefined}>{blocks(node.content, k)}</ol>
      case 'listItem':
        return <li key={k}>{blocks(node.content, k)}</li>
      case 'blockquote':
        return <blockquote key={k}>{blocks(node.content, k)}</blockquote>
      case 'horizontalRule':
        return <hr key={k} />
      default:
        return null
    }
  })
}

function inline(nodes: RichNode[] | undefined, key: string): ReactNode[] {
  return (nodes ?? []).map((node, i) => {
    const k = `${key}.${i}`
    if (node.type === 'hardBreak') return <br key={k} />
    if (node.type !== 'text' || !node.text) return null
    let out: ReactNode = linkParts(node.text).map((part, j) => (part.href
      ? <a key={j} href={part.href} target="_blank" rel="noopener noreferrer nofollow">{part.text}</a>
      : part.text))
    const style: CSSProperties = {}
    for (const mark of node.marks ?? []) {
      if (mark.type === 'bold') out = <strong>{out}</strong>
      else if (mark.type === 'italic') out = <em>{out}</em>
      else if (mark.type === 'underline') out = <u>{out}</u>
      else if (mark.type === 'strike') out = <s>{out}</s>
      else if (mark.type === 'textStyle' && isTextColor(mark.attrs?.color)) style.color = mark.attrs!.color
      else if (mark.type === 'highlight' && isHighlight(mark.attrs?.color)) style.backgroundColor = mark.attrs!.color
    }
    return style.color || style.backgroundColor
      ? <span key={k} className={style.backgroundColor ? 'rich-mark' : undefined} style={style}>{out}</span>
      : <span key={k}>{out}</span>
  })
}
