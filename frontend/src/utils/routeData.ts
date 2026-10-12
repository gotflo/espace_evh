// Donnees principales de chaque page du menu : lues des le survol (ordinateur) ou le toucher
// (telephone) du lien, pour que la page s'affiche complete des son ouverture.
// Les adresses sont exactement celles que lisent les pages (meme cache, aucune lecture en double).
import { warm } from '../api/client'
import { serviceSchedulePath } from './schedule'

/** Lectures des blocs de l'accueil, envoyees en un seul appel (/me/home). */
export function dashboardData(exercises = true): string[] {
  return ['/dashboard/verse', '/me/month', '/me/fiss', '/me/events?days=30', '/me/announcements', ...(exercises ? ['/me/exercises'] : []), serviceSchedulePath()]
}

const ROUTE_DATA: Record<string, string[]> = {
  '/ma-fiche': ['/me/fiss'],
  '/mes-resolutions': ['/me/resolutions'],
  '/vertumetre': ['/me/vertumetre'],
  '/mon-devoir': ['/me/assignments'],
  '/exercices': ['/me/exercises'],
  '/servir': ['/me/services'],
  '/ma-vie-spirituelle': ['/me/spiritual'],
  '/contact': ['/me/requests'],
  '/rendez-vous': ['/me/appointments'],
  '/notifications': ['/me/notifications?limit=30'],
  '/admin/gestion': ['/admin/platform/overview'],
  '/admin/membres': ['/admin/members?page=1&q='],
  '/admin/responsables': ['/admin/leaders'],
  '/admin/rapport-gem': ['/admin/gem-reports'],
  '/admin/rapports-mensuels': ['/admin/leader-reports'],
  '/admin/rendez-vous': ['/admin/appointments'],
  '/admin/validations': ['/admin/validations'],
  '/admin/demandes': ['/admin/requests'],
  '/admin/annonces': ['/admin/announcements'],
  '/admin/evenements': ['/admin/events'],
  '/admin/exercices': ['/admin/exercises?page=1&status=all'],
  '/admin/ressources': ['/admin/resolution-resources'],
  '/admin/versets': ['/admin/verses'],
  '/admin/horaires': ['/admin/service-schedule'],
  '/admin/gems': ['/admin/gems'],
  '/admin/organisation': ['/admin/organization'],
  '/admin/roles': ['/admin/roles', '/admin/permissions'],
  '/admin/reglages': ['/admin/settings'],
  '/admin/apparence': ['/admin/appearance', '/admin/settings'],
}

/** Lien survole ou touche : ses donnees sont lues en avance (une fois toutes les 20 s au plus). */
export function warmRoute(href: string) {
  for (const path of ROUTE_DATA[href.split(/[?#]/)[0]] ?? []) warm(path)
}
