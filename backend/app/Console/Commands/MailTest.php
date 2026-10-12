<?php

namespace App\Console\Commands;

use App\Mail\NotificationMail;
use App\Services\Messenger;
use App\Services\Sms\TwilioMessagingClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/**
 * Essai d'envoi : un exemple de chaque courriel (rendez-vous du jour, demande de suppression, devoir,
 * nouvelle inscription et sa reponse, absence d'une semaine,
 * FISS, rapport) vers une adresse, envoye tout de suite. Affiche la configuration (sans secret) et
 * l'erreur exacte du serveur de courriel en cas d'echec.
 */
class MailTest extends Command
{
    protected $signature = 'app:mail-test {to : adresse qui recoit les exemples} {--sms= : numero (+1...) pour un texto d\'essai}';

    protected $description = 'Envoie un exemple de chaque courriel (et un texto d\'essai) pour verifier la configuration.';

    public function handle(): int
    {
        $to = (string) $this->argument('to');
        $this->line('Serveur : '.config('mail.default').' · '.config('mail.mailers.smtp.host').':'.config('mail.mailers.smtp.port')
            .' · identifiant '.(config('mail.mailers.smtp.username') ?: '(aucun)').' · mot de passe '.(config('mail.mailers.smtp.password') ? 'présent' : 'absent'));
        $this->line('Expéditeur : '.config('mail.from.name').' <'.config('mail.from.address').'> · sans adresse de réponse (noreply)');
        $this->line('Textos de notification : '.(TwilioMessagingClient::enabled() ? 'activés' : 'désactivés (TWILIO_SMS_FROM absent)'));
        if (! Messenger::emailDeliverable()) {
            $this->error('Aucun envoi de courriel configuré (MAIL_MAILER='.config('mail.default').') : rien ne partirait. Renseignez MAIL_MAILER=smtp et le serveur SMTP dans .env.');

            return self::FAILURE;
        }

        $app = rtrim((string) config('app.url'), '/');
        $people = [
            ['time' => '21h50', 'name' => 'Marie Tremblay', 'tribe' => 'Juda', 'phone' => '+14185550123', 'urgency' => 'urgent', 'urgency_label' => 'Urgente', 'message' => 'Je souhaite parler d’une situation familiale.'],
            ['time' => '22h05', 'name' => 'Jean Dupont', 'tribe' => 'Ruben', 'phone' => '+14185550203', 'urgency' => 'normal', 'urgency_label' => 'Normale', 'message' => null],
            ['time' => '22h20', 'name' => 'Sarah Nguema', 'tribe' => 'Benjamin', 'phone' => '+14185550300', 'urgency' => 'important', 'urgency_label' => 'Importante', 'message' => 'Engagement au service.'],
            ['time' => '22h35', 'name' => 'Adams Kouassi', 'tribe' => 'Ruben', 'phone' => '+14185550411', 'urgency' => 'normal', 'urgency_label' => 'Normale', 'message' => null],
            ['time' => '22h50', 'name' => 'Esther Mbuyi', 'tribe' => 'Juda', 'phone' => '+14185550512', 'urgency' => 'normal', 'urgency_label' => 'Normale', 'message' => null],
            ['time' => '23h05', 'name' => 'Paul Lavoie', 'tribe' => 'Lévi', 'phone' => '+14185550613', 'urgency' => 'normal', 'urgency_label' => 'Normale', 'message' => null],
        ];
        $digest = ['dayLabel' => 'mercredi 14 octobre', 'dayTitle' => 'de ce soir', 'range' => '21h50 – 23h20', 'minutes' => 15, 'adminUrl' => $app.'/admin/rendez-vous'];
        $samples = [
            ['[Exemple 1/12] Vos rendez-vous de ce soir · mercredi 14 octobre (6)', 'appointments-digest', $digest + [
                'reason' => 'full', 'update' => false, 'items' => $people, 'total' => 6, 'free' => 0]],
            ['[Exemple 2/12] Liste mise à jour · Vos rendez-vous de ce soir · mercredi 14 octobre (5)', 'appointments-digest', $digest + [
                'reason' => 'deadline', 'update' => true, 'items' => array_slice($people, 0, 5), 'total' => 6, 'free' => 1]],
            ['[Exemple 3/12] Demande de suppression : Jean Dupont', 'deletion-request', [
                'memberName' => 'Jean Dupont', 'tribe' => 'Ruben', 'phone' => '+14185550203', 'requestedBy' => 'Adams Kouassi',
                'reason' => 'Exemple : ne fréquente plus l’église depuis un an.', 'impact' => [['label' => 'Profil (identité, appartenance)', 'count' => 1]],
                'approveUrl' => $app, 'rejectUrl' => $app, 'days' => 14]],
            ['[Exemple 4/12] Nouveau devoir : La foi qui agit', 'assignment', [
                'firstName' => 'Marie', 'title' => 'La foi qui agit', 'speaker' => 'Pst Abraham Andebi', 'typeLabel' => 'Vidéo', 'typeVerb' => 'la regarder',
                'instructions' => 'Regardez la prédication jusqu’au bout, puis notez trois actions concrètes pour la semaine.', 'url' => $app.'/mon-devoir']],
            ['[Exemple 5/12] Votre fiche de santé spirituelle d’octobre', 'fiss-reminder', [
                'firstName' => 'Marie', 'stage' => 'first', 'deMonth' => 'd’octobre', 'daysLeft' => 25, 'url' => $app.'/ma-fiche']],
            ['[Exemple 6/12] Rappel : votre fiche de santé spirituelle d’octobre', 'fiss-reminder', [
                'firstName' => 'Marie', 'stage' => 'last', 'deMonth' => 'd’octobre', 'daysLeft' => 4, 'url' => $app.'/ma-fiche']],
            ['[Exemple 7/12] Rapport de septembre à transmettre · Tribu Juda', 'report-reminder', [
                'firstName' => 'Adams', 'stage' => 'first', 'deMonth' => 'de septembre', 'scopeLabel' => 'tribu Juda', 'scopeOf' => 'de la', 'closesOn' => 'samedi 31 octobre', 'url' => $app.'/admin/rapports-mensuels']],
            ['[Exemple 8/12] Rappel : rapport de septembre à transmettre · Département Chorale', 'report-reminder', [
                'firstName' => 'Esther', 'stage' => 'last', 'deMonth' => 'de septembre', 'scopeLabel' => 'département Chorale', 'scopeOf' => 'du', 'closesOn' => 'samedi 31 octobre', 'url' => $app.'/admin/rapports-mensuels']],
            ['[Exemple 9/12] Nouveau membre à valider : Degaulle Kaswende', 'membership-request', [
                'pastorName' => 'Abraham Andebi', 'memberName' => 'Degaulle Kaswende', 'photoUrl' => null, 'initials' => 'DK',
                'details' => [
                    ['label' => 'Prénoms', 'value' => 'Degaulle'], ['label' => 'Nom', 'value' => 'Kaswende'], ['label' => 'Genre', 'value' => 'Homme'],
                    ['label' => 'Anniversaire', 'value' => '15 mai'], ['label' => 'Téléphone', 'value' => '+14185550777'],
                    ['label' => 'Courriel', 'value' => 'degaulle.exemple@gmail.com'], ['label' => 'Tribu', 'value' => 'Juda'],
                    ['label' => 'Départements', 'value' => 'Chorale, Accueil'], ['label' => 'Matricule', 'value' => 'VHCKD1505'],
                    ['label' => 'Demande envoyée', 'value' => 'vendredi 9 octobre à 14h05'],
                ],
                'approveUrl' => $app, 'rejectUrl' => $app, 'days' => 30]],
            ['[Exemple 10/12] Votre compte My vasesdhonneur est validé', 'membership-approved', ['firstName' => 'Degaulle', 'url' => $app.'/tableau-de-bord']],
            ['[Exemple 11/12] Votre demande d’inscription à My vasesdhonneur', 'membership-rejected', ['firstName' => 'Degaulle', 'url' => $app]],
            ['[Exemple 12/12] Votre espace My vasesdhonneur vous attend', 'absence-reminder', [
                'firstName' => 'Marie', 'days' => 9, 'connected' => 'connectée', 'url' => $app.'/tableau-de-bord',
                'items' => ['1 devoir à faire', '2 exercices pour vous', '3 nouvelles annonces', '5 notifications non lues']]],
        ];

        foreach ($samples as [$subject, $view, $data]) {
            try {
                Mail::to($to)->send(new NotificationMail($subject, $view, $data));
                $this->info('Envoyé : '.$subject);
            } catch (\Throwable $e) {
                $this->error('Échec : '.$subject.' → '.mb_substr($e->getMessage(), 0, 300));

                return self::FAILURE;
            }
        }

        if ($phone = $this->option('sms')) {
            if (! TwilioMessagingClient::enabled()) {
                $this->warn('Texto non envoyé : les textos de notification ne sont pas configurés (TWILIO_SMS_FROM).');
            } else {
                try {
                    (new TwilioMessagingClient)->send($phone, Messenger::smsText('My vasesdhonneur : ceci est un texto d\'essai des notifications.'));
                    $this->info('Texto d\'essai envoyé au '.$phone);
                } catch (\Throwable $e) {
                    $this->error('Texto en échec : '.$e->getMessage());

                    return self::FAILURE;
                }
            }
        }

        return self::SUCCESS;
    }
}
