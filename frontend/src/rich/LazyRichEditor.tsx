import { lazy, Suspense } from 'react'
import type { RichEditorProps } from './RichEditor'
import '../rich.css'

// L'editeur (TipTap) ne se telecharge qu'au moment d'ecrire : la lecture d'un devoir reste legere.
const RichEditor = lazy(() => import('./RichEditor'))

export function LazyRichEditor(props: RichEditorProps) {
  return (
    <Suspense fallback={<div className={`rich${props.compact ? ' is-compact' : ''}`}><div className="rich-toolbar rich-wait-bar" /><div className="rich-input rich-wait" /></div>}>
      <RichEditor {...props} />
    </Suspense>
  )
}
