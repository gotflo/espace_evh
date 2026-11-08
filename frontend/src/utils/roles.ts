import type { UserRole } from '../types'

/**
 * Etiquette courte d'une fonction (badges, puces, menu) : « Respo » pour responsable, « Patri » pour patriarche,
 * choix de l'eglise pour gagner de la place. Les phrases (notifications, courriels) gardent les mots entiers.
 */
export function shortRole(label: string): string {
  return label
    .replace(/\bResponsables?\b/g, 'Respo')
    .replace(/\bresponsables?\b/g, 'respo')
    .replace(/\bPatriarches?\b/g, 'Patri')
    .replace(/\bpatriarches?\b/g, 'patri')
}

/**
 * Fonction principale affichee sous le nom (menu, Mon profil). Responsable de departement : lequel, ou combien
 * (« Respo · Chorale », « Respo de 2 départements ») plutot qu'un intitule au singulier sans precision.
 */
export function mainRoleLabel(roles: UserRole[]): string {
  // Une fonction (responsable, Garde...) passe avant le role de base de tous les membres.
  const first = roles.find((r) => r.key !== 'fidele') ?? roles[0]
  if (!first) return 'Fidèle'
  if (first.key === 'department_leader') {
    const count = roles.filter((r) => r.key === 'department_leader').length
    return count > 1 ? `Respo de ${count} départements` : `Respo · ${first.scope_name ?? 'département'}`
  }
  return shortRole(first.name)
}
