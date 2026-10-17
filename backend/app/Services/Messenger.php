<?php

namespace App\Services;

use App\Mail\NotificationMail;
use App\Models\OutboundMessage;
use App\Models\User;
use App\Services\Sms\TwilioMessagingClient;
use App\Support\Monitoring\Redactor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Courriels et textos. Chaque message passe par une file (outbound_messages) envoyee chaque minute en
 * arriere-plan : une publication pour toute l'eglise ne ralentit pas l'application, un envoi en echec
 * est reessaye (1, 5, 30 puis 120 min), et une cle anti-doublon empeche tout second envoi.
 *
 * Membre : courriel si son adresse est remplie, sinon texto (si les textos sont configures). Ses
 * preferences sont respectees (« par courriel », « par texto », et les categories coupees).
 */
class Messenger
{
    private const RETRY_MINUTES = [1, 5, 30, 120];

    private const MAX_ATTEMPTS = 5;

    /** Motif enregistre quand aucun envoi de courriel n'est configure sur le serveur. */
    public const MAIL_NOT_CONFIGURED = 'Non envoyé : aucun envoi de courriel configuré sur le serveur (MAIL_MAILER).';

    /**
     * Les courriels partent-ils vraiment ? Avec le pilote « log » ou « array », ils ne sont qu'ecrits dans un
     * journal : accepte en developpement et pendant les tests, jamais compte comme un envoi ailleurs.
     */
    public static function emailDeliverable(): bool
    {
        return ! in_array((string) config('mail.default'), ['log', 'array'], true) || app()->environment('local', 'testing');
    }

    // ---------------------------------------------------------------- Destinataires

    public static function emailOf(User $user): ?string
    {
        $email = trim((string) ($user->profile?->email ?? ''));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? mb_strtolower($email) : null;
    }

    /** La personne accepte-t-elle ce canal (et cette categorie d'avis) ? */
    public static function wants(User $user, string $channel, ?string $category): bool
    {
        $prefs = is_array($user->notification_prefs) ? $user->notification_prefs : [];
        if (($prefs['channel_'.$channel] ?? true) === false) {
            return false;
        }

        return $category === null || ($prefs[$category] ?? true) !== false;
    }

    /**
     * Membre : courriel si l'adresse est remplie (et acceptee), sinon texto. Renvoie le canal retenu.
     *
     * @param  array{subject: string, view: string, data: array<string, mixed>}  $mail
     */
    public static function toMember(User $user, string $kind, string $dedupe, array $mail, ?string $sms, ?string $category = null): ?string
    {
        $email = self::emailOf($user);
        if ($email && self::wants($user, 'email', $category)) {
            return self::queueEmail($email, $user->id, $kind, $mail['subject'], $mail['view'], $mail['data'], $dedupe) ? 'email' : null;
        }
        if ($sms !== null && $user->phone && TwilioMessagingClient::enabled() && self::wants($user, 'sms', $category)) {
            return self::queueSms($user->phone, $user->id, $kind, $sms, $dedupe) ? 'sms' : null;
        }

        return null;
    }

    // ---------------------------------------------------------------- File

    /** @param array<string, mixed> $data */
    public static function queueEmail(string $to, ?int $userId, string $kind, string $subject, string $view, array $data, ?string $dedupe): ?OutboundMessage
    {
        return self::queue(['channel' => 'email', 'recipient' => $to, 'user_id' => $userId, 'kind' => $kind,
            'subject' => mb_substr($subject, 0, 200), 'view' => $view, 'payload' => $data, 'dedupe_key' => $dedupe]);
    }

    public static function queueSms(string $to, ?int $userId, string $kind, string $text, ?string $dedupe): ?OutboundMessage
    {
        return self::queue(['channel' => 'sms', 'recipient' => $to, 'user_id' => $userId, 'kind' => $kind,
            'payload' => ['text' => self::smsText($text)], 'dedupe_key' => $dedupe, 'available_at' => self::smsAllowedAt(now())]);
    }

    /** @param array<string, mixed> $row */
    private static function queue(array $row): ?OutboundMessage
    {
        if ($row['dedupe_key'] && OutboundMessage::where('dedupe_key', $row['dedupe_key'])->exists()) {
            return null;
        }
        try {
            return OutboundMessage::create($row + ['status' => 'pending', 'available_at' => $row['available_at'] ?? now()]);
        } catch (UniqueConstraintViolationException) {
            return null; // deja en file (deux passages simultanes)
        }
    }

