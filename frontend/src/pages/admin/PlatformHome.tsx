import { useCallback, useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { api, peek } from '../../api/client'
import { usePulse } from '../../pulse'
import { useAuth } from '../../auth/AuthContext'
import { AppLayout } from '../../components/AppLayout'
import { Icon } from '../../components/Icon'
import { Skeleton } from '../../components/Skeleton'
import { ICONS } from '../../utils/icons'

interface Overview {
  accounts: { members: number; new_this_month: number; active: number; pending: number; blocked: number }
  roles: { total: number; custom: number; holders: number }
  organization: { tribes: number; departments: number; gems: number }
  content: {
    verses_live: number; verses_draft: number; resources_published: number; resources_draft: number; resources_videos: number
    assignments: number; announcements_30d: number; events_upcoming: number; exercises_open: number
  }
}

/** Raccourcis de gestion, affiches selon les permissions. */
const SHORTCUTS: { to: string; label: string; hint: string; icon: string; perm: string }[] = [
  { to: '/admin/ressources', label: 'Ressources', hint: 'Liens et vidéos YouTube', icon: ICONS.link, perm: 'resources.view' },
  { to: '/admin/versets', label: 'Versets', hint: 'Textes bibliques du tableau de bord', icon: ICONS.book, perm: 'verses.view' },
  { to: '/admin/devoirs-rm', label: 'Devoirs (DS)', hint: 'Publier une vidéo ou un audio', icon: ICONS.devoir, perm: 'assignments.publish' },
  { to: '/admin/annonces', label: 'Annonces', hint: 'Publier pour l’église', icon: ICONS.announce, perm: 'announcements.publish' },
  { to: '/admin/evenements', label: 'Événements', hint: 'Créer et modifier', icon: ICONS.events, perm: 'events.manage' },
  { to: '/admin/horaires', label: 'Horaires', hint: 'Cultes de la semaine', icon: ICONS.clock, perm: 'schedule.view' },
  { to: '/admin/exercices', label: 'Exercices', hint: 'Créer et assigner', icon: ICONS.exercises, perm: 'exercises.assign' },
  { to: '/admin/comptes', label: 'Comptes', hint: 'Attribuer des rôles', icon: ICONS.profile, perm: 'accounts.view' },
  { to: '/admin/roles', label: 'Rôles', hint: 'Permissions par module', icon: ICONS.roles, perm: 'roles.manage' },
  { to: '/admin/organisation', label: 'Organisation', hint: 'Tribus et départements', icon: ICONS.org, perm: 'tribes.manage' },
  { to: '/admin/gems', label: 'GEMs', hint: 'Groupes des tribus', icon: ICONS.gem, perm: 'gems.manage' },
  { to: '/admin/journal', label: 'Journal', hint: 'Opérations de gestion', icon: ICONS.audit, perm: 'audit.view' },
]

/**
 * Tableau de bord de gestion : chiffres globaux (comptes, roles, organisation, contenus) et raccourcis.
 * Aucune donnee personnelle ni aucun contenu envoye par les fideles n'y figure.
 */
export default function PlatformHome() {
  const { hasPermission, isPlatformManager } = useAuth()
  const navigate = useNavigate()
  const [data, setData] = useState<Overview | null>(() => peek<Overview>('/admin/platform/overview') ?? null)
  const [failed, setFailed] = useState(false)

  const load = useCallback(() => {
    api<Overview>('/admin/platform/overview').then((d) => { setData(d); setFailed(false) }).catch(() => setFailed(true))
  }, [])
  useEffect(() => { load() }, [load])
  usePulse(['members', 'validations', 'requests'], load)

  const tile = (value: number | undefined, label: string, to?: string, tone = '') => (
    <button className={`stat-tile ${tone}`} onClick={() => to && navigate(to)} disabled={!to} aria-label={`${label} : ${value ?? '-'}`}>
      <span className="stat-value">{value ?? '-'}</span>
      <span className="stat-label">{label}</span>
    </button>
  )
  const go = (perm: string, to: string) => (hasPermission(perm) ? to : undefined)

  return (
    <AppLayout title="Tableau de gestion" subtitle="Comptes, rôles et contenus de la plateforme">
      {isPlatformManager && (
        <div className="alert alert-info pm-note">
          <Icon name="lock" size={15} /> Compte de gestion : les fiches, rapports, FISS et Vertumètres des fidèles ne sont pas accessibles depuis ce compte.
        </div>
      )}
      {failed && <div className="alert alert-error">Le tableau de gestion n’a pas pu être chargé. Réessayez dans un instant.</div>}

      {!data && !failed ? (
        <div className="stat-row">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="skeleton-tile" />)}</div>
      ) : data && (
        <>
          <h2 className="pm-section">Comptes</h2>
          <div className="stat-row">
            {tile(data.accounts.members, 'Membres inscrits', go('accounts.view', '/admin/comptes'))}
            {tile(data.accounts.active, 'Actifs', undefined, 'stat-ok')}
            {tile(data.accounts.new_this_month, 'Nouveaux ce mois-ci')}
            {tile(data.accounts.pending, 'Inscriptions en attente', undefined, data.accounts.pending ? 'stat-warn' : '')}
          </div>

          <h2 className="pm-section">Rôles et organisation</h2>
          <div className="stat-row">
            {tile(data.roles.total, `Rôles (${data.roles.custom} personnalisé${data.roles.custom > 1 ? 's' : ''})`, go('roles.manage', '/admin/roles'))}
            {tile(data.roles.holders, 'Personnes avec une fonction', go('accounts.view', '/admin/comptes'))}
            {tile(data.organization.tribes, 'Tribus', go('tribes.manage', '/admin/organisation'))}
            {tile(data.organization.departments, 'Départements', go('departments.manage', '/admin/organisation'))}
            {tile(data.organization.gems, 'GEMs', go('gems.manage', '/admin/gems'))}
          </div>

          <h2 className="pm-section">Contenus</h2>
          <div className="stat-row">
            {tile(data.content.resources_published, `Ressources publiées (${data.content.resources_videos} vidéo${data.content.resources_videos > 1 ? 's' : ''})`, go('resources.view', '/admin/ressources'))}
            {tile(data.content.resources_draft, 'Ressources en brouillon', go('resources.view', '/admin/ressources'), data.content.resources_draft ? 'stat-warn' : '')}
            {tile(data.content.verses_live, 'Versets affichés', go('verses.view', '/admin/versets'))}
            {tile(data.content.verses_draft, 'Versets en brouillon', go('verses.view', '/admin/versets'))}
            {tile(data.content.assignments, 'Devoirs publiés', go('assignments.publish', '/admin/devoirs-rm'))}
            {tile(data.content.announcements_30d, 'Annonces (30 jours)', go('announcements.publish', '/admin/annonces'))}
            {tile(data.content.events_upcoming, 'Événements à venir', go('events.manage', '/admin/evenements'))}
            {tile(data.content.exercises_open, 'Exercices ouverts', go('exercises.assign', '/admin/exercices'))}
          </div>
        </>
      )}

      <h2 className="pm-section">Raccourcis</h2>
      <div className="pm-shortcuts">
        {SHORTCUTS.filter((s) => hasPermission(s.perm)).map((s) => (
          <Link key={s.to} to={s.to} className="panel pm-shortcut">
            <span className="pm-shortcut-icon"><Icon path={s.icon} size={20} /></span>
            <span className="pm-shortcut-text"><strong>{s.label}</strong><small>{s.hint}</small></span>
          </Link>
        ))}
      </div>
    </AppLayout>
  )
}
