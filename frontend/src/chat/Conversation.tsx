import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState, type KeyboardEvent, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { api, ApiError } from '../api/client'
import { toast } from '../toast'
import { getUnread, setUnread } from '../notifications'
import { Icon } from '../components/Icon'
import { ModalLayer } from '../components/ModalLayer'
import { LoadError } from '../components/LoadError'
import { clearDraft, readDraft, useDraft } from '../utils/drafts'
import { ImageError, shrinkFile } from '../utils/imagePipeline'
import {
  chatPhoto, clock, dayKey, dayLabel, EMOJIS, initial, linkParts, newClientId, onChatPush, onlyEmoji, setChatUnread, setOpenConversation,
  listTime, useChatPhoto, type ChatContact, type ChatListItem, type ChatMessage, type ChatPage, type ChatPerson, type ChatSearchResult, type ChatState, type ChatSync,
} from './chat'

const PATHS = {
  send: 'M22 2 11 13M22 2l-7 20-4-9-9-4z',
  attach: 'M21.4 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48',
  smile: 'M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20zM8 14s1.5 2 4 2 4-2 4-2M9 9h.01M15 9h.01',
  reply: 'M9 14 4 9l5-5M4 9h10.5a5.5 5.5 0 0 1 0 11H11',
  copy: 'M20 9h-9a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h9a2 2 0 0 0 2-2v-9a2 2 0 0 0-2-2zM5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1',
  flag: 'M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7',
  down: 'M6 9l6 6 6-6',
  forward: 'M15 14l5-5-5-5M20 9H9.5a5.5 5.5 0 0 0 0 11H13',
  more: 'M12 13a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM12 6a1 1 0 1 0 0-2 1 1 0 0 0 0 2zM12 20a1 1 0 1 0 0-2 1 1 0 0 0 0 2z',
}

/** Calque plein ecran du telephone : meme element que les fenetres (hors de la page, au-dessus de la barre du bas). */
function appLayer(): HTMLElement {
  let el = document.getElementById('app-layer')
  if (!el) {
    el = document.createElement('div')
    el.id = 'app-layer'
    el.className = 'app app-layer'
    document.body.appendChild(el)
  }
  return el
}

function useIsPhone(): boolean {
  const query = '(max-width: 860px)'
  const [phone, setPhone] = useState(() => window.matchMedia(query).matches)
  useEffect(() => {
    const mq = window.matchMedia(query)
    const on = () => setPhone(mq.matches)
    mq.addEventListener('change', on)
    return () => mq.removeEventListener('change', on)
  }, [])
  return phone
}

const touchDevice = () => window.matchMedia('(pointer: coarse)').matches

function Avatar({ person, size = 34 }: { person?: ChatPerson | { name: string; photo_url: string | null }; size?: number }) {
  const style = { width: size, height: size, fontSize: size * 0.42 }
  return person?.photo_url
    ? <img className="mini-avatar chat-avatar" style={style} src={person.photo_url} alt="" width={size} height={size} loading="lazy" decoding="async" />
    : <span className="mini-avatar chat-avatar" style={style} aria-hidden>{initial(person?.name ?? '?')}</span>
}

function Ticks({ m, read }: { m: ChatMessage; read: boolean }) {
  if (m.local?.state === 'sending') return <span className="chat-tick" title="Envoi en cours" aria-label="Envoi en cours"><Icon name="clock" size={13} /></span>
  if (m.local?.state === 'failed') return null
  return (
    <span className={`chat-tick${read ? ' is-read' : ''}`} title={read ? 'Lu' : 'Envoyé'} aria-label={read ? 'Lu' : 'Envoyé'}>
      <svg width="18" height="12" viewBox="0 0 18 12" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
        <path d="M1 6.5 4.2 10 10 2.5" />{read && <path d="M7.5 9.3 8.2 10 14 2.5" />}
      </svg>
    </span>
  )
}

function Photo({ m, onOpen }: { m: ChatMessage; onOpen: (url: string) => void }) {
  const remote = useChatPhoto(m.local ? null : m.id)
  const url = m.local?.preview ?? remote.url
  const ratio = m.photo && m.photo.width > 0 ? m.photo.width / m.photo.height : 1
  return (
    <button type="button" className="chat-photo" style={{ aspectRatio: String(Math.min(2.2, Math.max(0.6, ratio))) }}
      onClick={(e) => { e.stopPropagation(); if (url) onOpen(url) }} aria-label="Ouvrir la photo">
      {url ? <img src={url} alt="Photo" draggable={false} /> : <span className="chat-photo-wait">{remote.failed ? 'Photo indisponible' : <span className="spinner" />}</span>}
    </button>
  )
}

function Body({ text }: { text: string }) {
  if (onlyEmoji(text)) return <p className="chat-text chat-emoji-only">{text}</p>
  return (
    <p className="chat-text">
      {linkParts(text).map((p, i) => p.href
        ? <a key={i} href={p.href} target="_blank" rel="noopener noreferrer nofollow" onClick={(e) => e.stopPropagation()}>{p.text}</a>
        : <span key={i}>{p.text}</span>)}
    </p>
  )
}

/** Extrait d'un resultat de recherche, le mot cherche en gras (quand il s'y trouve tel quel). */
function Found({ text, query }: { text: string; query: string }) {
  // Comparaison lettre a lettre sans accents ni majuscules (« priere » surligne « PRIÈRE »), positions conservees.
  const fold = (v: string) => Array.from(v, (c) => (c.normalize('NFD')[0] ?? c).toLowerCase()).join('')
  const q = query.trim()
  const at = q && fold(text).length === text.length ? fold(text).indexOf(fold(q)) : -1
  if (at < 0) return <>{text}</>
  return <>{text.slice(0, at)}<mark>{text.slice(at, at + q.length)}</mark>{text.slice(at + q.length)}</>
}

