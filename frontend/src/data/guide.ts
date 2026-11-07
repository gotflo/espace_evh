import type { ModuleName } from '../types'
import type { IconName } from '../utils/icons'

/**
 * Contenu du Guide (tutoriel de l'application), repris des PowerPoint « Guide du fidèle » et « Guide du Garde ».
 * Partie « tous » : chaque fidele. Partie « garde » : les Gardes, et leurs responsables (patriarche, AP, pasteurs)
 * qui les encadrent. Les captures (public/guide-captures, nom.webp et nom@2x.webp) montrent des personnes fictives.
 */

export type GuideAudience = 'tous' | 'garde'

export interface GuideStep { title: string; text: string }

export interface GuideShot { name: string; caption: string }

export interface GuideSection {
  id: string
  title: string
  intro?: string
  /** Section reservee aux Gardes dans un chapitre ouvert a tous. */
  audience?: GuideAudience
  /** Module de l'application dont depend la section (masquee s'il est desactive). */
  module?: ModuleName
  shots?: GuideShot[]
  steps?: GuideStep[]
  tips?: string[]
  /** Qui voit cette information (encadre). */
  privacy?: string
  warning?: string
  timeline?: { when: string; what: string; tone?: 'gold' | 'red' }[]
  table?: { head: [string, string]; rows: [string, string][] }
  compare?: { sees: string[]; doesNotSee: string[] }
  checklist?: { when: string; what: string }[]
  link?: { to: string; label: string }
}

export interface GuideChapter {
  id: string
  part: GuideAudience
  icon: IconName
  title: string
  summary: string
  sections: GuideSection[]
}

export interface GuideFaq { q: string; a: string; audience: GuideAudience }

