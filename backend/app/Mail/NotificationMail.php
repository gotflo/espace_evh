<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * Courriel de notification (gabarit resources/views/emails/*). Expediteur : noreply@ (courriel automatique,
 * sans adresse de reponse). Version texte jointe pour les messageries simples et la delivrabilite.
 */
class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, mixed> $data */
    public function __construct(public string $mailSubject, public string $template, public array $data) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address((string) config('mail.from.address'), (string) config('mail.from.name')),
            subject: $this->mailSubject,
        );
    }

    public function headers(): Headers
    {
        $prefs = rtrim((string) config('app.url'), '/').'/notifications';

        return new Headers(text: [
            // Lien de gestion des avis (les messageries l'affichent) : on peut couper les courriels a tout moment.
            'List-Unsubscribe' => '<'.$prefs.'>',
            // Courriel automatique (RFC 3834) : pas de reponse automatique en retour (absences, Outlook).
            'Auto-Submitted' => 'auto-generated',
            'X-Auto-Response-Suppress' => 'All',
        ]);
    }

    public function build(): static
    {
        $data = $this->data + self::common();
        $html = view('emails.'.$this->template, $data)->render();

        return $this->html($html)->text('emails.plain', ['plain' => self::plain($html)]);
    }

    /** @return array<string, string> variables communes des gabarits */
    public static function common(): array
    {
        $app = rtrim((string) config('app.url'), '/');

        return [
            'appUrl' => $app,
            'assetUrl' => rtrim((string) (config('mail.asset_url') ?: $app), '/'),
            'prefsUrl' => $app.'/notifications',
        ];
    }

    /** Version texte : le contenu du courriel, sans balises. */
    public static function plain(string $html): string
    {
        $html = preg_replace('#<(style|head)[^>]*>.*?</\1>#si', '', $html) ?? $html;
        // Texte d'apercu cache (affiche seulement a cote du sujet) : pas dans la version texte.
        $html = preg_replace('#<div style="display:none[^"]*">.*?</div>#si', '', $html) ?? $html;
        $html = preg_replace('#<a [^>]*href="([^"]+)"[^>]*>(.*?)</a>#si', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/tr|/h[1-6]|/div|/li)[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;

        return trim(preg_replace("/\n\s*\n+/", "\n\n", $text) ?? $text);
    }
}
