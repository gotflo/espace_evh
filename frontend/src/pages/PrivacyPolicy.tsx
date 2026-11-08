import { Link } from 'react-router-dom'
import { useAuth } from '../auth/AuthContext'
import { Brand } from '../components/Brand'
import { APP_NAME } from '../config'

/** Date de la derniere mise a jour de cette politique (a changer a chaque modification du texte). */
const UPDATED = '8 novembre 2026'

/**
 * Politique de confidentialite (page publique « /confidentialite », liee depuis Parametres) : ce que l'application
 * recueille, pourquoi, qui le voit, combien de temps, et les droits de chacun.
 * Le texte decrit le fonctionnement reel de l'application : a mettre a jour si ce fonctionnement change.
 */
export default function PrivacyPolicy() {
  const { isAuthenticated } = useAuth()
  const back = isAuthenticated ? '/parametres' : '/connexion'
  return (
    <div className="legal-screen">
      <article className="card legal-card">
        <Brand subtitle={APP_NAME} />
        <h1 className="legal-title">Politique de confidentialité</h1>
        <p className="legal-updated">Dernière mise à jour : {UPDATED}</p>

        <section>
          <h2>En bref</h2>
          <ul>
            <li>L’application sert uniquement à la vie de l’église : vous accompagner, vous informer et organiser les services.</li>
            <li>Chaque responsable ne voit que les membres dont il a la charge, et seulement ce que sa fonction demande.</li>
            <li>Vos renseignements ne sont jamais vendus ni utilisés pour de la publicité.</li>
            <li>Vous pouvez consulter et corriger votre profil à tout moment, et demander la suppression de votre compte.</li>
          </ul>
        </section>

        <section>
          <h2>Les renseignements recueillis</h2>
          <ul>
            <li><strong>Votre compte :</strong> votre numéro de téléphone, qui sert à vous connecter avec un code reçu par SMS.</li>
            <li><strong>Votre profil :</strong> nom, genre, jour et mois d’anniversaire, tribu, GEM, services, et ce que vous choisissez d’ajouter (photo, adresse courriel, situation familiale, liens de famille, vie spirituelle).</li>
            <li><strong>Votre suivi spirituel :</strong> ce que vous remplissez vous-même (fiche de santé spirituelle, Vertumètre, résolutions du mois, devoirs, exercices, méditations) et ce que vos responsables notent pour vous accompagner (présences, évaluations, journal de suivi).</li>
            <li><strong>Vos échanges :</strong> vos demandes (prière, question), vos rendez-vous et vos réponses aux événements.</li>
            <li><strong>Le fonctionnement de l’application :</strong> votre dernière visite, les appareils sur lesquels vous avez accepté les notifications, et un journal des opérations importantes (qui a fait quoi, et quand) pour la sécurité.</li>
          </ul>
        </section>

        <section>
          <h2>Pourquoi</h2>
          <p>Pour vous permettre d’utiliser l’application, permettre à vos responsables de vous accompagner, vous envoyer les annonces, rappels et notifications qui vous concernent, organiser les cultes, les services et les rendez-vous, et protéger les comptes contre les abus.</p>
        </section>

        <section>
          <h2>Qui peut voir vos renseignements</h2>
          <ul>
            <li><strong>Vous</strong> voyez tout ce qui vous concerne.</li>
            <li><strong>Votre Garde</strong> suit les membres de son GEM : présences, notes et suivi qu’il rédige.</li>
            <li><strong>Le patriarche et l’Assistant Pasteur de votre tribu</strong> suivent les membres de la tribu, y compris la fiche de santé spirituelle et le Vertumètre.</li>
            <li><strong>Les responsables de vos services</strong> suivent les membres de leur département.</li>
            <li><strong>Les pasteurs</strong> (Pasteur Résident et Pasteurs Assistants) suivent l’ensemble de l’église.</li>
            <li>L’administration de la plateforme gère l’application sans accès aux renseignements confidentiels (fiches, suivi spirituel).</li>
          </ul>
          <p>Les autres membres ne voient de vous que ce qui est utile à la vie de l’église (par exemple votre nom dans un service ou un événement), jamais vos fiches ni votre suivi.</p>
        </section>

        <section>
          <h2>Combien de temps</h2>
          <p>Vos renseignements sont conservés tant que votre compte existe. Quand un compte est supprimé, la suppression est définitive : le profil et les renseignements qui y sont liés sont effacés. Restent seulement une trace de l’opération (nom et date) au journal de sécurité, et le numéro de téléphone, gardé pour empêcher une nouvelle inscription.</p>
        </section>

        <section>
          <h2>Sécurité</h2>
          <p>Connexion chiffrée (HTTPS), code à usage unique par SMS, accès limité selon la fonction de chacun et vérifié par le serveur, journal des opérations sensibles.</p>
        </section>

        <section>
          <h2>Sur votre appareil</h2>
          <p>L’application garde sur votre appareil ce qui lui permet de fonctionner : votre session, vos réglages et vos brouillons (effacés quand vous vous déconnectez). Aucun témoin publicitaire ni outil de suivi.</p>
        </section>

        <section>
          <h2>Vos droits</h2>
          <ul>
            <li>Consulter et corriger vos renseignements : dans Mon profil.</li>
            <li>Couper les notifications et les courriels : dans Paramètres et dans Mes notifications.</li>
            <li>Demander une copie de vos renseignements ou la suppression de votre compte : depuis « Nous contacter », ou auprès de votre patriarche ou d’un pasteur.</li>
          </ul>
          <p>Vous pouvez aussi vous adresser à la Commission d’accès à l’information du Québec.</p>
        </section>

        <section>
          <h2>Nous joindre</h2>
          <p>Pour toute question sur vos renseignements personnels, écrivez-nous depuis « Nous contacter » dans l’application. La personne responsable de la protection des renseignements personnels de l’église est le Pasteur Résident.</p>
        </section>

        <Link className="btn btn-ghost mt" to={back}>{isAuthenticated ? 'Retour aux paramètres' : 'Se connecter'}</Link>
      </article>
    </div>
  )
}