export const GUIDE_CHAPTERS: GuideChapter[] = [
  // ------------------------------------------------------------------ Pour tous
  {
    id: 'inscription', part: 'tous', icon: 'profile',
    title: 'S’inscrire et se connecter',
    summary: 'Du numéro de téléphone jusqu’à votre espace : installation, code SMS, profil, validation.',
    sections: [
      {
        id: 'installer', title: 'Installer l’application',
        shots: [{ name: 'fidele-installer-banniere', caption: 'L’invitation « Installer My vasesdhonneur »' }],
        steps: [
          { title: 'Sur iPhone', text: 'Dans Safari : Partager, puis « Sur l’écran d’accueil ». Ouvrez ensuite l’application depuis son icône.' },
          { title: 'Sur Android', text: 'Touchez « Installer » dans le bandeau « Installer My vasesdhonneur ».' },
          { title: 'Plus tard, à tout moment', text: 'Menu, puis « Paramètres » : la rubrique Application montre les gestes de votre téléphone.' },
        ],
        tips: ['Quelqu’un n’y arrive pas ? Envoyez-lui le lien espace.vasesdhonneurchicoutimi.org/installer : il montre les gestes de son téléphone, même sans être connecté.'],
      },
      {
        id: 'numero', title: 'Un numéro de téléphone, un code SMS',
        shots: [
          { name: 'fidele-connexion-numero', caption: 'Écran « Connexion » : votre numéro de cellulaire' },
          { name: 'fidele-connexion-code', caption: 'Écran « Vérification » : le code reçu par SMS' },
        ],
        steps: [
          { title: 'Votre numéro de cellulaire', text: 'Aucun mot de passe : le numéro suffit.' },
          { title: 'Un code à 6 chiffres par SMS', text: 'Valable 10 minutes. La connexion se fait toute seule au 6e chiffre.' },
          { title: 'Nouveau numéro, nouveau compte', text: 'Le premier code crée votre compte, qui attend ensuite la validation du pasteur.' },
        ],
        tips: ['Le code n’arrive pas ? Touchez « Modifier le numéro », vérifiez-le, puis « Recevoir mon code » (45 secondes entre deux envois).'],
      },
      {
        id: 'profil-inscription', title: 'Compléter votre profil',
        shots: [
          { name: 'fidele-profil-rempli-haut', caption: 'Écran « Bienvenue ! » : photo, prénoms, nom, anniversaire' },
          { name: 'fidele-profil-rempli-tribu', caption: 'Le choix de la tribu et « Envoyer mon inscription »' },
        ],
        steps: [
          { title: 'Obligatoire', text: 'Prénoms, Nom, Genre et Tribu.' },
          { title: 'Conseillé', text: 'Courriel (pour être prévenu de la validation et recevoir vos rappels), photo, anniversaire (jour et mois).' },
        ],
        tips: [
          'La tribu est ensuite verrouillée. Pour en changer : une demande depuis Mon profil, validée par les responsables des deux tribus.',
          'Le GEM n’est pas choisi ici : un responsable vous rattache à un GEM après votre inscription.',
        ],
      },
      {
        id: 'validation', title: 'Le pasteur valide votre inscription',
        shots: [
          { name: 'fidele-attente-validation', caption: 'L’écran d’attente, avec le verset du moment' },
          { name: 'fidele-bienvenue', caption: 'Votre espace s’ouvre, avec un mot de bienvenue' },
        ],
        steps: [
          { title: 'En attente', text: '« Compte en attente de validation » : votre espace s’ouvrira tout seul.' },
          { title: 'Le pasteur valide', text: 'Il est prévenu de votre inscription et la valide.' },
          { title: 'Votre espace s’ouvre', text: 'Vous êtes prévenu, et un mot de bienvenue vous accueille.' },
        ],
      },
    ],
  },
  {
    id: 'espace', part: 'tous', icon: 'dashboard',
    title: 'Votre espace',
    summary: 'L’accueil, la barre du bas et le menu : pour vous repérer en un instant.',
    sections: [
      {
        id: 'accueil', title: 'Le tableau de bord',
        shots: [
          { name: 'fidele-accueil-haut', caption: 'L’accueil : le verset du moment et « Mon mois »' },
          { name: 'fidele-accueil-exercices', caption: 'Plus bas : vos exercices et « Nos rendez-vous »' },
        ],
        steps: [
          { title: 'Le verset du moment', text: 'Choisi par l’église, en haut de l’accueil.' },
          { title: 'Mon mois', text: 'Ce qu’il vous reste à faire ce mois-ci : fiche, Vertumètre, résolutions, devoir.' },
          { title: 'Mes exercices', text: 'Ce que vos responsables vous proposent, avec la date limite.' },
          { title: 'Nos rendez-vous', text: 'Les rendez-vous réguliers de l’église, jour par jour.' },
        ],
      },
      {
        id: 'accueil-garde', title: 'Votre accueil de Garde', audience: 'garde',
        shots: [{ name: 'accueil-rapport-chiffres', caption: 'Le bandeau du rapport et les chiffres du GEM' }],
        steps: [
          { title: 'Rapport de la semaine à envoyer', text: 'Bandeau réservé au Garde, avec le bouton « Remplir », tant que le rapport n’est pas envoyé.' },
          { title: 'Les chiffres de votre GEM', text: 'Fidèles, Actifs, Inactifs, Profils incomplets : touchez une tuile pour voir la liste.' },
          { title: 'Plus bas', text: 'Les nouveaux inscrits à accueillir, les événements et les rendez-vous réguliers de l’église.' },
        ],
      },
      {
        id: 'menu', title: 'Se déplacer dans l’application',
        shots: [{ name: 'fidele-menu', caption: 'Le menu, partie « Mon espace »' }],
        steps: [
          { title: 'La barre du bas', text: 'Accueil, Calendrier, Service, Alertes et Menu, toujours à portée de pouce.' },
          { title: 'Le menu · Mon espace', text: 'Vos pages : vie spirituelle, fiche, méditation, Vertumètre, RM, devoir, exercices, rendez-vous, contact et paramètres (le guide s’y trouve, rubrique Aide).' },
          { title: 'Mon profil et Déconnexion', text: 'En bas du menu, sous votre nom.' },
        ],
      },
      {
        id: 'menu-gestion', title: 'Menu · Gestion', audience: 'garde',
        shots: [{ name: 'menu-gestion', caption: 'Le menu, partie « Gestion »' }],
        steps: [
          { title: 'Vos outils de Garde', text: 'Membres, Rapport de GEM, Présences, Demandes, Exercices.' },
        ],
      },
    ],
  },
  {
    id: 'fiss', part: 'tous', icon: 'fiss',
    title: 'Ma fiche de santé spirituelle (FISS)',
    summary: 'Mon mois, votre repère, puis une fiche par mois, verrouillée après l’envoi.',
    sections: [
      {
        id: 'mon-mois', title: 'Mon mois, votre repère',
        shots: [
          { name: 'fidele-mon-mois', caption: 'En début de mois : 0 sur 4' },
          { name: 'fidele-mon-mois-3-sur-4', caption: 'Après la fiche, le Vertumètre et les résolutions : 3 sur 4' },
        ],
        steps: [
          { title: 'Sur l’accueil', text: 'Mon mois montre ce qui est fait et ce qu’il reste à faire.' },
          { title: 'L’anneau', text: 'Il se remplit à chaque étape terminée.' },
          { title: 'Touchez une ligne', text: 'Elle ouvre directement la bonne page.' },
        ],
      },
      {
        id: 'fiss-remplir', title: 'Remplir votre fiche',
        shots: [
          { name: 'fidele-fiss-a-remplir', caption: 'La fiche du mois à remplir' },
          { name: 'fidele-fiss-notes', caption: 'Vie spirituelle, sanctification, vie sociale' },
        ],
        steps: [
          { title: 'Une fiche par mois', text: 'À remplir dans le mois ; rappels dès le 8, puis les derniers jours.' },
          { title: 'Ce qu’on y note', text: 'Vie spirituelle sur 60 (méditation, prière, jeûne), sanctification, vie sociale et vie intérieure.' },
          { title: 'Gagnez du temps', text: '« Reprendre mes réponses » repart de votre fiche du mois passé : ajustez ce qui a changé.' },
        ],
        link: { to: '/ma-fiche', label: 'Ouvrir ma fiche' },
      },
      {
        id: 'fiss-enregistrer', title: 'Enregistrer votre fiche',
        shots: [
          { name: 'fidele-fiss-vie-interieure', caption: 'La vie intérieure' },
          { name: 'fidele-fiss-verrouillee', caption: 'Une fiche envoyée : verrouillée, avec son évolution' },
        ],
        steps: [
          { title: 'La vie intérieure', text: 'Chaque question demande une réponse (« Aucune » ou « Non » si rien à signaler).' },
          { title: 'Verrouillée après l’envoi', text: '« Enregistrer ma fiche » la verrouille. Une modification se demande à votre patriarche (deux demandes au plus).' },
          { title: 'Votre évolution', text: 'D’un mois à l’autre, avec ce qui a changé.' },
        ],
      },
    ],
  },
  {
    id: 'mois', part: 'tous', icon: 'target',
    title: 'Vertumètre, résolutions et devoir',
    summary: 'Le questionnaire du mois, vos RM, le devoir publié par le pasteur et les rappels.',
    sections: [
      {
        id: 'vertumetre', title: 'Mon Vertumètre', module: 'vertumetre',
        shots: [
          { name: 'fidele-vertumetre-etape1', caption: 'Étape 1 sur 3' },
          { name: 'fidele-vertumetre-confirmer', caption: 'Enregistrer, puis confirmer' },
          { name: 'fidele-vertumetre-envoye', caption: 'Le Vertumètre enregistré et verrouillé' },
        ],
        steps: [
          { title: 'Trois étapes', text: 'Prière et jeûne, méditation de la Parole, fruit de l’Esprit et caractère.' },
          { title: 'Enregistrer et confirmer', text: 'Il est alors verrouillé ; une modification se demande à votre patriarche.' },
          { title: 'Dans le mois en cours', text: 'Rappels dès le 20. Un mois passé ne se rattrape pas.' },
        ],
        link: { to: '/vertumetre', label: 'Ouvrir mon Vertumètre' },
      },
      {
        id: 'rm-bilan', title: 'Mes RM : le bilan du mois passé', module: 'resolutions',
        shots: [
          { name: 'fidele-rm-bilan', caption: 'Le bilan de chaque résolution' },
          { name: 'fidele-rm-ressources', caption: 'Les ressources pour vous aider' },
        ],
        steps: [
          { title: 'En début de mois', text: 'Rappels du 1er au 3, puis une relance du 8 au 12 si ce n’est pas fait.' },
          { title: 'Le bilan', text: 'Pour chaque résolution : Atteinte, En partie ou Pas encore. Il aide vos responsables à vous encourager.' },
          { title: 'Des ressources pour vous aider', text: 'Des vidéos par thème : méditation, prière, jeûne, culte.' },
        ],
      },
      {
        id: 'rm', title: 'Mes RM : les résolutions du mois', module: 'resolutions',
        shots: [
          { name: 'fidele-rm-saisie', caption: 'Écrire ou renouveler une résolution' },
          { name: 'fidele-rm-enregistrees', caption: 'Les résolutions enregistrées' },
        ],
        steps: [
          { title: 'Laissez-vous guider par votre fiche', text: 'Les points à fortifier sont signalés, rubrique par rubrique.' },
          { title: '« Je renouvelle »', text: 'Reprenez une résolution du mois dernier, ou écrivez-en une nouvelle. Une seule suffit pour enregistrer.' },
          { title: 'Ajustables', text: 'Vous pouvez les modifier jusqu’à la fin du mois ; le mois prochain, vous ferez le bilan.' },
        ],
        link: { to: '/mes-resolutions', label: 'Ouvrir mes RM' },
      },
      {
        id: 'devoir', title: 'Mon devoir (DS)', module: 'assignments',
        shots: [
          { name: 'fidele-devoir-video', caption: 'Le message, avec la barre de lecture' },
          { name: 'fidele-devoir-resume', caption: 'Les étapes, le résumé et vos actions' },
        ],
        steps: [
          { title: 'Publié par le pasteur', text: 'Vous êtes prévenu à la publication ; une relance arrive 3 jours après si le devoir n’est pas fait.' },
          { title: 'Quatre étapes', text: 'Regardez ou écoutez le message, résumez-le, notez vos actions, priez.' },
          { title: 'À votre rythme', text: 'La lecture reprend là où vous vous étiez arrêté. Vous pouvez enregistrer votre résumé avant la fin.' },
        ],
        link: { to: '/mon-devoir', label: 'Ouvrir mon devoir' },
      },
      {
        id: 'devoir-fait', title: 'Quand un devoir est-il « fait » ?', module: 'assignments',
        shots: [
          { name: 'fidele-devoir-resume-envoye', caption: '« Résumé envoyé · vidéo à terminer »' },
          { name: 'fidele-devoir-fait', caption: 'Le devoir fait' },
        ],
        steps: [
          { title: 'Résumé envoyé', text: '« Résumé envoyé · vidéo à terminer » : il reste à finir le message.' },
          { title: 'Jusqu’au bout', text: 'Les passages avancés rapidement ne comptent pas.' },
          { title: 'Fait', text: 'Message lu en entier et résumé envoyé : le devoir passe à « Fait ». Vous pouvez encore le modifier.' },
        ],
      },
      {
        id: 'rappels-mois', title: 'Les rappels du mois',
        timeline: [
          { when: 'Du 1er au 3', what: 'Rappel des RM : bilan et résolutions' },
          { when: 'Du 8 au 12', what: 'Relance des RM, si ce n’est pas fait' },
          { when: 'Du 8 au 14', what: 'Rappel de la fiche (FISS)' },
          { when: 'Dès le 20', what: 'Rappel du Vertumètre' },
          { when: 'Dernière semaine', what: 'Relance de la fiche' },
          { when: '2 derniers jours', what: 'Relance du Vertumètre' },
          { when: 'Fin du mois', what: 'La fiche et le Vertumètre du mois se ferment', tone: 'red' },
        ],
        tips: [
          'Dès que c’est rempli, le rappel s’arrête.',
          'Le devoir suit sa publication : prévenu le jour même, relance après 3 jours.',
        ],
      },
    ],
  },
  {
    id: 'meditation', part: 'tous', icon: 'book',
    title: 'Votre vie spirituelle',
    summary: 'Votre méditation, votre parcours, votre journal et vos exercices.',
    sections: [
      {
        id: 'meditation-remplir', title: 'Méditation',
        shots: [
          { name: 'fidele-meditation-remplir', caption: '« Remplir ma fiche » de méditation' },
          { name: 'fidele-meditation-liste', caption: 'Mes fiches de méditation' },
        ],
        steps: [
          { title: 'Cinq rubriques', text: 'Verset, observation, rhéma reçu, action du jour, prière ; puis « Valider ».' },
          { title: 'Datée du jour', text: 'Vos fiches restent dans « Mes fiches de méditation », les plus récentes en premier.' },
        ],
        link: { to: '/meditation', label: 'Ouvrir Méditation' },
      },
      {
        id: 'vie-spirituelle', title: 'Ma vie spirituelle', module: 'spiritual',
        shots: [
          { name: 'fidele-vie-indicateurs', caption: 'Vos indicateurs' },
          { name: 'fidele-vie-parcours', caption: 'Mon parcours' },
          { name: 'fidele-vie-journal', caption: 'Votre journal et vos notes' },
        ],
        steps: [
          { title: 'Vos indicateurs', text: 'Fiche, notes, assiduité, parcours.' },
          { title: 'Mon parcours', text: 'Cochez les étapes que vous avez franchies.' },
          { title: 'Votre journal', text: 'Témoignages, sujets de prière, besoins. Les entrées de vos responsables y apparaissent aussi, avec leur nom.' },
        ],
        link: { to: '/ma-vie-spirituelle', label: 'Ouvrir Ma vie spirituelle' },
      },
      {
        id: 'mes-exercices', title: 'Mes exercices', module: 'exercises',
        shots: [
          { name: 'fidele-exercices', caption: '« À faire » et « Terminés »' },
          { name: 'fidele-exercice-detail', caption: 'Un exercice vidéo' },
        ],
        steps: [
          { title: 'Proposés par vos responsables', text: 'Vidéos, lectures et méditations ; « À faire » puis « Terminés ».' },
          { title: 'Une vidéo se regarde en entier', text: 'Les passages avancés rapidement ne comptent pas.' },
          { title: 'Une date limite', text: 'Des rappels arrivent avant la fermeture ; ensuite, l’exercice n’accepte plus rien.' },
        ],
        link: { to: '/exercices', label: 'Ouvrir mes exercices' },
      },
    ],
  },
  {
    id: 'calendrier', part: 'tous', icon: 'calendar',
    title: 'La vie de l’église',
    summary: 'Le calendrier, le service, le rendez-vous avec le pasteur et le contact.',
    sections: [
      {
        id: 'calendrier-voir', title: 'Le calendrier',
        shots: [
          { name: 'fidele-calendrier', caption: 'Le calendrier en liste' },
          { name: 'fidele-calendrier-aujourdhui', caption: 'Aujourd’hui et les jours qui suivent' },
        ],
        steps: [
          { title: 'Tout ce qui est programmé', text: 'Cultes, événements, anniversaires et vos échéances.' },
          { title: 'Votre agenda', text: '« Programmer » ajoute une entrée visible par vous.' },
          { title: 'Sur votre téléphone', text: 'Abonnement possible à Google, iPhone ou Outlook, depuis « Partager ».' },
        ],
        link: { to: '/calendrier', label: 'Ouvrir le calendrier' },
      },
      {
        id: 'service', title: 'Servir dans un département',
        shots: [
          { name: 'fidele-service', caption: 'Les services disponibles' },
          { name: 'fidele-service-demande', caption: 'Envoyer votre demande' },
          { name: 'fidele-service-attente', caption: 'La demande en attente' },
        ],
        steps: [
          { title: 'Choisir un service', text: 'Onglet Service : touchez « M’inscrire ».' },
          { title: 'Envoyer votre demande', text: 'Elle part au responsable du service.' },
          { title: 'En attente', text: 'Vous êtes prévenu dès qu’elle est validée ; vous recevez ensuite les annonces du service.' },
        ],
        link: { to: '/servir', label: 'Ouvrir Service' },
      },
      {
        id: 'rendez-vous', title: 'Prendre rendez-vous avec le pasteur', module: 'appointments',
        shots: [
          { name: 'fidele-rdv-quand', caption: 'Quand ?' },
          { name: 'fidele-rdv-demande', caption: 'Votre demande' },
          { name: 'fidele-rdv-confirme', caption: 'Le rendez-vous confirmé' },
        ],
        steps: [
          { title: 'Quand ?', text: 'Après les cultes du mercredi et du dimanche ; 15 minutes.' },
          { title: 'Votre demande', text: 'Priorité et message, lu par le pasteur seulement.' },
          { title: 'Confirmé aussitôt', text: 'Présentez-vous au protocole après le culte. Vous pouvez annuler.' },
        ],
        link: { to: '/rendez-vous', label: 'Prendre rendez-vous' },
      },
      {
        id: 'contact', title: 'Nous contacter',
        shots: [
          { name: 'fidele-contact', caption: 'Nouvelle demande' },
          { name: 'fidele-contact-reponse', caption: 'La réponse, dans « Mes demandes »' },
        ],
        steps: [
          { title: 'Une demande à un responsable', text: 'Choisissez le motif, écrivez votre message, puis « Envoyer la demande ».' },
          { title: 'La réponse', text: 'Elle arrive dans « Mes demandes », et vous êtes prévenu par notification.' },
        ],
        link: { to: '/contact', label: 'Nous contacter' },
      },
    ],
  },
  {
    id: 'profil', part: 'tous', icon: 'bell',
    title: 'Mon profil et mes alertes',
    summary: 'Vos informations, vos notifications et leurs réglages.',
    sections: [
      {
        id: 'mon-profil', title: 'Garder votre profil à jour',
        shots: [
          { name: 'fidele-profil', caption: 'La carte « Complétez votre profil »' },
          { name: 'fidele-profil-famille', caption: 'L’onglet Famille' },
        ],
        steps: [
          { title: 'Quatre onglets', text: 'Identité, Famille, Infos personnelles, Vie spirituelle.' },
          { title: 'Complétez votre profil', text: 'La carte indique ce qui manque : un profil complet aide vos responsables à vous accompagner.' },
          { title: 'Changer de tribu', text: 'Une demande depuis Mon profil, validée par les responsables des deux tribus.' },
        ],
        link: { to: '/mon-profil', label: 'Ouvrir mon profil' },
      },
      {
        id: 'alertes', title: 'Vos notifications',
        shots: [
          { name: 'fidele-alertes-liste', caption: 'Vos notifications, triées par jour' },
          { name: 'fidele-alertes-choix', caption: '« Choisir mes notifications »' },
        ],
        steps: [
          { title: 'Toutes vos notifications', text: 'Triées par jour. Touchez-en une pour ouvrir la bonne page : elle passe en « lue ».' },
          { title: 'Sur votre téléphone', text: '« Activer les notifications » pour être prévenu même quand l’application est fermée.' },
          { title: 'Choisir mes notifications', text: 'Par sujet, et aussi par courriel (ici ou dans Menu, puis « Paramètres »). Les messages essentiels restent toujours actifs.' },
        ],
        link: { to: '/notifications', label: 'Ouvrir les alertes' },
      },
      {
        id: 'responsabilites-fidele', title: 'Vos responsabilités, mois après mois',
        checklist: [
          { when: 'En début de mois', what: 'Faire le bilan et prendre vos résolutions (RM).' },
          { when: 'Dans le mois', what: 'Remplir votre fiche de santé spirituelle (FISS).' },
          { when: 'Avant la fin du mois', what: 'Remplir votre Vertumètre.' },
          { when: 'À chaque devoir publié', what: 'Regarder le message, le résumer, noter vos actions, prier.' },
          { when: 'Avant la date limite', what: 'Faire les exercices proposés par vos responsables.' },
          { when: 'Au fil des jours', what: 'Noter vos méditations, garder votre profil et vos notifications à jour.' },
        ],
        tips: ['Sur l’accueil, « Mon mois » vous dit toujours ce qu’il reste à faire.'],
      },
    ],
  },

  // ------------------------------------------------------------------ Pour les Gardes
  {
    id: 'outils', part: 'garde', icon: 'roles',
    title: 'Vos outils de Garde',
    summary: 'Le menu Gestion : cinq outils, limités aux fidèles de votre GEM.',
    sections: [
      {
        id: 'outils-menu', title: 'Le menu Gestion',
        shots: [{ name: 'menu-gestion', caption: 'Le menu Gestion du Garde' }],
        steps: [
          { title: 'Membres', text: 'La liste de votre GEM et la fiche de chaque fidèle.' },
          { title: 'Rapport de GEM', text: 'Chaque semaine, pour votre patriarche et votre AP.' },
          { title: 'Présences', text: 'L’appel au culte.' },
          { title: 'Demandes', text: 'Les messages de vos fidèles.' },
          { title: 'Exercices', text: 'Ce que vous proposez à votre GEM.' },
        ],
        privacy: 'Tout est limité aux fidèles de votre GEM : vous ne voyez ni les autres GEM ni l’annuaire des responsables.',
      },
    ],
  },
  {
    id: 'membres', part: 'garde', icon: 'members',
    title: 'Les fidèles de votre GEM',
    summary: 'La liste, les onglets pour cibler et la fiche de chaque fidèle.',
    sections: [
      {
        id: 'membres-liste', title: 'La liste et la fiche',
        shots: [
          { name: 'membres', caption: 'La liste des fidèles du GEM' },
          { name: 'fiche-haut', caption: 'La fiche d’un fidèle' },
        ],
        steps: [
          { title: 'La liste de votre GEM', text: 'Vous compris ; recherche par nom ou téléphone. Point vert : actif ; gris : inactif.' },
          { title: 'Des onglets pour cibler', text: 'Tous, Actifs, Inactifs, Absents 7 j, Profil incomplet.' },
          { title: 'La fiche d’un fidèle', text: 'Téléphone, courriel, tribu, GEM, anniversaire, dernière activité.' },
        ],
        tips: [
          'Inactif : 3 mois sans connexion, fiche ni présence. Vous êtes prévenu le lundi ; il redevient actif dès qu’il revient.',
          'Vous ne pouvez pas modifier la tribu, le GEM ou le statut d’un fidèle.',
        ],
        link: { to: '/admin/membres', label: 'Ouvrir Membres' },
      },
    ],
  },
  {
    id: 'suivi', part: 'garde', icon: 'spiritual',
    title: 'Suivre un fidèle',
    summary: 'Parcours, journal de suivi et notes sur 20.',
    sections: [
      {
        id: 'suivi-fiche', title: 'Dans la fiche du fidèle',
        shots: [
          { name: 'fiche-suivi', caption: 'Suivi spirituel : étapes du parcours et journal' },
          { name: 'fiche-ajouter-note', caption: 'Notes & évaluations : ajouter une note' },
          { name: 'vie-spirituelle-journal', caption: 'Ce que voit le fidèle dans « Ma vie spirituelle »' },
        ],
        steps: [
          { title: 'Parcours et journal', text: 'Cochez les étapes franchies ; notez une visite, une prière, un besoin…, puis « Ajouter au journal ».' },
          { title: 'Notes sur 20', text: '« + Ajouter une note » : méditation de groupe, devoir, discipline… Le fidèle est prévenu.' },
          { title: 'Ce que voit le fidèle', text: 'Son journal, avec la mention « par » et votre nom.' },
        ],
        tips: ['Vous ne supprimez que vos propres entrées.'],
      },
    ],
  },
  {
    id: 'presences', part: 'garde', icon: 'attendance',
    title: 'Faire l’appel au culte',
    summary: 'Pointer les présents dans Présences.',
    sections: [
      {
        id: 'presences-appel', title: 'Présences',
        shots: [
          { name: 'presences-haut', caption: 'La date, l’événement et le compteur' },
          { name: 'presences-liste', caption: 'Cocher les présents' },
        ],
        steps: [
          { title: 'La date et l’événement', text: 'Par exemple « Culte du dimanche », le jour même ou après.' },
          { title: 'Cocher les présents', text: '« Tout présent » ou un par un, puis « Enregistrer la session ».' },
          { title: 'Les inactifs sont masqués', text: 'Quelqu’un revient ? Tapez son nom : il redevient actif.' },
          { title: 'Lien avec votre rapport', text: 'Les présents au culte du dimanche sont déjà cochés dans le rapport de la semaine.' },
        ],
        link: { to: '/admin/presences', label: 'Ouvrir Présences' },
      },
    ],
  },
  {
    id: 'rapport', part: 'garde', icon: 'gem',
    title: 'Le rapport de la semaine',
    summary: 'Chaque semaine, une minute pour dire à votre patriarche et à votre AP comment va votre GEM.',
    sections: [
      {
        id: 'rapport-remplir', title: 'Remplir le rapport',
        shots: [
          { name: 'rapport-haut', caption: 'Le rapport à envoyer' },
          { name: 'rapport-tableau', caption: 'Culte du dimanche et rencontre de la tribu' },
        ],
        steps: [
          { title: 'Ouvrir le rapport', text: 'Menu Gestion, « Rapport de GEM », ou « Remplir » depuis l’accueil.' },
          { title: 'La rencontre a eu lieu ?', text: 'Cochez ou décochez « La rencontre de la tribu a eu lieu cette semaine ».' },
          { title: 'Les présences', text: 'Pour chaque membre : culte du dimanche et rencontre de la tribu. Le culte est déjà précoché depuis Présences ; « Tous » et « Aucun » cochent une colonne d’un coup.' },
        ],
      },
      {
        id: 'rapport-envoyer', title: 'Envoyer le rapport',
        shots: [
          { name: 'rapport-envoyer', caption: 'Un mot sur la semaine, puis « Envoyer le rapport »' },
          { name: 'rapport-envoye', caption: 'Le rapport envoyé et son résumé' },
        ],
        steps: [
          { title: 'Un mot sur la semaine', text: 'Facultatif : absences à signaler, besoins, sujets de prière.' },
          { title: '« Envoyer le rapport »', text: 'Message « Rapport de la semaine envoyé. Merci ! ».' },
          { title: 'Le résumé', text: 'La pastille passe à « Envoyé », avec les présents au culte et à la rencontre.' },
        ],
      },
      {
        id: 'rapport-apres', title: 'Après l’envoi',
        shots: [
          { name: 'patriarche-notification', caption: 'Ce que reçoit votre patriarche' },
          { name: 'rapport-historique', caption: '« Corriger » et les semaines précédentes' },
        ],
        steps: [
          { title: 'Patriarche et AP prévenus', text: '« Rapport de GEM reçu », avec les chiffres de la semaine.' },
          { title: 'Corriger', text: '« Corriger », puis « Renvoyer le rapport corrigé », jusqu’au samedi suivant.' },
          { title: 'Semaines précédentes', text: 'Les 8 dernières semaines restent visibles, avec leurs chiffres.' },
        ],
      },
      {
        id: 'rapport-calendrier', title: 'Le calendrier du rapport',
        timeline: [
          { when: 'Lundi → dimanche', what: 'La semaine du rapport' },
          { when: 'Dimanche', what: 'Le rapport s’ouvre ; faites l’appel au culte' },
          { when: 'Dimanche 18 h', what: 'Rappel « Rapport de la semaine à envoyer » (ou lundi 9 h)' },
          { when: 'Mardi ou mercredi 9 h', what: 'Relance « Rappel : rapport de la semaine à envoyer »' },
          { when: 'Samedi suivant', what: 'Dernier jour pour envoyer ou corriger', tone: 'gold' },
          { when: 'Dimanche suivant', what: 'La semaine est figée : « Non reçu » si rien n’a été envoyé', tone: 'red' },
        ],
        warning: 'Une semaine manquée ne se rattrape pas : elle reste « Non reçu » sur votre fiche de Garde. Pas de rappel du jeudi au samedi.',
        link: { to: '/admin/rapport-gem', label: 'Ouvrir le rapport' },
      },
    ],
  },
  {
    id: 'accueil-nouveaux', part: 'garde', icon: 'gift',
    title: 'Accueillir les nouveaux',
    summary: 'Les nouveaux inscrits de votre GEM, sur votre accueil.',
    sections: [
      {
        id: 'nouveaux', title: 'Nouveaux inscrits',
        shots: [
          { name: 'accueil-nouveaux', caption: 'Le panneau « Nouveaux inscrits »' },
          { name: 'nouveaux-accueilli', caption: 'Après « Accueillir »' },
        ],
        steps: [
          { title: 'Nouveaux inscrits', text: 'Sur votre accueil : les arrivées des 30 derniers jours dans votre GEM.' },
          { title: 'Accueillir', text: 'Une fois la personne accueillie, touchez « Accueillir » : elle passe en « ✓ Accueilli ».' },
          { title: 'Relance', text: 'Sans accueil après 3 jours : notification « À accueillir », suivie du nom.' },
        ],
        link: { to: '/tableau-de-bord', label: 'Ouvrir l’accueil' },
      },
    ],
  },
  {
    id: 'exercices', part: 'garde', icon: 'exercises',
    title: 'Proposer un exercice à votre GEM',
    summary: 'Créer, suivre et relancer.',
    sections: [
      {
        id: 'exercices-gem', title: 'Exercices', module: 'exercises',
        shots: [
          { name: 'exercice-nouveau', caption: '« + Nouvel exercice »' },
          { name: 'exercice-suivi', caption: 'Le suivi : qui a terminé' },
          { name: 'mes-exercices-a-faire', caption: 'Côté fidèle : « Mes exercices »' },
        ],
        steps: [
          { title: 'Créer', text: '« + Nouvel exercice » : vidéo YouTube ou exercice écrit, consignes, date limite. Pour votre GEM.' },
          { title: 'Suivre', text: 'Qui a terminé, qui n’a pas commencé ; « Relancer » une fois par jour.' },
          { title: 'Côté fidèle', text: 'L’exercice arrive dans « Mes exercices », avec des rappels avant la date limite.' },
        ],
        link: { to: '/admin/exercices', label: 'Ouvrir Exercices' },
      },
    ],
  },
  {
    id: 'notifications-garde', part: 'garde', icon: 'bell',
    title: 'Vos notifications de Garde',
    summary: 'Ce que l’application vous envoie, et quand.',
    sections: [
      {
        id: 'notifications-tableau', title: 'Vos notifications',
        shots: [{ name: 'notifications-liste', caption: 'La page Alertes d’un Garde' }],
        table: {
          head: ['Notification', 'Quand'],
          rows: [
            ['Rapport de la semaine à envoyer', 'Dimanche 18 h, ou lundi 9 h'],
            ['Rappel : rapport de la semaine à envoyer', 'Mardi ou mercredi, 9 h'],
            ['À accueillir', 'Nouvel inscrit non accueilli après 3 jours'],
            ['N membre(s) devenu(s) inactif(s)', 'Chaque lundi'],
            ['Anniversaire aujourd’hui', 'Le jour même, dès 7 h'],
          ],
        },
        tips: ['Les rappels du rapport ne se coupent pas. Vous recevez aussi toutes les notifications d’un fidèle.'],
      },
    ],
  },
  {
    id: 'responsables', part: 'garde', icon: 'eye',
    title: 'Vos responsables et vous',
    summary: 'Votre fiche de Garde, et ce que le Garde voit ou ne voit pas.',
    sections: [
      {
        id: 'fiche-garde', title: 'Votre fiche de Garde',
        intro: 'Ce que votre patriarche, votre AP et les pasteurs voient de votre GEM.',
        shots: [
          { name: 'patriarche-annuaire-avant', caption: 'L’annuaire des responsables' },
          { name: 'fiche-garde-haut', caption: 'Les indicateurs du GEM' },
          { name: 'fiche-garde-rapports', caption: 'Le détail semaine après semaine' },
        ],
        steps: [
          { title: 'L’annuaire', text: 'Chaque Garde, avec « Rapport non reçu » tant que la semaine n’est pas envoyée.' },
          { title: 'Les indicateurs', text: 'Rapports envoyés, présence au culte, rencontres tenues, membres actifs, actions de suivi.' },
          { title: 'Semaine après semaine', text: 'Le détail de chaque rapport ; les semaines manquées apparaissent « Non reçu ».' },
        ],
        privacy: 'Le contenu de vos notes de suivi n’y est jamais affiché.',
      },
      {
        id: 'confidentialite', title: 'Ce que le Garde voit… et ne voit pas',
        compare: {
          sees: [
            'Les fidèles de son GEM : profil, coordonnées, famille, profil spirituel',
            'Leurs présences, leur parcours, leur journal de suivi',
            'Leurs exercices',
          ],
          doesNotSee: [
            'La fiche de santé spirituelle (FISS) de ses fidèles',
            'Leur Vertumètre, leurs RM, leurs devoirs et leurs méditations',
            'Les autres GEM et l’annuaire des responsables',
          ],
        },
        tips: ['Les rapports, les notes et les exercices sont enregistrés dans le journal de l’église, avec leur auteur et leur date.'],
      },
    ],
  },
  {
    id: 'responsabilites', part: 'garde', icon: 'validate',
    title: 'Vos responsabilités, semaine après semaine',
    summary: 'Ce que l’application attend de vous.',
    sections: [
      {
        id: 'responsabilites-liste', title: 'Vos repères',
        checklist: [
          { when: 'Chaque dimanche', what: 'Faire l’appel au culte dans Présences.' },
          { when: 'Chaque semaine, avant samedi', what: 'Envoyer le rapport de GEM.' },
          { when: 'Sous 48 heures', what: 'Répondre aux demandes de vos fidèles.' },
          { when: 'Sous 3 jours', what: 'Accueillir les nouveaux de votre GEM.' },
          { when: 'Chaque lundi', what: 'Reprendre contact avec les fidèles signalés inactifs.' },
          { when: 'Au fil des semaines', what: 'Noter vos suivis (parcours, journal, notes) et proposer des exercices.' },
        ],
        tips: ['Et chaque mois, comme chaque fidèle : votre fiche (FISS), votre Vertumètre, vos RM et votre devoir.'],
      },
    ],
  },
]

