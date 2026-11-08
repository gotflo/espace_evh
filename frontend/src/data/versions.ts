/**
 * Versions de l'application, de la plus recente a la plus ancienne (Parametres, rubrique « À propos »).
 * A chaque mise en ligne qui ajoute une fonctionnalite : nouvelle version en tete, une ligne courte par ajout
 * (pas de detail, pas de simple correction). La premiere entree est la version affichee.
 */
export interface AppVersion {
  version: string
  items: string[]
}

export const APP_VERSIONS: AppVersion[] = [
  { version: '2.6', items: ['Chaque groupe séparé pour les responsables.', 'Version et politique de confidentialité.'] },
  { version: '2.5', items: ['Onglet Paramètres.', 'Votre GEM et votre Garde dans Mon profil.', 'Changement de tribu suivi par courriel.'] },
  { version: '2.4', items: ['Calendrier ouvert sur le jour en cours.', 'Vidéo de présentation.', 'Équipe du protocole.'] },
  { version: '2.3', items: ['Guide illustré de l’application.'] },
  { version: '2.2', items: ['Espace Méditation.', 'Appel des répétitions par service.'] },
  { version: '2.1', items: ['Photo de profil cadrée avant l’envoi.', 'Apparence de l’application.'] },
  { version: '2.0', items: ['Mes RM, Mon devoir (DS) et Mon Vertumètre.', 'Prise de rendez-vous.', 'Mise à jour automatique.'] },
  { version: '1.3', items: ['Rapport de GEM et rapports mensuels.', 'Annuaire des responsables.'] },
  { version: '1.2', items: ['Notifications sur le téléphone.'] },
  { version: '1.1', items: ['Calendrier et notifications.', 'Inscription aux services.', 'Famille dans le profil.', 'Exercices en vidéo, horaires des cultes et versets.', 'Validations et rapports.'] },
  { version: '1.0', items: ['Connexion par SMS et profil.', 'Ma fiche (FISS) et Ma vie spirituelle.', 'Membres, GEMs, présences, annonces, événements et exercices.', 'Nous contacter.'] },
]

/** Version en cours (la plus recente). */
export const APP_VERSION = APP_VERSIONS[0].version
