// Chat des GEMs : types, pastille des messages non lus, photos privees et petits outils d'affichage.
import { useEffect, useState } from 'react'
import { auth } from '../api/client'

export interface ChatPerson { name: string; first_name: string; photo_url: string | null; is_garde: boolean; is_me: boolean; present: boolean }

export interface ChatReply { id: number; sender: string; kind: 'text' | 'photo'; deleted: boolean; text: string }

export interface ChatMessage {
  id: number
  /** Identifiant d'envoi choisi par l'appareil (ses propres messages seulement). */
  client_id: string | null
  sender: string
  mine: boolean
  kind: 'text' | 'photo'
  body: string | null
  photo: { width: number; height: number } | null
  reply: ChatReply | null
  reactions: { emoji: string; by: string[] }[]
  edited: boolean
  /** Message transfere depuis une autre discussion. */
  forwarded?: boolean
  deleted: boolean
  at: string
  rev: number
  /** Etat local d'un message en cours d'envoi (jamais envoye par le serveur). */
  local?: { state: 'sending' | 'failed'; file?: File; preview?: string; error?: string; replyTo?: number | null }
}

export interface ChatConversationInfo {
  id: number
  type: 'gem' | 'direct'
  title: string
  photo_url: string | null
  peer: string | null
  members: number
  can_send: boolean
  online: boolean
}

export interface ChatState {
  conversation: ChatConversationInfo
  me: string
  rev: number
  last_read_id: number
  reads: Record<string, number>
  typing: string[]
}

export interface ChatPage extends ChatState {
  messages: ChatMessage[]
  people: Record<string, ChatPerson>
  /** null : cette page ne dit rien de ce cote. */
  has_more: boolean | null
  has_newer: boolean | null
  first_unread_id: number | null
  edit_minutes: number
  reactions: string[]
  max_length: number
}

export interface ChatSync extends ChatState {
  reload: boolean
  messages: ChatMessage[]
  people: Record<string, ChatPerson>
  unread_total: number
}

export interface ChatListItem {
  id: number
  type: 'gem' | 'direct'
  title: string
  photo_url: string | null
  peer: string | null
  online: boolean
  can_send: boolean
  unread: number
  last: { text: string; kind: 'text' | 'photo'; deleted: boolean; mine: boolean; sender: string | null; at: string } | null
}

export interface ChatContact { key: string; name: string; photo_url: string | null; is_garde: boolean; gem: string; conversation_id: number | null }

export interface ChatSearchResult { id: number; sender: string; kind: 'text' | 'photo'; text: string; at: string }

export interface ChatInbox { me: string; conversations: ChatListItem[]; contacts: ChatContact[]; unread: number }

// ---------------------------------------------------------------- Pastille des messages non lus (menu)

let unread = 0
const listeners = new Set<(n: number) => void>()

/** Nombre de messages non lus, recu avec le pouls, la liste ou une discussion ouverte. */
export function setChatUnread(n: number) {
  const next = Math.max(0, Math.floor(n) || 0)
  if (next === unread) return
  unread = next
  listeners.forEach((l) => l(unread))
}

export function useChatUnread(enabled = true): number {
  const [n, setN] = useState(unread)
  useEffect(() => {
    if (!enabled) return
    setN(unread)
    listeners.add(setN)
    return () => { listeners.delete(setN) }
  }, [enabled])
  return enabled ? n : 0
}

// ---------------------------------------------------------------- Discussion a l'ecran

let openId: number | null = null
/** Discussion affichee en ce moment (les notifications de cette discussion ne s'affichent pas par-dessus). */
export function setOpenConversation(id: number | null) { openId = id }
export function openConversation(): number | null { return openId }

/** Une notification du chat vient d'arriver sur l'appareil : la liste et la discussion ouverte se relisent tout de suite. */
const pushListeners = new Set<() => void>()
export function onChatPush(cb: () => void): () => void {
  pushListeners.add(cb)
  return () => { pushListeners.delete(cb) }
}
export function chatPushReceived() { pushListeners.forEach((l) => l()) }

// ---------------------------------------------------------------- Photos privees

// Les photos du chat n'ont pas d'adresse publique : elles sont lues avec la session, puis gardees en memoire
// le temps de l'ouverture de l'application (rien n'est ecrit sur l'appareil).
const photos = new Map<number, Promise<string>>()
let photosFor: string | null = null

