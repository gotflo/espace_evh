import { useCallback, useEffect, useState } from 'react'
import { api, ApiError, peek } from '../api/client'
import { AppLayout } from '../components/AppLayout'
import { Icon } from '../components/Icon'
import { LoadError } from '../components/LoadError'
import { SkeletonCard } from '../components/Skeleton'
import type { MyGemData } from '../types'

/** Mon GEM : la liste de ceux qui composent son GEM, le Garde signale. Simple lecture : aucune fiche ne s'ouvre. */
export default function MyGem() {
  const [data, setData] = useState<MyGemData | null>(() => peek<MyGemData>('/me/gem') ?? null)
  const [loadError, setLoadError] = useState('')

  const load = useCallback(() => {
    api<MyGemData>('/me/gem').then((d) => { setData(d); setLoadError('') })
      .catch((err) => setLoadError(err instanceof ApiError ? err.firstMessage : 'Erreur.'))
  }, [])
  useEffect(() => { load() }, [load])

  const gem = data?.gem ?? null

  return (
    <AppLayout title="Mon GEM" subtitle={gem ? [gem.label, gem.tribe && `Tribu ${gem.tribe}`].filter(Boolean).join(' · ') : undefined}>
      {data === null ? (
        loadError ? <LoadError message={loadError} onRetry={() => { setLoadError(''); load() }} /> : <SkeletonCard />
      ) : !gem ? (
        <div className="empty-state"><Icon name="gem" size={28} /><h3>Aucun GEM pour le moment</h3></div>
      ) : (
        <section className="panel my-gem-page">
          <div className="panel-head">
            <h3 className="my-gem-title">{gem.label}</h3>
            <span className="helper">{gem.members.length} membre{gem.members.length > 1 ? 's' : ''}</span>
          </div>
          <ul className="my-gem-list">
            {gem.members.map((m, i) => (
              <li key={i} className={`my-gem-member${m.is_garde ? ' is-garde' : ''}`}>
                {m.photo_url
                  ? <img className="mini-avatar" src={m.photo_url} alt="" width={40} height={40} loading="lazy" decoding="async" />
                  : <span className="mini-avatar" aria-hidden>{(m.name.trim()[0] ?? '?').toUpperCase()}</span>}
                <span className="my-gem-member-name">{m.name}{m.is_me && <small> (vous)</small>}</span>
                {m.is_garde && <span className="my-gem-badge">Garde</span>}
              </li>
            ))}
          </ul>
        </section>
      )}
    </AppLayout>
  )
}
