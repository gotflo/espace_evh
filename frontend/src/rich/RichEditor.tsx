import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { EditorContent, Extension, textblockTypeInputRule, useEditor, useEditorState, type Editor } from '@tiptap/react'
import StarterKit from '@tiptap/starter-kit'
import { Color, TextStyle } from '@tiptap/extension-text-style'
import Highlight from '@tiptap/extension-highlight'
import { Placeholder } from '@tiptap/extensions'
import { docText, HIGHLIGHTS, normalizeDoc, TEXT_COLORS, type RichDoc } from './doc'
import '../rich.css'

export interface RichEditorProps {
  /** Document de depart (lu une seule fois : changer `resetKey` pour repartir d'un autre). */
  value: RichDoc | null
  onChange: (doc: RichDoc | null, text: string) => void
  placeholder?: string
  /** Nombre de caracteres maximal (compte comme sur le serveur). */
  max: number
  /** Nombre de caracteres conseille au minimum : le compteur l'indique tant qu'il n'est pas atteint. */
  min?: number
  /** Nom accessible du champ (etiquette visible a cote). */
  labelledBy?: string
  /** Version compacte (commentaire) : moins d'outils, hauteur reduite. */
  compact?: boolean
  autoFocus?: boolean
  /** Ctrl+Entree (ou Cmd+Entree) : envoyer. */
  onSubmit?: () => void
  disabled?: boolean
}

const P = {
  h: 'M6 4v16M18 4v16M6 12h12',
  ul: 'M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01',
  ol: 'M10 6h10M10 12h10M10 18h10M4 4.5h1.5V9M4 9h3M4 14.5c.6-.6 2.5-.8 2.5.6 0 1.1-2.5 2-2.5 3.4h3',
  quote: 'M7 7h3v5H6v-1a4 4 0 0 1 1-3M15 7h3v5h-4v-1a4 4 0 0 1 1-3M6 17h12',
  color: 'M5 20h14M8 16l4-11 4 11M9.5 12h5',
  marker: 'M15 5l4 4-9 9H6v-4zM4 21h16',
  clear: 'M4 7V5h12v2M10 5l-3 14M13 19l8-8M21 19l-8-8',
  undo: 'M9 14 4 9l5-5M4 9h10a6 6 0 0 1 0 12h-3',
  redo: 'M15 14l5-5-5-5M20 9H10a6 6 0 0 0 0 12h3',
  help: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3M12 17h.01',
}

function Svg({ d }: { d: string }) {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden focusable="false">
      <path d={d} />
    </svg>
  )
}

/** Bouton de la barre d'outils : il ne prend pas le focus (la selection reste dans le texte, aussi sur iPhone). */
function Tool({ label, active, disabled, onRun, children, keys }: { label: string; active?: boolean; disabled?: boolean; onRun: () => void; children: ReactNode; keys?: string }) {
  return (
    <button type="button" className={`rich-tool${active ? ' is-on' : ''}`} aria-label={label} aria-pressed={active ?? undefined} disabled={disabled}
      title={keys ? `${label} (${keys})` : label} onMouseDown={(e) => e.preventDefault()} onClick={onRun}>
      {children}
    </button>
  )
}

