import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { Icon } from '../components/Icon'
import { LoadError } from '../components/LoadError'
import { SkeletonCard } from '../components/Skeleton'
import { Conversation } from '../chat/Conversation'
import { initial, listTime, onChatPush, setChatUnread, useChatUnread, type ChatContact, type ChatInbox, type ChatListItem } from '../chat/chat'
import '../chat.css'

/**
 * Messages : la discussion de groupe de son GEM et ses discussions privees avec les membres de son GEM.
 * Ordinateur : la liste a gauche, la discussion a droite. Telephone : la liste, puis la discussion en plein ecran.
 */
export default function Chat() {
  const params = useParams()
  const [search] = useSearchParams()
  const navigate = useNavigate()
  const openId = params.id && /^\d+$/.test(params.id) ? Number(params.id) : null
  const [inbox, setInbox] = useState<ChatInbox | null>(() => peek<ChatInbox>('/me/chat') ?? null)
  const [loadError, setLoadError] = useState('')
  const [opening, setOpening] = useState<string | null>(null)
  const counter = useRef(0)
  const unread = useChatUnread()

  const load = useCallback(() => {
    api<ChatInbox>(`/me/chat?n=${++counter.current}`).then((r) => { setInbox(r); setLoadError(''); setChatUnread(r.unread) })
      .catch((err) => setLoadError(err instanceof ApiError ? err.firstMessage : 'Erreur.'))
  }, [])
  useEffect(() => { load() }, [load])
  // Un message arrive (notification du telephone, pastille du pouls) : la liste se relit ; sinon toutes les 20 s a l'ecran.
  useEffect(() => onChatPush(load), [load])
  const seen = useRef(unread)
  useEffect(() => { if (seen.current !== unread) { seen.current = unread; load() } }, [unread, load])
  useEffect(() => {
    const t = window.setInterval(() => { if (document.visibilityState === 'visible') load() }, 20000)
    return () => window.clearInterval(t)
  }, [load])

  const openDirect = useCallback(async (key: string) => {
    if (opening) return
    setOpening(key)
    try {
      const r = await api<{ conversation_id: number }>('/me/chat/direct', { method: 'POST', body: { key }, toast: false })
      navigate(`/chat/${r.conversation_id}`)
    } catch (err) {
      setLoadError(err instanceof ApiError ? err.firstMessage : 'Erreur.')
    } finally { setOpening(null) }
  }, [navigate, opening])

  // Arrivee depuis « Mon GEM » (/chat?avec=cle) : la discussion privee s'ouvre directement.
  const withKey = search.get('avec')
  const asked = useRef<string | null>(null)
  useEffect(() => {
    if (withKey && /^[a-f0-9]{20}$/.test(withKey) && asked.current !== withKey) {
      asked.current = withKey
      void openDirect(withKey)
    }
  }, [withKey, openDirect])

  // Arrivee par le bouton « Discussion du GEM » (/chat?groupe=1) : le groupe s'ouvre des que la liste est lue.
  const wantsGroup = search.get('groupe') === '1'
  useEffect(() => {
    const group = wantsGroup && !openId ? inbox?.conversations.find((c) => c.type === 'gem') : null
    if (group) navigate(`/chat/${group.id}`, { replace: true })
  }, [wantsGroup, openId, inbox, navigate])

  // Ordinateur : le cadre occupe exactement la hauteur restante sous l'en-tete (bandeaux compris), sans faire defiler la page.
  const frame = useRef<HTMLDivElement>(null)
  useLayoutEffect(() => {
    const el = frame.current
    if (!el) return
    const fit = () => {
      if (window.matchMedia('(max-width: 860px)').matches) { el.style.removeProperty('--chat-height'); return }
      const scroller = document.scrollingElement
      const top = el.getBoundingClientRect().top + (scroller?.scrollTop ?? 0)
      el.style.setProperty('--chat-height', `${Math.max(420, window.innerHeight - top - 44)}px`)
    }
    fit()
    const observer = new ResizeObserver(fit)
    if (el.parentElement) observer.observe(el.parentElement)
    window.addEventListener('resize', fit)
    return () => { observer.disconnect(); window.removeEventListener('resize', fit) }
  }, [])

  const contacts = (inbox?.contacts ?? []).filter((c) => !c.conversation_id || !inbox?.conversations.some((x) => x.id === c.conversation_id))

  return (
    <AppLayout title="Messages" subtitle="Le chat de votre GEM">
      <div className={`chat${openId ? ' has-open' : ''}`} ref={frame}>
        <aside className="chat-list" aria-label="Discussions">
          {inbox === null ? (
            loadError ? <LoadError message={loadError} onRetry={() => { setLoadError(''); load() }} /> : <SkeletonCard />
          ) : inbox.conversations.length === 0 && inbox.contacts.length === 0 ? (
            <div className="empty-state"><Icon name="gem" size={28} /><h3>Pas encore de GEM</h3><p>Le chat s’ouvre dès que vous faites partie d’un GEM.</p></div>
          ) : (
            <>
              {loadError && <p className="alert alert-error" role="alert">{loadError}</p>}
              <ul className="chat-items">
                {inbox.conversations.map((c) => <ListRow key={c.id} c={c} active={c.id === openId} onOpen={() => navigate(`/chat/${c.id}`)} />)}
              </ul>
              {contacts.length > 0 && (
                <>
                  <h3 className="section-label chat-contacts-title">Écrire à un membre de mon GEM</h3>
                  <ul className="chat-items">
                    {contacts.map((c) => <ContactRow key={c.key} c={c} busy={opening === c.key} onOpen={() => void openDirect(c.key)} />)}
                  </ul>
                </>
              )}
              <p className="chat-privacy"><Icon name="lock" size={14} /> Seuls les participants lisent les messages.</p>
            </>
          )}
        </aside>
        <section className="chat-main">
          {openId
            ? <Conversation id={openId} onBack={() => navigate('/chat')} onChanged={load} onOpenDirect={(key) => void openDirect(key)}
                targets={inbox?.conversations ?? []} contacts={contacts} onOpen={(to) => navigate(`/chat/${to}`)} />
            : <div className="empty-state chat-pick"><Icon name="requests" size={30} /><h3>Vos messages</h3><p>Choisissez une discussion.</p></div>}
        </section>
      </div>
    </AppLayout>
  )
}

