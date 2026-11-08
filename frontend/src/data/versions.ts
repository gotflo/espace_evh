/**
 * Nouveautes de l'application, de la plus recente a la plus ancienne (Parametres, rubrique « À propos »).
 * A completer a chaque mise en ligne : seulement les fonctionnalites ajoutees, une ligne courte chacune.
 */
export interface AppVersion {
  /** Jour de la mise en ligne (AAAA-MM-JJ) */
  date: string
  title: string
  items: string[]
}

export const APP_VERSIONS: AppVersion[] = [
  {
    date: '2026-11-08',
    title: 'Chaque groupe à sa place',
    items: [
      'Responsables de plusieurs groupes : chaque service séparé.',
      'Version de l’application et politique de confidentialité dans Paramètres.',
    ],
  },
  {
    date: '2026-11-07',
    title: 'Paramètres',
    items: [
      'Onglet Paramètres pour tous.',
      'Votre GEM et votre Garde dans Mon profil.',
      'Changement de tribu suivi par courriel.',
    ],
  },
  {
    date: '2026-10-31',
    title: 'Calendrier et vidéo',
    items: ['Calendrier ouvert sur le jour en cours.', 'Vidéo de présentation dans le Guide.'],
  },
  {
    date: '2026-10-18',
    title: 'Guide',
    items: ['Guide illustré de l’application.'],
  },
  {
    date: '2026-10-17',
    title: 'Méditation',
    items: ['Espace Méditation.', 'Appel des répétitions par service.'],
  },
  {
    date: '2026-10-12',
    title: 'Photos',
    items: ['Photo de profil cadrée avant l’envoi.'],
  },
  {
    date: '2026-10-10',
    title: 'Mise à jour d’octobre',
    items: ['Mes RM, Mon devoir (DS) et Mon Vertumètre.', 'Prise de rendez-vous.'],
  },
]