export function Conversation({ id, onBack, onChanged, onOpenDirect, targets, contacts, onOpen }: {
  id: number
  onBack: () => void
  /** La liste des discussions doit etre relue (message envoye, discussion lue). */
  onChanged: () => void
  onOpenDirect: (key: string) => void
  /** Ou transferer un message : ses discussions, et les membres de son GEM a qui il n'a pas encore ecrit. */
  targets: ChatListItem[]
  contacts: ChatContact[]
  onOpen: (conversationId: number) => void
}) {
  const phone = useIsPhone()
  const [page, setPage] = useState<ChatPage | null>(null)
  const [state, setState] = useState<ChatState | null>(null)
  const [items, setItems] = useState<ChatMessage[]>([])
  const [people, setPeople] = useState<Record<string, ChatPerson>>({})
  const [hasMore, setHasMore] = useState(false)
  // Apres un saut vers un message ancien (recherche) : il reste des messages plus recents a charger en descendant.
  const [hasNewer, setHasNewer] = useState(false)
  const [loadingNewer, setLoadingNewer] = useState(false)
  const [searchOpen, setSearchOpen] = useState(false)
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<{ list: ChatSearchResult[]; partial: boolean; for: string } | null>(null)
  const [searching, setSearching] = useState(false)
  const [found, setFound] = useState<number | null>(null)
  const [forwardFor, setForwardFor] = useState<ChatMessage | null>(null)
  const [loadingOlder, setLoadingOlder] = useState(false)
  const [error, setError] = useState('')
  const [gone, setGone] = useState(false)
  const [text, setText] = useState('')
  const [attached, setAttached] = useState<{ file: File; preview: string } | null>(null)
  const [replyTo, setReplyTo] = useState<ChatMessage | null>(null)
  const [editing, setEditing] = useState<ChatMessage | null>(null)
  const [menuFor, setMenuFor] = useState<ChatMessage | null>(null)
  const [reportFor, setReportFor] = useState<ChatMessage | null>(null)
  const [reason, setReason] = useState('')
  const [viewer, setViewer] = useState<string | null>(null)
  const [showEmoji, setShowEmoji] = useState(false)
  const [showInfo, setShowInfo] = useState(false)
  const [atBottom, setAtBottom] = useState(true)
  const [pendingBelow, setPendingBelow] = useState(0)
  const [busy, setBusy] = useState(false)

  const scroller = useRef<HTMLDivElement>(null)
  const layerRef = useRef<HTMLDivElement>(null)
  const input = useRef<HTMLTextAreaElement>(null)
  const fileInput = useRef<HTMLInputElement>(null)
  const rev = useRef(0)
  const stick = useRef(true)
  const restore = useRef<number | null>(null)
  const lastRead = useRef(0)
  const firstUnread = useRef<number | null>(null)
  const jumped = useRef(false)
  const lastTyping = useRef(0)
  const detached = useRef(false)
  const target = useRef<number | null>(null)
  const known = useRef(new Set<number>())
  const searchInput = useRef<HTMLInputElement>(null)
  const headRef = useRef<HTMLElement>(null)
  const idRef = useRef(id)
  idRef.current = id

  useDraft(editing ? null : `chat:${id}`, text, (v) => !v.trim())

  // ---------------------------------------------------------------- Fusion de ce que le serveur renvoie

  const merge = useCallback((incoming: ChatMessage[]) => {
    if (!incoming.length) return
    setItems((current) => {
      const byId = new Map<number, ChatMessage>()
      const locals: ChatMessage[] = []
      for (const m of current) {
        if (m.local) locals.push(m)
        else byId.set(m.id, m)
      }
      let kept = locals
      for (const m of incoming) {
        byId.set(m.id, m)
        if (m.client_id) kept = kept.filter((l) => l.client_id !== m.client_id)
      }
      return [...[...byId.values()].sort((a, b) => a.id - b.id), ...kept]
    })
  }, [])

  const applyState = useCallback((s: ChatState) => {
    rev.current = Math.max(rev.current, s.rev)
    lastRead.current = Math.max(lastRead.current, s.last_read_id)
    setState(s)
  }, [])

  // ---------------------------------------------------------------- Chargement

  const load = useCallback(() => {
    const wanted = id
    setError('')
    api<ChatPage>(`/me/chat/${wanted}`).then((p) => {
      if (idRef.current !== wanted) return
      rev.current = p.rev
      lastRead.current = p.last_read_id
      firstUnread.current = p.first_unread_id
      jumped.current = false
      stick.current = true
      detached.current = false
      setPage(p); setState(p); setItems(p.messages); setPeople(p.people); setHasMore(!!p.has_more); setHasNewer(false)
    }).catch((err) => {
      if (idRef.current !== wanted) return
      if (err instanceof ApiError && err.status === 404) setGone(true)
      else setError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    })
  }, [id])

  useEffect(() => {
    setPage(null); setState(null); setItems([]); setPeople({}); setGone(false); setReplyTo(null); setEditing(null); setAttached(null)
    setMenuFor(null); setShowEmoji(false); setShowInfo(false); setPendingBelow(0); setAtBottom(true)
    setSearchOpen(false); setQuery(''); setResults(null); setFound(null); setForwardFor(null); setHasNewer(false)
    detached.current = false; target.current = null
    setText(readDraft<string>(`chat:${id}`) ?? '')
    load()
    setOpenConversation(id)
    return () => { setOpenConversation(null) }
  }, [id, load])

  // Photo jointe : son apercu local est libere quand elle part ou qu'on la retire.
  useEffect(() => () => { if (attached) URL.revokeObjectURL(attached.preview) }, [attached])

  // ---------------------------------------------------------------- Mise a jour en direct

  const counter = useRef(0)
  const syncing = useRef(false)
  const sync = useCallback(async (): Promise<boolean> => {
    if (syncing.current || document.visibilityState !== 'visible') return false
    const wanted = idRef.current
    syncing.current = true
    try {
      const r = await api<ChatSync>(`/me/chat/${wanted}/sync?rev=${rev.current}&n=${++counter.current}`)
      if (idRef.current !== wanted) return false
      if (r.reload) { load(); return true }
      setChatUnread(r.unread_total)
      const before = rev.current
      applyState(r)
      if (r.messages.length) {
        setPeople((p) => ({ ...p, ...r.people }))
        const fresh = r.messages.filter((m) => !m.mine && m.rev > before && !known.current.has(m.id)).length
        if ((!stick.current || detached.current) && fresh) setPendingBelow((n) => n + fresh)
        // Arret sur un message ancien : seuls les messages deja a l'ecran sont mis a jour (les nouveaux arriveront en redescendant).
        merge(detached.current ? r.messages.filter((m) => known.current.has(m.id)) : r.messages)
        onChanged()
      }
      return r.messages.length > 0
    } catch (err) {
      if (err instanceof ApiError && err.status === 404 && idRef.current === wanted) setGone(true)
      return false
    } finally {
      syncing.current = false
    }
  }, [applyState, load, merge, onChanged])

  useEffect(() => {
    if (!page || gone) return
    let timer: number | undefined
    let quiet = 0
    let stopped = false
    // Vif tant que la discussion bouge, puis plus calme : 3 s, 5 s apres une minute sans rien, 10 s apres cinq minutes.
    const delay = () => (quiet < 20 ? 3000 : quiet < 70 ? 5000 : 10000)
    const tick = async () => {
      const changed = await sync()
      quiet = changed ? 0 : quiet + 1
      if (!stopped) timer = window.setTimeout(tick, delay())
    }
    timer = window.setTimeout(tick, 3000)
    const now = () => { quiet = 0; window.clearTimeout(timer); void tick() }
    const onVisible = () => { if (document.visibilityState === 'visible') now() }
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener('online', now)
    const off = onChatPush(now)
    return () => { stopped = true; window.clearTimeout(timer); document.removeEventListener('visibilitychange', onVisible); window.removeEventListener('online', now); off() }
  }, [page, gone, sync])

  // ---------------------------------------------------------------- Defilement

  const lastServerId = useMemo(() => items.reduce((max, m) => (m.local ? max : Math.max(max, m.id)), 0), [items])
  useEffect(() => { known.current = new Set(items.filter((m) => !m.local).map((m) => m.id)) }, [items])

  useLayoutEffect(() => {
    const el = scroller.current
    if (!el) return
    const wanted = target.current ? el.querySelector<HTMLElement>(`[data-mid="${target.current}"]`) : null
    if (wanted) {
      // Message retrouve par la recherche : amene au milieu de l'ecran et signale un instant.
      const id = target.current
      target.current = null
      jumped.current = true
      stick.current = false
      el.scrollTop = Math.max(0, wanted.offsetTop - el.clientHeight / 2 + wanted.offsetHeight / 2)
      setFound(id)
      window.setTimeout(() => setFound((f) => (f === id ? null : f)), 2600)
    } else if (restore.current !== null) {
      el.scrollTop = el.scrollHeight - restore.current
      restore.current = null
    } else if (!jumped.current && page) {
      jumped.current = true
      const mark = firstUnread.current ? el.querySelector<HTMLElement>('.chat-unread-mark') : null
      if (mark) { el.scrollTop = Math.max(0, mark.offsetTop - 12); stick.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80 } else el.scrollTop = el.scrollHeight
    } else if (stick.current) {
      el.scrollTop = el.scrollHeight
    }
  }, [items, page, state?.typing.length, attached, replyTo, editing, showEmoji])

  const onScroll = () => {
    const el = scroller.current
    if (!el) return
    const low = el.scrollHeight - el.scrollTop - el.clientHeight < 80
    // Tant qu'il reste des messages plus recents a charger, le bas de l'ecran n'est pas la fin de la discussion.
    const bottom = low && !hasNewer
    stick.current = bottom
    setAtBottom(bottom)
    if (bottom) setPendingBelow(0)
    if (low && hasNewer && !loadingNewer && lastServerId) {
      setLoadingNewer(true)
      const wanted = id
      api<ChatPage>(`/me/chat/${wanted}?after=${lastServerId}`).then((p) => {
        if (idRef.current !== wanted) return
        setPeople((old) => ({ ...old, ...p.people }))
        rev.current = Math.max(rev.current, p.rev)
        merge(p.messages)
        if (!p.has_newer) { detached.current = false; setHasNewer(false) }
      }).catch(() => {}).finally(() => setLoadingNewer(false))
    }
    if (el.scrollTop < 120 && hasMore && !loadingOlder && items.length) {
      const first = items.find((m) => !m.local)
      if (!first) return
      setLoadingOlder(true)
      const wanted = id
      api<ChatPage>(`/me/chat/${wanted}?before=${first.id}`).then((p) => {
        if (idRef.current !== wanted) return
        restore.current = el.scrollHeight - el.scrollTop
        setPeople((old) => ({ ...p.people, ...old }))
        setHasMore(!!p.has_more)
        merge(p.messages)
      }).catch(() => {}).finally(() => setLoadingOlder(false))
    }
  }

  const toBottom = () => {
    const el = scroller.current
    if (!el) return
    // Arret sur un message ancien : retour aux derniers messages (la discussion est relue).
    if (hasNewer) { setPendingBelow(0); load(); return }
    stick.current = true
    el.scrollTo({ top: el.scrollHeight, behavior: 'smooth' })
    setPendingBelow(0)
  }

  // Telephone : le calque suit la zone visible (clavier ouvert, barre de Safari), la saisie reste toujours a l'ecran.
  useEffect(() => {
    const vv = window.visualViewport
    const el = layerRef.current
    if (!phone || !vv || !el) return
    const apply = () => {
      el.style.height = `${vv.height}px`
      el.style.top = `${vv.offsetTop}px`
      if (stick.current && scroller.current) scroller.current.scrollTop = scroller.current.scrollHeight
    }
    apply()
    vv.addEventListener('resize', apply)
    vv.addEventListener('scroll', apply)
    return () => { vv.removeEventListener('resize', apply); vv.removeEventListener('scroll', apply) }
  }, [phone, page])

  // ---------------------------------------------------------------- Lu

  useEffect(() => {
    if (!page || gone || !atBottom || hasNewer || !lastServerId || lastServerId <= lastRead.current) return
    const mark = () => {
      if (document.visibilityState !== 'visible' || lastServerId <= lastRead.current) return
      const upTo = lastServerId
      lastRead.current = upTo
      api<{ last_read_id: number; unread_total: number; notifications_read?: number }>(`/me/chat/${id}/read`, { method: 'POST', body: { message_id: upTo }, toast: false })
        .then((r) => {
          setChatUnread(r.unread_total)
          // La notification de cette discussion vient d'etre marquee lue : la cloche de l'application suit aussitot.
          if (r.notifications_read) setUnread(Math.max(0, getUnread() - r.notifications_read))
          onChanged()
        })
        .catch(() => { lastRead.current = Math.min(lastRead.current, upTo - 1) })
    }
    const t = window.setTimeout(mark, 350)
    document.addEventListener('visibilitychange', mark)
    return () => { window.clearTimeout(t); document.removeEventListener('visibilitychange', mark) }
  }, [page, gone, atBottom, hasNewer, lastServerId, id, onChanged])

  // ---------------------------------------------------------------- Ecrire

  const resize = () => {
    const el = input.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 132)}px`
    el.style.overflowY = el.scrollHeight > 132 ? 'auto' : 'hidden'
  }
  useLayoutEffect(resize, [text])

  const onType = (value: string) => {
    setText(value)
    if (value.trim() && !editing && Date.now() - lastTyping.current > 4000) {
      lastTyping.current = Date.now()
      api(`/me/chat/${id}/typing`, { method: 'POST', toast: false }).catch(() => {})
    }
  }

  const deliver = useCallback(async (m: ChatMessage) => {
    const wanted = idRef.current
    try {
      let body: unknown = { client_id: m.client_id, body: m.body ?? '', reply_to: m.local?.replyTo ?? null }
      if (m.local?.file) {
        const form = new FormData()
        form.append('client_id', m.client_id ?? '')
        if (m.body) form.append('body', m.body)
        if (m.local.replyTo) form.append('reply_to', String(m.local.replyTo))
        form.append('photo', m.local.file)
        body = form
      }
      const r = await api<{ message: ChatMessage; rev: number }>(`/me/chat/${wanted}/messages`, { method: 'POST', body, toast: false })
      if (m.local?.preview) {
        // La photo envoyee est relue une fois (version du serveur), puis l'apercu local est libere.
        const preview = m.local.preview
        await chatPhoto(r.message.id).catch(() => {})
        window.setTimeout(() => URL.revokeObjectURL(preview), 2000)
      }
      if (idRef.current !== wanted) return
      // Envoi pendant un arret sur un message ancien : retour aux derniers messages, le sien compris.
      if (detached.current) load()
      else merge([r.message])
      onChanged()
    } catch (err) {
      const message = err instanceof ApiError ? err.firstMessage : 'Envoi impossible.'
      if (idRef.current !== wanted) return
      setItems((list) => list.map((x) => (x.local && x.client_id === m.client_id ? { ...x, local: { ...x.local, state: 'failed', error: message } } : x)))
      if (err instanceof ApiError && err.status === 403) void sync()
    }
  }, [load, merge, onChanged, sync])

  const submit = async () => {
    const body = text.trim()
    if (busy || !page || !state) return
    if (editing) {
      if (!body && editing.kind === 'text') return
      setBusy(true)
      try {
        const r = await api<{ message: ChatMessage }>(`/me/chat/messages/${editing.id}`, { method: 'PUT', body: { body }, toast: false })
        merge([r.message])
        setEditing(null); setText(readDraft<string>(`chat:${id}`) ?? '')
      } catch (err) {
        toast.error(err instanceof ApiError ? err.firstMessage : 'Modification impossible.')
      } finally { setBusy(false) }
      return
    }
    if (!body && !attached) return
    if (body.length > page.max_length) { toast.error(`Ce message est trop long : ${page.max_length} caractères au plus.`); return }
    const local: ChatMessage = {
      id: -Date.now(), client_id: newClientId(), sender: state.me, mine: true, kind: attached ? 'photo' : 'text', body: body || null,
      photo: attached ? { width: 4, height: 3 } : null,
      reply: replyTo ? { id: replyTo.id, sender: replyTo.sender, kind: replyTo.kind, deleted: replyTo.deleted, text: replyTo.body?.slice(0, 120) || (replyTo.kind === 'photo' ? 'Photo' : '') } : null,
      reactions: [], edited: false, deleted: false, at: new Date().toISOString(), rev: 0,
      local: { state: 'sending', file: attached?.file, preview: attached ? URL.createObjectURL(attached.file) : undefined, replyTo: replyTo?.id ?? null },
    }
    stick.current = true
    setItems((list) => [...list, local])
    setText(''); clearDraft(`chat:${id}`); setReplyTo(null); setAttached(null); setShowEmoji(false)
    input.current?.focus()
    void deliver(local)
  }

  const retry = (m: ChatMessage) => {
    setItems((list) => list.map((x) => (x === m ? { ...x, local: { ...x.local!, state: 'sending', error: undefined } } : x)))
    void deliver(m)
  }
  const discard = (m: ChatMessage) => {
    if (m.local?.preview) URL.revokeObjectURL(m.local.preview)
    setItems((list) => list.filter((x) => x !== m))
  }

  const onKey = (e: KeyboardEvent<HTMLTextAreaElement>) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing && !touchDevice()) { e.preventDefault(); void submit() }
    if (e.key === 'Escape') { if (editing) { setEditing(null); setText('') } else if (replyTo) setReplyTo(null) }
  }

  const pick = async (file: File | undefined) => {
    if (fileInput.current) fileInput.current.value = ''
    if (!file) return
    if (!file.type.startsWith('image/')) { toast.error('Choisissez une photo.'); return }
    try {
      const small = await shrinkFile(file, 1600, 500 * 1024)
      setAttached({ file: small, preview: URL.createObjectURL(small) })
      input.current?.focus()
    } catch (err) {
      toast.error(err instanceof ImageError ? err.message : 'Cette photo n’a pas pu être préparée.')
    }
  }

  // ---------------------------------------------------------------- Actions sur un message

  const react = async (m: ChatMessage, emoji: string | null) => {
    setMenuFor(null)
    try {
      const r = await api<{ message: ChatMessage }>(`/me/chat/messages/${m.id}/reaction`, { method: 'PUT', body: { emoji }, toast: false })
      merge([r.message])
    } catch (err) { toast.error(err instanceof ApiError ? err.firstMessage : 'Réaction impossible.') }
  }
  const remove = async (m: ChatMessage) => {
    setMenuFor(null)
    if (!confirm('Supprimer ce message pour tout le monde ?')) return
    try {
      const r = await api<{ message: ChatMessage }>(`/me/chat/messages/${m.id}`, { method: 'DELETE', toast: false })
      merge([r.message]); onChanged()
    } catch (err) { toast.error(err instanceof ApiError ? err.firstMessage : 'Suppression impossible.') }
  }
  const copy = async (m: ChatMessage) => {
    setMenuFor(null)
    try { await navigator.clipboard.writeText(m.body ?? ''); toast.success('Message copié.') } catch { toast.error('Copie impossible sur cet appareil.') }
  }
  const startEdit = (m: ChatMessage) => { setMenuFor(null); setReplyTo(null); setAttached(null); setEditing(m); setText(m.body ?? ''); window.setTimeout(() => input.current?.focus(), 50) }
  const startReply = (m: ChatMessage) => { setMenuFor(null); setEditing(null); setReplyTo(m); window.setTimeout(() => input.current?.focus(), 50) }
  const sendReport = async () => {
    if (!reportFor || reason.trim().length < 3) return
    setBusy(true)
    try {
      await api(`/me/chat/messages/${reportFor.id}/report`, { method: 'POST', body: { reason: reason.trim() } })
      setReportFor(null); setReason('')
    } catch { /* message affiche automatiquement */ } finally { setBusy(false) }
  }
  // ---------------------------------------------------------------- Recherche

  // La recherche part 350 ms apres la derniere frappe (2 caracteres au moins) ; seule la derniere reponse compte.
  useEffect(() => {
    const q = query.trim()
    if (!searchOpen || q.length < 2) { setResults(null); setSearching(false); return }
    setSearching(true)
    const wanted = id
    const t = window.setTimeout(() => {
      api<{ results: ChatSearchResult[]; partial: boolean }>(`/me/chat/${wanted}/search?q=${encodeURIComponent(q)}`)
        .then((r) => { if (idRef.current === wanted) setResults({ list: r.results, partial: r.partial, for: q }) })
        .catch((err) => { if (idRef.current === wanted) { setResults({ list: [], partial: false, for: q }); toast.error(err instanceof ApiError ? err.firstMessage : 'Recherche impossible.') } })
        .finally(() => setSearching(false))
    }, 350)
    return () => window.clearTimeout(t)
  }, [query, searchOpen, id])

  const openSearch = () => {
    // Les resultats s'affichent juste sous l'en-tete, quelle que soit sa hauteur (encoche du telephone comprise).
    layerRef.current?.style.setProperty('--chat-head-height', `${headRef.current?.offsetHeight ?? 58}px`)
    setSearchOpen(true); window.setTimeout(() => searchInput.current?.focus(), 50)
  }
  const closeSearch = () => { setSearchOpen(false); setQuery(''); setResults(null) }

  /** Va au message : deja a l'ecran, on y defile ; sinon la discussion est relue autour de lui. */
  const goTo = (messageId: number) => {
    closeSearch()
    target.current = messageId
    if (known.current.has(messageId)) { setItems((list) => [...list]); return }
    const wanted = id
    api<ChatPage>(`/me/chat/${wanted}?around=${messageId}`).then((p) => {
      if (idRef.current !== wanted) return
      detached.current = !!p.has_newer
      firstUnread.current = null
      rev.current = Math.max(rev.current, p.rev)
      setPeople((old) => ({ ...old, ...p.people }))
      setHasMore(!!p.has_more); setHasNewer(!!p.has_newer); setPendingBelow(0)
      if (p.has_newer) setAtBottom(false)
      setItems(p.messages)
    }).catch((err) => { target.current = null; toast.error(err instanceof ApiError ? err.firstMessage : 'Message introuvable.') })
  }

  // ---------------------------------------------------------------- Transfert

  const forward = async (m: ChatMessage, to: { conversation_id?: number; key?: string; title: string }) => {
    if (busy) return
    setBusy(true)
    try {
      const r = await api<{ message: ChatMessage; conversation_id: number }>(`/me/chat/messages/${m.id}/forward`, {
        method: 'POST', body: { client_id: newClientId(), conversation_id: to.conversation_id ?? null, key: to.key ?? null }, toast: false,
      })
      setForwardFor(null)
      onChanged()
      if (r.conversation_id === id) { if (detached.current) load(); else { stick.current = true; merge([r.message]) } }
      toast.success(`Message transféré à ${to.title}.`)
      if (r.conversation_id !== id) onOpen(r.conversation_id)
    } catch (err) {
      toast.error(err instanceof ApiError ? err.firstMessage : 'Transfert impossible.')
    } finally { setBusy(false) }
  }

  // Appui long (telephone) ou clic droit : menu du message.
  const press = useRef<number | undefined>(undefined)
  const holders = (m: ChatMessage) => (m.local || m.deleted ? {} : {
    onContextMenu: (e: React.MouseEvent) => { e.preventDefault(); setMenuFor(m) },
    onTouchStart: () => { window.clearTimeout(press.current); press.current = window.setTimeout(() => setMenuFor(m), 450) },
    onTouchMove: () => window.clearTimeout(press.current),
    onTouchEnd: () => window.clearTimeout(press.current),
    onTouchCancel: () => window.clearTimeout(press.current),
  })

  // ---------------------------------------------------------------- Rendu

  const info = state?.conversation
  const who = (key: string) => people[key]
  const others = Object.entries(people).filter(([, p]) => p.present && !p.is_me)
  const readBy = (m: ChatMessage) => {
    const keys = Object.keys(state?.reads ?? {})
    return keys.length > 0 && keys.every((k) => (state?.reads[k] ?? 0) >= m.id)
  }
  const typingNames = (state?.typing ?? []).map((k) => who(k)?.first_name).filter(Boolean)
  const subtitle = !info ? '' : typingNames.length
    ? `${typingNames.join(', ')} ${typingNames.length > 1 ? 'écrivent' : 'écrit'}…`
    : info.type === 'gem'
      ? ['Vous', ...others.map(([, p]) => p.first_name)].join(', ')
      : info.online ? 'en ligne' : ''
  const canEdit = (m: ChatMessage) => m.mine && !m.deleted && !!page && Date.now() - new Date(m.at).getTime() < page.edit_minutes * 60000 && !!info?.can_send

  // Citation : si le message cite est a l'ecran, son etat du moment fait foi (supprime ou modifie depuis).
  const loaded = new Map(items.filter((m) => !m.local).map((m) => [m.id, m]))
  const quoteText = (reply: NonNullable<ChatMessage['reply']>) => {
    const now = loaded.get(reply.id)
    if (now?.deleted || reply.deleted) return 'Message supprimé'
    if (now) return now.body?.slice(0, 120) || (now.kind === 'photo' ? 'Photo' : '')
    return reply.text || (reply.kind === 'photo' ? 'Photo' : '')
  }

  let lastDay = ''
  const rows: ReactNode[] = []
  items.forEach((m, i) => {
    const day = dayKey(m.at)
    if (day !== lastDay) { lastDay = day; rows.push(<div key={`d${day}`} className="chat-day"><span>{dayLabel(m.at)}</span></div>) }
    if (firstUnread.current === m.id) rows.push(<div key="unread" className="chat-unread-mark"><span>Messages non lus</span></div>)
    const prev = items[i - 1]
    const next = items[i + 1]
    const close = (a?: ChatMessage, b?: ChatMessage) => !!a && !!b && a.sender === b.sender && dayKey(a.at) === dayKey(b.at)
      && Math.abs(new Date(a.at).getTime() - new Date(b.at).getTime()) < 5 * 60000
    const first = !close(prev, m) || firstUnread.current === m.id
    const last = !close(m, next)
    const person = who(m.sender)
    const mineReaction = m.reactions.find((r) => state && r.by.includes(state.me))?.emoji
    rows.push(
      <div key={m.local ? m.client_id : m.id} data-mid={m.local ? undefined : m.id}
        className={`chat-row${m.mine ? ' is-mine' : ''}${first ? ' is-first' : ''}${last ? ' is-last' : ''}${found === m.id ? ' is-found' : ''}`}>
        {!m.mine && info?.type === 'gem' && <span className="chat-row-avatar">{last && <Avatar person={person} size={28} />}</span>}
        <div className={`chat-bubble${m.deleted ? ' is-deleted' : ''}${m.kind === 'photo' && !m.deleted ? ' has-photo' : ''}${m.local?.state === 'failed' ? ' is-failed' : ''}`} {...holders(m)}>
          {!m.mine && first && info?.type === 'gem' && (
            <span className="chat-sender">{person?.name ?? 'Ancien membre'}{person?.is_garde && <em> · Garde</em>}</span>
          )}
          {m.forwarded && !m.deleted && <span className="chat-forwarded"><Icon path={PATHS.forward} size={13} /> Transféré</span>}
          {m.reply && !m.deleted && (
            <span className="chat-quote">
              <strong>{who(m.reply.sender)?.is_me ? 'Vous' : who(m.reply.sender)?.name ?? 'Ancien membre'}</strong>
              <span>{quoteText(m.reply)}</span>
            </span>
          )}
          {m.deleted ? <p className="chat-text chat-deleted">{m.mine ? 'Vous avez supprimé ce message' : 'Ce message a été supprimé'}</p> : (
            <>
              {m.kind === 'photo' && <Photo m={m} onOpen={setViewer} />}
              {m.body && <Body text={m.body} />}
            </>
          )}
          <span className="chat-meta">
            {m.edited && <span>modifié</span>}
            <time dateTime={m.at}>{clock(m.at)}</time>
            {m.mine && !m.deleted && <Ticks m={m} read={readBy(m)} />}
          </span>
          {!m.local && !m.deleted && (
            <button type="button" className="chat-more" aria-label="Actions sur ce message" onClick={(e) => { e.stopPropagation(); setMenuFor(m) }}>
              <Icon path={PATHS.down} size={16} />
            </button>
          )}
          {m.reactions.length > 0 && (
            <span className="chat-reactions">
              {m.reactions.map((r) => (
                <button type="button" key={r.emoji} className={`chat-reaction${r.emoji === mineReaction ? ' is-mine' : ''}`}
                  title={r.by.map((k) => (who(k)?.is_me ? 'Vous' : who(k)?.name ?? 'Ancien membre')).join(', ')}
                  onClick={(e) => { e.stopPropagation(); if (info?.can_send) void react(m, r.emoji === mineReaction ? null : r.emoji) }}>
                  <span>{r.emoji}</span>{r.by.length > 1 && <small>{r.by.length}</small>}
                </button>
              ))}
            </span>
          )}
          {m.local?.state === 'failed' && (
            <span className="chat-failed">
              <Icon name="alert" size={14} /> {m.local.error || 'Non envoyé.'}
              <button type="button" className="btn-link" onClick={() => retry(m)}>Réessayer</button>
              <button type="button" className="btn-link" onClick={() => discard(m)}>Supprimer</button>
            </span>
          )}
        </div>
      </div>,
    )
  })

  const pane = (
    <div className={`chat-pane${phone ? ' chat-layer' : ''}`} ref={layerRef}>
      <header className="chat-head" ref={headRef}>
        <button type="button" className="chat-icon-btn chat-back" onClick={onBack} aria-label="Retour aux discussions"><Icon name="back" size={22} /></button>
        <button type="button" className="chat-head-main" onClick={() => info?.type === 'gem' && setShowInfo(true)} disabled={info?.type !== 'gem'}>
          {info?.type === 'gem'
            ? <span className="mini-avatar chat-avatar chat-avatar-gem" aria-hidden><Icon name="gem" size={20} /></span>
            : <Avatar person={{ name: info?.title ?? '?', photo_url: info?.photo_url ?? null }} size={40} />}
          <span className="chat-head-text">
            <strong>{info?.title ?? 'Discussion'}</strong>
            <small className={typingNames.length ? 'is-typing' : ''}>{subtitle}</small>
          </span>
        </button>
        {info && page && !gone && (
          <button type="button" className={`chat-icon-btn${searchOpen ? ' is-on' : ''}`} onClick={() => (searchOpen ? closeSearch() : openSearch())}
            aria-expanded={searchOpen} aria-label="Rechercher dans la discussion" title="Rechercher dans la discussion">
            <Icon name="search" size={20} />
          </button>
        )}
      </header>

      {searchOpen && (
        <div className="chat-search">
          <div className="chat-search-bar">
            <Icon name="search" size={18} />
            <input ref={searchInput} type="search" className="chat-search-input" value={query} maxLength={60} placeholder="Rechercher dans la discussion"
              aria-label="Rechercher dans la discussion" enterKeyHint="search" onChange={(e) => setQuery(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Escape') closeSearch() }} />
            <button type="button" className="chat-icon-btn" aria-label="Fermer la recherche" onClick={closeSearch}><Icon name="close" size={18} /></button>
          </div>
          <div className="chat-search-results" role="region" aria-label="Résultats de la recherche" aria-live="polite">
            {query.trim().length < 2 ? <p className="chat-search-note">Écrivez au moins 2 lettres.</p>
              : searching && !results ? <p className="chat-search-note"><span className="spinner" /></p>
                : results && results.list.length === 0 ? <p className="chat-search-note">Aucun message trouvé pour « {results.for} ».</p>
                  : results && (
                    <>
                      <ul className="chat-search-list">
                        {results.list.map((r) => (
                          <li key={r.id}>
                            <button type="button" className="chat-search-item" onClick={() => goTo(r.id)}>
                              <span className="chat-search-top"><strong>{r.sender}</strong><time dateTime={r.at}>{listTime(r.at)}</time></span>
                              <span className="chat-search-text">{r.kind === 'photo' && <Icon name="image" size={14} />} <Found text={r.text} query={results.for} /></span>
                            </button>
                          </li>
                        ))}
                      </ul>
                      {results.partial && <p className="chat-search-note">Seuls les messages les plus récents sont affichés : précisez votre recherche pour en voir d’autres.</p>}
                    </>
                  )}
          </div>
        </div>
      )}

      <div className="chat-scroll" ref={scroller} onScroll={onScroll}>
        {gone ? (
          <div className="empty-state"><Icon name="lock" size={28} /><h3>Discussion indisponible</h3><p>Vous n’y avez plus accès.</p>
            <button type="button" className="btn btn-ghost small mt-sm" onClick={onBack}>Retour aux discussions</button></div>
        ) : !page ? (
          error ? <LoadError message={error} onRetry={load} /> : <div className="chat-wait" role="status" aria-label="Chargement"><span className="spinner" /></div>
        ) : (
          <>
            {loadingOlder && <div className="chat-wait small"><span className="spinner" /></div>}
            {!hasMore && (
              <p className="chat-privacy"><Icon name="lock" size={14} /> Seuls les participants lisent cette discussion. Les messages sont chiffrés sur le serveur.</p>
            )}
            {items.length === 0 && <p className="chat-empty">Aucun message pour le moment. Écrivez le premier !</p>}
            {rows}
            {loadingNewer && <div className="chat-wait small"><span className="spinner" /></div>}
            {typingNames.length > 0 && !hasNewer && <div className="chat-row"><div className="chat-bubble chat-typing" aria-label={subtitle}><i /><i /><i /></div></div>}
          </>
        )}
      </div>

      {!atBottom && page && !gone && !searchOpen && (
        <button type="button" className="chat-jump" onClick={toBottom} aria-label="Aller aux derniers messages">
          {pendingBelow > 0 && <span className="nav-badge">{pendingBelow > 99 ? '99+' : pendingBelow}</span>}
          <Icon path={PATHS.down} size={20} />
        </button>
      )}

      {page && !gone && (info?.can_send ? (
        <footer className="chat-compose">
          {(replyTo || editing) && (
            <div className="chat-context">
              <Icon path={editing ? undefined : PATHS.reply} name={editing ? 'edit' : undefined} size={16} />
              <span className="chat-context-text">
                <strong>{editing ? 'Modification du message' : `Réponse à ${who(replyTo!.sender)?.is_me ? 'vous-même' : who(replyTo!.sender)?.name ?? 'un ancien membre'}`}</strong>
                <span>{(editing ?? replyTo)!.body || 'Photo'}</span>
              </span>
              <button type="button" className="chat-icon-btn" aria-label="Annuler" onClick={() => { if (editing) { setEditing(null); setText(readDraft<string>(`chat:${id}`) ?? '') } else setReplyTo(null) }}><Icon name="close" size={18} /></button>
            </div>
          )}
          {attached && (
            <div className="chat-context">
              <img className="chat-attached" src={attached.preview} alt="Photo à envoyer" />
              <span className="chat-context-text"><strong>Photo à envoyer</strong><span>Ajoutez une légende si vous le souhaitez.</span></span>
              <button type="button" className="chat-icon-btn" aria-label="Retirer la photo" onClick={() => setAttached(null)}><Icon name="close" size={18} /></button>
            </div>
          )}
          {showEmoji && (
            <div className="chat-emojis" role="group" aria-label="Émojis">
              {EMOJIS.map((e) => <button type="button" key={e} onClick={() => { onType(text + e); input.current?.focus() }}>{e}</button>)}
            </div>
          )}
          <div className="chat-compose-row">
            <button type="button" className={`chat-icon-btn${showEmoji ? ' is-on' : ''}`} aria-label="Émojis" aria-pressed={showEmoji} onClick={() => setShowEmoji((v) => !v)}><Icon path={PATHS.smile} size={22} /></button>
            {!editing && (
              <>
                <button type="button" className="chat-icon-btn" aria-label="Joindre une photo" onClick={() => fileInput.current?.click()}><Icon path={PATHS.attach} size={22} /></button>
                <input ref={fileInput} type="file" accept="image/*" hidden onChange={(e) => void pick(e.target.files?.[0])} />
              </>
            )}
            <textarea ref={input} className="chat-input" rows={1} value={text} maxLength={page.max_length} placeholder="Message"
              aria-label="Votre message" enterKeyHint={touchDevice() ? 'enter' : 'send'}
              onChange={(e) => onType(e.target.value)} onKeyDown={onKey} />
            <button type="button" className="chat-send" disabled={busy || (!text.trim() && !attached && !(editing && editing.kind === 'photo'))}
              onClick={() => void submit()} aria-label={editing ? 'Enregistrer la modification' : 'Envoyer'}>
              <Icon path={editing ? undefined : PATHS.send} name={editing ? 'check' : undefined} size={20} />
            </button>
          </div>
        </footer>
      ) : (
        <footer className="chat-closed"><Icon name="lock" size={16} /> Vous n’êtes plus dans le même GEM : cette discussion reste lisible, mais on ne peut plus y écrire.</footer>
      ))}

      {menuFor && (
        <ModalLayer className="modal-overlay chat-sheet-overlay" onClick={() => setMenuFor(null)}>
          <div className="modal-box chat-sheet" onClick={(e) => e.stopPropagation()} aria-label="Actions sur le message">
            {info?.can_send && (
              <div className="chat-sheet-reactions">
                {page?.reactions.map((e) => {
                  const mine = menuFor.reactions.find((r) => state && r.by.includes(state.me))?.emoji === e
                  return <button type="button" key={e} className={mine ? 'is-mine' : ''} aria-pressed={mine} aria-label={`Réagir avec ${e}`} onClick={() => void react(menuFor, mine ? null : e)}>{e}</button>
                })}
              </div>
            )}
            {info?.can_send && <button type="button" className="chat-sheet-item" onClick={() => startReply(menuFor)}><Icon path={PATHS.reply} size={18} /> Répondre</button>}
            <button type="button" className="chat-sheet-item" onClick={() => { setForwardFor(menuFor); setMenuFor(null) }}><Icon path={PATHS.forward} size={18} /> Transférer</button>
            {menuFor.body && <button type="button" className="chat-sheet-item" onClick={() => void copy(menuFor)}><Icon path={PATHS.copy} size={18} /> Copier le texte</button>}
            {canEdit(menuFor) && <button type="button" className="chat-sheet-item" onClick={() => startEdit(menuFor)}><Icon name="edit" size={18} /> Modifier</button>}
            {menuFor.mine && <button type="button" className="chat-sheet-item is-danger" onClick={() => void remove(menuFor)}><Icon name="trash" size={18} /> Supprimer pour tout le monde</button>}
            {!menuFor.mine && <button type="button" className="chat-sheet-item is-danger" onClick={() => { setReportFor(menuFor); setMenuFor(null) }}><Icon path={PATHS.flag} size={18} /> Signaler au Pasteur Résident</button>}
          </div>
        </ModalLayer>
      )}

      {forwardFor && (
        <ModalLayer className="modal-overlay" onClick={() => setForwardFor(null)}>
          <div className="modal-box" onClick={(e) => e.stopPropagation()}>
            <h3>Transférer le message</h3>
            <p className="chat-forward-what">{forwardFor.kind === 'photo' && <Icon name="image" size={14} />} {forwardFor.body || 'Photo'}</p>
            <p className="helper">Choisissez la discussion. Le message y sera envoyé à votre nom, avec la mention « Transféré ».</p>
            <ul className="my-gem-list chat-members">
              {targets.filter((t) => t.can_send).map((t) => (
                <li key={`c${t.id}`}>
                  <button type="button" className="my-gem-member" disabled={busy} onClick={() => void forward(forwardFor, { conversation_id: t.id, title: t.title })}>
                    {t.type === 'gem'
                      ? <span className="mini-avatar chat-avatar chat-avatar-gem" aria-hidden><Icon name="gem" size={20} /></span>
                      : <Avatar person={{ name: t.title, photo_url: t.photo_url }} size={40} />}
                    <span className="my-gem-member-name">{t.title}{t.id === id && <small> (cette discussion)</small>}</span>
                  </button>
                </li>
              ))}
              {contacts.map((c) => (
                <li key={`k${c.key}`}>
                  <button type="button" className="my-gem-member" disabled={busy} onClick={() => void forward(forwardFor, { key: c.key, title: c.name })}>
                    <Avatar person={{ name: c.name, photo_url: c.photo_url }} size={40} />
                    <span className="my-gem-member-name">{c.name}</span>
                    {c.is_garde && <span className="my-gem-badge">Garde</span>}
                  </button>
                </li>
              ))}
            </ul>
            <div className="editor-actions"><button type="button" className="btn btn-ghost" onClick={() => setForwardFor(null)}>Annuler</button></div>
          </div>
        </ModalLayer>
      )}

      {reportFor && (
        <ModalLayer className="modal-overlay" onClick={() => { setReportFor(null); setReason('') }}>
          <div className="modal-box" onClick={(e) => e.stopPropagation()}>
            <h3>Signaler ce message</h3>
            <p className="helper">Ce message, et lui seul, sera montré au Pasteur Résident avec votre motif. Le reste de la discussion reste privé.</p>
            <div className="field"><label htmlFor="chat-report-reason">Motif</label>
              <textarea id="chat-report-reason" className="textarea" rows={3} maxLength={300} value={reason} onChange={(e) => setReason(e.target.value)} />
            </div>
            <div className="editor-actions">
              <button type="button" className="btn btn-ghost" onClick={() => { setReportFor(null); setReason('') }}>Annuler</button>
              <button type="button" className="btn btn-primary" disabled={busy || reason.trim().length < 3} onClick={() => void sendReport()}>Signaler</button>
            </div>
          </div>
        </ModalLayer>
      )}

      {showInfo && info && (
        <ModalLayer className="modal-overlay" onClick={() => setShowInfo(false)}>
          <div className="modal-box" onClick={(e) => e.stopPropagation()}>
            <h3>{info.title}</h3>
            <p className="helper">{info.members} membre{info.members > 1 ? 's' : ''}. Touchez un membre pour lui écrire en privé.</p>
            <ul className="my-gem-list chat-members">
              {Object.entries(people).filter(([, p]) => p.present).sort(([, a], [, b]) => Number(b.is_garde) - Number(a.is_garde) || a.name.localeCompare(b.name, 'fr')).map(([key, p]) => (
                <li key={key}>
                  <button type="button" className={`my-gem-member${p.is_garde ? ' is-garde' : ''}`} disabled={p.is_me} onClick={() => { setShowInfo(false); onOpenDirect(key) }}>
                    <Avatar person={p} size={40} />
                    <span className="my-gem-member-name">{p.name}{p.is_me && <small> (vous)</small>}</span>
                    {p.is_garde && <span className="my-gem-badge">Garde</span>}
                  </button>
                </li>
              ))}
            </ul>
          </div>
        </ModalLayer>
      )}

      {viewer && (
        <ModalLayer className="modal-overlay chat-viewer" onClick={() => setViewer(null)} role="dialog" aria-label="Photo">
          <img src={viewer} alt="Photo en grand" />
          <button type="button" className="chat-icon-btn chat-viewer-close" aria-label="Fermer" onClick={() => setViewer(null)}><Icon name="close" size={24} /></button>
        </ModalLayer>
      )}
    </div>
  )

  return phone ? createPortal(pane, appLayer()) : pane
}