/** « # » + espace : un titre (l'editeur ne connait que les niveaux 2 et 3, « ## » et « ### » marchent aussi). */
const HashHeading = Extension.create({
  name: 'hashHeading',
  addInputRules() {
    const heading = this.editor.schema.nodes.heading
    return heading ? [textblockTypeInputRule({ find: /^#\s$/, type: heading, getAttributes: { level: 2 } })] : []
  },
})

const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform)
const mod = mac ? '⌘' : 'Ctrl'

/**
 * Editeur de texte enrichi (devoirs, commentaires du pasteur). Mise en forme comme dans un traitement de texte, et
 * raccourcis de saisie : « # » titre, « - » ou « * » liste, « 1. » liste numerotee, « > » citation, **gras**,
 * *italique*, ~~barre~~, ==surligne==. Couleurs et surlignages limites a une palette lisible. Le texte colle
 * d'ailleurs ne garde que la mise en forme autorisee.
 */
export default function RichEditor({ value, onChange, placeholder, max, min = 0, labelledBy, compact = false, autoFocus = false, onSubmit, disabled = false }: RichEditorProps) {
  const [panel, setPanel] = useState<'color' | 'highlight' | 'help' | null>(null)
  const [length, setLength] = useState(() => docText(value).length)
  const changeRef = useRef(onChange)
  changeRef.current = onChange
  const submitRef = useRef(onSubmit)
  submitRef.current = onSubmit
  const helpId = useId()

  const editor = useEditor({
    extensions: [
      StarterKit.configure({
        heading: { levels: [2, 3] },
        code: false,
        codeBlock: false,
        link: false,
        trailingNode: false,
        dropcursor: false,
      }),
      HashHeading,
      TextStyle,
      Color,
      Highlight.configure({ multicolor: true }),
      Placeholder.configure({ placeholder: placeholder ?? '' }),
    ],
    content: value ?? '',
    editable: !disabled,
    autofocus: autoFocus ? 'end' : false,
    editorProps: {
      attributes: {
        class: 'rich-input',
        role: 'textbox',
        'aria-multiline': 'true',
        ...(labelledBy ? { 'aria-labelledby': labelledBy } : {}),
        'aria-describedby': helpId,
        spellcheck: 'true',
        autocapitalize: 'sentences',
      },
      // Texte colle (Word, site web, WhatsApp) : ni styles, ni couleurs, ni polices d'origine ; la structure reste.
      transformPastedHTML: (html) => html.replace(/\s(style|class|color|data-color|face|bgcolor)="[^"]*"/gi, ''),
      handleKeyDown: (_view, event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey) && submitRef.current) {
          event.preventDefault()
          submitRef.current()
          return true
        }
        if (event.key === 'Escape') setPanel(null)
        return false
      },
    },
    onUpdate: ({ editor: e }) => {
      const doc = normalizeDoc(e.getJSON() as RichDoc)
      const text = docText(doc)
      setLength(text.length)
      changeRef.current(doc, text)
    },
  })

  useEffect(() => { editor?.setEditable(!disabled) }, [editor, disabled])

  const state = useEditorState({
    editor,
    selector: ({ editor: e }: { editor: Editor | null }) => e ? {
      bold: e.isActive('bold'), italic: e.isActive('italic'), underline: e.isActive('underline'), strike: e.isActive('strike'),
      h2: e.isActive('heading', { level: 2 }), h3: e.isActive('heading', { level: 3 }),
      ul: e.isActive('bulletList'), ol: e.isActive('orderedList'), quote: e.isActive('blockquote'),
      color: (e.getAttributes('textStyle').color as string | undefined) ?? null,
      highlight: e.isActive('highlight') ? ((e.getAttributes('highlight').color as string | undefined) ?? HIGHLIGHTS[0].value) : null,
      undo: e.can().undo(), redo: e.can().redo(),
    } : null,
  })

  if (!editor || !state) return <div className={`rich${compact ? ' is-compact' : ''}`}><div className="rich-input rich-wait" /></div>

  const run = (fn: (c: ReturnType<Editor['chain']>) => ReturnType<Editor['chain']>) => { fn(editor.chain().focus()).run() }
  const over = length > max
  const short = min > 0 && length > 0 && length < min

  return (
    <div className={`rich${compact ? ' is-compact' : ''}${disabled ? ' is-disabled' : ''}`}>
      <div className="rich-toolbar" role="toolbar" aria-label="Mise en forme">
        <Tool label="Gras" keys={`${mod}+B`} active={state.bold} onRun={() => run((c) => c.toggleBold())}><b>G</b></Tool>
        <Tool label="Italique" keys={`${mod}+I`} active={state.italic} onRun={() => run((c) => c.toggleItalic())}><i>I</i></Tool>
        <Tool label="Souligné" keys={`${mod}+U`} active={state.underline} onRun={() => run((c) => c.toggleUnderline())}><u>S</u></Tool>
        {!compact && <Tool label="Barré" active={state.strike} onRun={() => run((c) => c.toggleStrike())}><s>B</s></Tool>}
        <span className="rich-sep" aria-hidden />
        {!compact && <Tool label="Titre" active={state.h2} onRun={() => run((c) => c.toggleHeading({ level: 2 }))}><Svg d={P.h} /></Tool>}
        <Tool label="Liste à puces" active={state.ul} onRun={() => run((c) => c.toggleBulletList())}><Svg d={P.ul} /></Tool>
        <Tool label="Liste numérotée" active={state.ol} onRun={() => run((c) => c.toggleOrderedList())}><Svg d={P.ol} /></Tool>
        {!compact && <Tool label="Citation" active={state.quote} onRun={() => run((c) => c.toggleBlockquote())}><Svg d={P.quote} /></Tool>}
        <span className="rich-sep" aria-hidden />
        <Tool label="Couleur du texte" active={!!state.color || panel === 'color'} onRun={() => setPanel((p) => (p === 'color' ? null : 'color'))}>
          <span className="rich-color-icon"><Svg d={P.color} /><i style={{ background: state.color ?? 'currentColor' }} /></span>
        </Tool>
        <Tool label="Surligner" active={!!state.highlight || panel === 'highlight'} onRun={() => setPanel((p) => (p === 'highlight' ? null : 'highlight'))}>
          <span className="rich-color-icon"><Svg d={P.marker} /><i style={{ background: state.highlight ?? HIGHLIGHTS[0].value }} /></span>
        </Tool>
        <Tool label="Effacer la mise en forme" onRun={() => run((c) => c.unsetAllMarks().clearNodes())}><Svg d={P.clear} /></Tool>
        <span className="rich-sep" aria-hidden />
        <Tool label="Annuler" keys={`${mod}+Z`} disabled={!state.undo} onRun={() => run((c) => c.undo())}><Svg d={P.undo} /></Tool>
        <Tool label="Rétablir" disabled={!state.redo} onRun={() => run((c) => c.redo())}><Svg d={P.redo} /></Tool>
        <Tool label="Astuces de saisie" active={panel === 'help'} onRun={() => setPanel((p) => (p === 'help' ? null : 'help'))}><Svg d={P.help} /></Tool>
      </div>

      {panel === 'color' && (
        <div className="rich-panel" role="group" aria-label="Couleur du texte">
          {TEXT_COLORS.map((c) => (
            <button key={c.value} type="button" className={`rich-swatch${state.color === c.value ? ' is-on' : ''}`} style={{ color: c.value }}
              aria-label={c.label} aria-pressed={state.color === c.value} title={c.label}
              onMouseDown={(e) => e.preventDefault()} onClick={() => { run((ch) => ch.setColor(c.value)); setPanel(null) }}>A</button>
          ))}
          <button type="button" className="rich-swatch-reset" onMouseDown={(e) => e.preventDefault()} onClick={() => { run((ch) => ch.unsetColor()); setPanel(null) }}>Par défaut</button>
        </div>
      )}
      {panel === 'highlight' && (
        <div className="rich-panel" role="group" aria-label="Surligner">
          {HIGHLIGHTS.map((c) => (
            <button key={c.value} type="button" className={`rich-swatch is-mark${state.highlight === c.value ? ' is-on' : ''}`} style={{ background: c.value }}
              aria-label={c.label} aria-pressed={state.highlight === c.value} title={c.label}
              onMouseDown={(e) => e.preventDefault()} onClick={() => { run((ch) => ch.setHighlight({ color: c.value })); setPanel(null) }} />
          ))}
          <button type="button" className="rich-swatch-reset" onMouseDown={(e) => e.preventDefault()} onClick={() => { run((ch) => ch.unsetHighlight()); setPanel(null) }}>Aucun</button>
        </div>
      )}
      {panel === 'help' && (
        <div className="rich-panel rich-help">
          <p>Écrivez naturellement, la mise en forme suit :</p>
          <ul>
            {!compact && <li><kbd>#</kbd> + espace : un titre</li>}
            <li><kbd>-</kbd> ou <kbd>*</kbd> + espace : une liste à puces</li>
            <li><kbd>1.</kbd> + espace : une liste numérotée</li>
            {!compact && <li><kbd>&gt;</kbd> + espace : une citation</li>}
            <li><kbd>**mot**</kbd> : <b>gras</b> · <kbd>*mot*</kbd> : <i>italique</i> · <kbd>==mot==</kbd> : <mark>surligné</mark></li>
            <li>Sélectionnez un passage puis touchez une couleur pour le colorer.</li>
            {onSubmit && <li><kbd>{mod}</kbd> + <kbd>Entrée</kbd> : publier</li>}
          </ul>
        </div>
      )}

      <EditorContent editor={editor} className="rich-body" onClick={() => setPanel((p) => (p === 'help' ? p : null))} />
      <div className="rich-foot" id={helpId}>
        <span className={over ? 'is-over' : short ? 'is-short' : ''} aria-live="polite">
          {over ? `${length - max} caractère(s) de trop` : short ? `Encore ${min - length} caractère(s) au moins` : `${length} / ${max}`}
        </span>
      </div>
    </div>
  )
}
