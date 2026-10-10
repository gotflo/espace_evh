import { useEffect, useRef, useState } from 'react'
import { api } from '../api/client'

export interface VideoPreview { video_id: string; thumbnail: string; title: string | null; author: string | null; embeddable?: boolean }

/** Lien qui vise YouTube (youtube.com, youtu.be, m.youtube.com, youtube-nocookie.com). */
export const isYouTubeLink = (url: string) => /(^|\/\/|\.)(youtube(-nocookie)?\.com|youtu\.be)(\/|$)/i.test(url.trim())

/**
 * Reconnaissance automatique d'un lien YouTube : identifiant, miniature, titre et auteur (verifies par le
 * serveur). « invalid » : le lien vise YouTube mais aucune video n'y est reconnue. onFound remplit les
 * champs encore vides (titre, auteur).
 */
export function useYouTubePreview(url: string, onFound?: (p: VideoPreview) => void, source: 'content' | 'exercises' = 'content') {
  const [preview, setPreview] = useState<VideoPreview | null>(null)
  const [status, setStatus] = useState<'idle' | 'loading' | 'ok' | 'invalid'>('idle')
  const found = useRef(onFound)
  found.current = onFound
  useEffect(() => {
    const u = url.trim()
    if (!u || !isYouTubeLink(u)) { setPreview(null); setStatus('idle'); return }
    setStatus('loading')
    const t = window.setTimeout(() => {
      api<VideoPreview>(source === 'exercises' ? '/admin/exercises/video-preview' : '/admin/content/video-preview', { method: 'POST', body: { url: u }, toast: false })
        .then((p) => { setPreview(p); setStatus('ok'); found.current?.(p) })
        .catch(() => { setPreview(null); setStatus('invalid') })
    }, 500)
    return () => window.clearTimeout(t)
  }, [url, source])
  return { preview, status }
}
