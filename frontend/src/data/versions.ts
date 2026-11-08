/**
 * Versions de l'application, de la plus recente a la plus ancienne (Parametres, rubrique « À propos »).
 * A chaque mise en ligne qui ajoute une fonctionnalite : nouvelle version en tete, une ligne courte par ajout
 * (pas de detail, pas de simple correction). La premiere entree est la version affichee.
 * Une ligne qui ne concerne qu'une fonction (gestion, validations, reglages...) porte les permissions qui la
 * rendent visible : un fidele ne voit que ce qui le concerne.
 */
export type VersionItem = string | { text: string; perms: string[] }

export interface AppVersion {
  version: string
  items: VersionItem[]
}

const LEADERS = ['members.view_scope', 'members.view_all']
const VALIDATORS = ['fiss.review', 'tribes.transfer', 'members.approve', 'departments.approve']

export const APP_VERSIONS: AppVersion[] = [
  { version: '2.7', items: [{ text: 'Demandes à valider signalées sur l’accueil et dans le menu.', perms: VALIDATORS }] },
  { version: '2.6', items: [{ text: 'Chaque groupe séparé pour les responsables.', perms: LEADERS }, 'Version et politique de confidentialité.'] },
  { version: '2.5', items: ['Onglet Paramètres.', 'Votre GEM et votre Garde dans Mon profil.', 'Changement de tribu suivi par courriel.'] },
  { version: '2.4', items: ['Calendrier ouvert sur le jour en cours.', 'Vidéo de présentation.', { text: 'Équipe du protocole.', perms: ['appointments.organize', 'appointments.host'] }] },
  { version: '2.3', items: ['Guide illustré de l’application.'] },
  { version: '2.2', items: ['Espace Méditation.', { text: 'Appel des répétitions par service.', perms: ['attendance.record'] }] },
  { version: '2.1', items: ['Photo de profil cadrée avant l’envoi.', { text: 'Apparence de l’application.', perms: ['settings.manage'] }] },
  { version: '2.0', items: ['Mes RM, Mon devoir (DS) et Mon Vertumètre.', 'Prise de rendez-vous.', 'Mise à jour automatique.'] },
  { version: '1.3', items: [{ text: 'Rapport de GEM, rapports mensuels et annuaire des responsables.', perms: LEADERS }] },
  { version: '1.2', items: ['Notifications sur le téléphone.'] },
  { version: '1.1', items: ['Calendrier et notifications.', 'Inscription aux services.', 'Famille dans le profil.', 'Exercices en vidéo, horaires des cultes et versets.',
    { text: 'Validations et rapports.', perms: [...VALIDATORS, 'reports.view'] }] },
  { version: '1.0', items: ['Connexion par SMS et profil.', 'Ma fiche (FISS) et Ma vie spirituelle.', 'Annonces et événements.', 'Nous contacter.',
    { text: 'Gestion des membres, GEMs, présences et exercices.', perms: LEADERS }] },
]

/** Version en cours (la plus recente), la meme pour tous. */
export const APP_VERSION = APP_VERSIONS[0].version

/** Lignes que cette personne peut voir, version par version (les versions sans ligne pour elle sont omises). */
export function versionsFor(hasPermission: (key: string) => boolean): { version: string; items: string[] }[] {
  return APP_VERSIONS
    .map((v) => ({
      version: v.version,
      items: v.items.filter((i) => typeof i === 'string' || i.perms.some((p) => hasPermission(p))).map((i) => (typeof i === 'string' ? i : i.text)),
    }))
    .filter((v) => v.items.length > 0)
}