export const GUIDE_FAQ: GuideFaq[] = [
  { audience: 'tous', q: 'Le code SMS n’arrive pas', a: 'Vérifiez le numéro (un cellulaire), attendez 45 secondes et redemandez.' },
  { audience: 'tous', q: 'Je ne reçois pas les notifications', a: 'Touchez « Activer » ; sur iPhone, installez d’abord l’application.' },
  { audience: 'tous', q: 'Je me suis trompé dans ma fiche', a: '« Demander une modification » : votre patriarche la rouvre (deux demandes au plus).' },
  { audience: 'tous', q: 'Mon devoir reste « En cours »', a: 'Allez au bout du message et envoyez votre résumé : les deux sont nécessaires.' },
  { audience: 'tous', q: 'Je n’ai pas de GEM', a: 'Un responsable vous y rattache après votre inscription ; demandez à votre patriarche.' },
  { audience: 'tous', q: 'J’ai oublié mon Vertumètre', a: 'Remplissez-le avant la fin du mois : un mois passé ne se rattrape pas.' },
  { audience: 'garde', q: 'Un fidèle n’apparaît pas dans mon GEM', a: 'Demandez à votre patriarche de le rattacher à votre GEM.' },
  { audience: 'garde', q: 'Je ne vois pas la fiche (FISS) de mes fidèles', a: 'C’est normal : elle est confidentielle (patriarche, AP, pasteurs).' },
  { audience: 'garde', q: 'J’ai oublié d’envoyer mon rapport', a: 'Envoyez-le avant le samedi suivant ; après, la semaine reste « Non reçu ».' },
  { audience: 'garde', q: 'Je me suis trompé dans mon rapport', a: '« Corriger », puis « Renvoyer le rapport corrigé », jusqu’au samedi suivant.' },
]