function ListRow({ c, active, onOpen }: { c: ChatListItem; active: boolean; onOpen: () => void }) {
  return (
    <li>
      <button type="button" className={`chat-item${active ? ' is-active' : ''}${c.unread ? ' is-unread' : ''}`} onClick={onOpen} aria-current={active ? 'true' : undefined}>
        <span className="chat-item-avatar">
          {c.type === 'gem'
            ? <span className="mini-avatar chat-avatar chat-avatar-gem" aria-hidden><Icon name="gem" size={22} /></span>
            : c.photo_url ? <img className="mini-avatar chat-avatar" src={c.photo_url} alt="" width={46} height={46} loading="lazy" decoding="async" />
              : <span className="mini-avatar chat-avatar" aria-hidden>{initial(c.title)}</span>}
          {c.online && <i className="chat-online" title="En ligne" aria-label="En ligne" />}
        </span>
        <span className="chat-item-text">
          <span className="chat-item-top">
            <strong>{c.title}</strong>
            {c.last && <time dateTime={c.last.at}>{listTime(c.last.at)}</time>}
          </span>
          <span className="chat-item-bottom">
            <span className={`chat-item-last${c.last?.deleted ? ' is-deleted' : ''}`}>
              {c.last
                ? <>{c.last.mine ? 'Vous : ' : c.type === 'gem' && c.last.sender ? `${c.last.sender} : ` : ''}{c.last.kind === 'photo' && !c.last.deleted && <Icon name="image" size={14} />} {c.last.text}</>
                : c.type === 'gem' ? 'Discussion de tout le GEM' : 'Aucun message'}
            </span>
            {c.unread > 0 && <span className="nav-badge" aria-label={`${c.unread} message(s) non lu(s)`}>{c.unread > 99 ? '99+' : c.unread}</span>}
          </span>
        </span>
      </button>
    </li>
  )
}

function ContactRow({ c, busy, onOpen }: { c: ChatContact; busy: boolean; onOpen: () => void }) {
  return (
    <li>
      <button type="button" className="chat-item" onClick={onOpen} disabled={busy}>
        <span className="chat-item-avatar">
          {c.photo_url ? <img className="mini-avatar chat-avatar" src={c.photo_url} alt="" width={46} height={46} loading="lazy" decoding="async" />
            : <span className="mini-avatar chat-avatar" aria-hidden>{initial(c.name)}</span>}
        </span>
        <span className="chat-item-text">
          <span className="chat-item-top"><strong>{c.name}</strong>{c.is_garde && <span className="my-gem-badge">Garde</span>}</span>
          <span className="chat-item-bottom"><span className="chat-item-last">{c.gem}</span></span>
        </span>
      </button>
    </li>
  )
}