    /**
     * Envoie ce qui est du (courriels et textos), dans la limite par minute de l'hebergeur.
     *
     * @return array{sent: int, failed: int}
     */
    public static function flush(?Carbon $now = null): array
    {
        $now ??= now();
        // Envoi interrompu (coupure du serveur) : de nouveau en file apres 10 min.
        OutboundMessage::where('status', 'sending')->where('updated_at', '<', $now->copy()->subMinutes(10))->update(['status' => 'pending']);

        $sent = 0;
        $failed = 0;
        foreach (['email' => (int) config('mail.per_minute', 20), 'sms' => (int) config('services.sms.per_minute', 30)] as $channel => $limit) {
            $due = OutboundMessage::where('channel', $channel)->where('status', 'pending')->where('available_at', '<=', $now)
                ->orderBy('available_at')->orderBy('id')->limit(max(1, $limit))->get();
            // Courriels sans envoi configure : marques « non envoyes » (jamais « envoyes »), sans tentative.
            if ($channel === 'email' && ! self::emailDeliverable() && $due->isNotEmpty()) {
                $failed += OutboundMessage::whereIn('id', $due->pluck('id'))->where('status', 'pending')
                    ->update(['status' => 'failed', 'last_error' => self::MAIL_NOT_CONFIGURED, 'updated_at' => $now]);
                Log::warning('Courriels non envoyés : aucun envoi configuré (MAIL_MAILER)', ['count' => $due->count()]);

                continue;
            }
            foreach ($due as $message) {
                // Reserve le message (un seul envoi meme si deux taches tournent en meme temps). Heure reelle de
                // la reservation : un passage long ne remet pas en file un message en cours d'envoi.
                $claimed = OutboundMessage::where('id', $message->id)->where('status', 'pending')
                    ->update(['status' => 'sending', 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
                if ($claimed !== 1) {
                    continue;
                }
                $message->refresh();
                if ($channel === 'sms' && ! self::smsAllowedAt($now)->lte($now)) {
                    // Heure calme (nuit) : repousse sans compter d'essai.
                    $message->forceFill(['status' => 'pending', 'attempts' => max(0, $message->attempts - 1), 'available_at' => self::smsAllowedAt($now)])->save();

                    continue;
                }
                try {
                    $channel === 'email' ? self::deliverEmail($message) : self::deliverSms($message);
                    $message->forceFill(['status' => 'sent', 'sent_at' => now(), 'last_error' => null])->save();
                    $sent++;
                } catch (\Throwable $e) {
                    $final = $message->attempts >= self::MAX_ATTEMPTS;
                    $message->forceFill([
                        'status' => $final ? 'failed' : 'pending',
                        'available_at' => $now->copy()->addMinutes(self::RETRY_MINUTES[min($message->attempts - 1, count(self::RETRY_MINUTES) - 1)]),
                        // Message d'erreur du fournisseur masque (il peut contenir le numero ou l'adresse complets).
                        'last_error' => Redactor::text($e->getMessage(), 500),
                    ])->save();
                    $final ? $failed++ : null;
                    Log::warning('Envoi '.$channel.' impossible', ['id' => $message->id, 'kind' => $message->kind, 'attempt' => $message->attempts, 'error' => Redactor::text($e->getMessage(), 300)]);
                    // Serveur d'envoi injoignable ou refus : on s'arrete pour ce passage (les suivants
                    // attendraient chacun le delai maximal) ; le reste repart au passage suivant.
                    break;
                }
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private static function deliverEmail(OutboundMessage $message): void
    {
        $to = $message->recipient;
        $subject = (string) $message->subject;
        if (config('mail.test_banner')) {
            $subject = '[TEST] '.$subject;
        }
        // Essais : tout part vers l'adresse de test, le destinataire d'origine est rappele dans le sujet.
        if ($test = config('mail.test_to')) {
            $subject = "[Essai → {$to}] {$subject}";
            $to = $test;
        }
        Mail::to($to)->send(new NotificationMail($subject, (string) $message->view, $message->payload ?? []));
    }

    private static function deliverSms(OutboundMessage $message): void
    {
        $to = config('services.sms.test_to') ?: $message->recipient;
        (new TwilioMessagingClient)->send($to, (string) ($message->payload['text'] ?? ''));
    }

    // ---------------------------------------------------------------- Textos

    /**
     * Texte d'un texto : caracteres de l'alphabet SMS standard seulement (un « ê », un « ç » ou des
     * guillemets « » font passer le message en Unicode : 70 caracteres au lieu de 160, cout double),
     * et un seul texto (160 caracteres).
     */
    public static function smsText(string $text): string
    {
        $text = strtr($text, [
            'ê' => 'e', 'ë' => 'e', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'œ' => 'oe', 'Œ' => 'OE', 'Ê' => 'E', 'È' => 'E', 'Â' => 'A', 'À' => 'A', 'Î' => 'I', 'Ô' => 'O', 'Û' => 'U',
            '« ' => '"', ' »' => '"', '« ' => '"', ' »' => '"', '«' => '"', '»' => '"', '“' => '"', '”' => '"', '’' => "'", '‘' => "'", '–' => '-', '—' => '-', '…' => '...', "\u{00A0}" => ' ', "\u{202F}" => ' ',
        ]);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if (mb_strlen($text) <= 160) {
            return $text;
        }
        // Trop long : on raccourcit la phrase, jamais le lien final (un lien coupe ne mene nulle part).
        if (preg_match('#^(.*?)\s*(https?://\S+)$#u', $text, $m) && mb_strlen($m[2]) < 120) {
            $room = 160 - mb_strlen($m[2]) - 4;

            return rtrim(mb_substr($m[1], 0, $room)).'... '.$m[2];
        }

        return mb_substr($text, 0, 157).'...';
    }

    /** Premier moment autorise pour un texto : pas la nuit (20 h 30 - 9 h). */
    public static function smsAllowedAt(Carbon $at): Carbon
    {
        $until = Carbon::parse($at->toDateString().' '.config('services.sms.quiet_until', '09:00'));
        $from = Carbon::parse($at->toDateString().' '.config('services.sms.quiet_from', '20:30'));
        if ($at->lt($until)) {
            return $until;
        }
        if ($at->gte($from)) {
            return $until->addDay();
        }

        return $at->copy();
    }
}