export function chatPhoto(messageId: number): Promise<string> {
  const token = auth.get()
  if (photosFor !== token) { forgetChatPhotos(); photosFor = token }
  let p = photos.get(messageId)
  if (!p) {
    p = fetch(`/api/me/chat/messages/${messageId}/photo`, { headers: { Authorization: `Bearer ${token ?? ''}` } })
      .then((res) => { if (!res.ok) throw new Error('photo'); return res.blob() })
      .then((blob) => URL.createObjectURL(blob))
    p.catch(() => { photos.delete(messageId) })
    photos.set(messageId, p)
  }
  return p
}

export function forgetChatPhotos() {
  photos.forEach((p) => { p.then((url) => URL.revokeObjectURL(url)).catch(() => {}) })
  photos.clear()
}

export function useChatPhoto(messageId: number | null): { url: string | null; failed: boolean } {
  const [state, setState] = useState<{ url: string | null; failed: boolean }>({ url: null, failed: false })
  useEffect(() => {
    if (!messageId) return
    let alive = true
    setState({ url: null, failed: false })
    chatPhoto(messageId).then((url) => { if (alive) setState({ url, failed: false }) }).catch(() => { if (alive) setState({ url: null, failed: true }) })
    return () => { alive = false }
  }, [messageId])
  return state
}

// ---------------------------------------------------------------- Affichage

export function newClientId(): string {
  const bytes = new Uint8Array(12)
  crypto.getRandomValues(bytes)
  return 'c' + Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
}

const sameDay = (a: Date, b: Date) => a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()

/** « Aujourd'hui », « Hier », « lundi 3 novembre » (et l'annee si ce n'est pas celle en cours). */
export function dayLabel(iso: string): string {
  const d = new Date(iso)
  const now = new Date()
  if (sameDay(d, now)) return 'Aujourd’hui'
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (sameDay(d, yesterday)) return 'Hier'
  return d.toLocaleDateString('fr-CA', { weekday: 'long', day: 'numeric', month: 'long', ...(d.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {}) })
}

export function dayKey(iso: string): string {
  const d = new Date(iso)
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`
}

export function clock(iso: string): string {
  return new Date(iso).toLocaleTimeString('fr-CA', { hour: '2-digit', minute: '2-digit', hour12: false }).replace(':', ' h ')
}

/** Heure du jour meme, « Hier », jour de la semaine, puis date courte (liste des discussions). */
export function listTime(iso: string): string {
  const d = new Date(iso)
  const now = new Date()
  if (sameDay(d, now)) return clock(iso)
  const days = Math.floor((now.getTime() - d.getTime()) / 86400000)
  const yesterday = new Date(now)
  yesterday.setDate(now.getDate() - 1)
  if (sameDay(d, yesterday)) return 'Hier'
  if (days < 7) return d.toLocaleDateString('fr-CA', { weekday: 'long' })
  return d.toLocaleDateString('fr-CA', { day: 'numeric', month: 'short' })
}

/** Texte decoupe en morceaux : texte simple et liens http(s) (jamais d'autre protocole). */
export function linkParts(text: string): { text: string; href?: string }[] {
  const out: { text: string; href?: string }[] = []
  const re = /https?:\/\/[^\s<>"']+/gi
  let last = 0
  for (let m = re.exec(text); m; m = re.exec(text)) {
    // Ponctuation de fin de phrase : hors du lien.
    const url = m[0].replace(/[.,;:!?)\]]+$/, '')
    if (m.index > last) out.push({ text: text.slice(last, m.index) })
    out.push({ text: url, href: url })
    last = m.index + url.length
    re.lastIndex = last
  }
  if (last < text.length) out.push({ text: text.slice(last) })
  return out
}

/** Message fait seulement de 1 a 3 emojis : affiche en grand, comme dans les messageries. */
export function onlyEmoji(text: string): boolean {
  const t = text.trim()
  if (!t || t.length > 24) return false
  try {
    return /^(?:\p{Extended_Pictographic}(?:️|‍\p{Extended_Pictographic}|[\u{1F3FB}-\u{1F3FF}])*\s*){1,3}$/u.test(t)
  } catch { return false }
}

export const initial = (name: string) => (name.trim()[0] ?? '?').toUpperCase()

/** Emojis proposes dans la zone de saisie (le clavier du telephone donne tous les autres). */
export const EMOJIS = ['😀', '😂', '😊', '😍', '🥰', '😘', '😎', '🤗', '🤔', '😅', '😢', '😭', '😮', '😴', '🙏', '👍', '👏', '🙌', '💪', '🤝', '❤️', '💛', '💚', '🔥', '✨', '🎉', '🎶', '📖', '✝️', '🕊️', '☀️', '🌙', '☕', '🍽️', '✅', '❗']
