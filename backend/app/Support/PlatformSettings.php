<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Reglages de la plateforme : source unique (libelle, aide, type, bornes, valeur par defaut).
 *
 * Chaque reglage est reellement branche dans le code (automatismes, rappels, limites) ; une valeur
 * absente de la table vaut sa valeur par defaut, qui est exactement le comportement d'avant les
 * reglages. Les reglages touchant a la securite (validation des inscriptions, codes SMS, droits)
 * n'en font volontairement pas partie.
 */
class PlatformSettings
{
    public const CACHE_KEY = 'platform:settings';

    /**
     * groupe => [titre, description, [cle => definition]].
     * definition : type (bool|int|text), default, label, help, pour int : min, max, unit ; pour text : max
     * (longueur), lines (zone de plusieurs lignes) et le texte d'origine en valeur par defaut.
     * « step » : etape de app:tick que le reglage active ou suspend.
     */
    public const GROUPS = [
        'modules' => ['Modules de la plateforme', 'Un module désactivé disparaît des menus et de l’accueil, et ses rappels s’arrêtent. Rien n’est effacé : tout réapparaît à la réactivation.', [
            'module.vertumetre' => ['type' => 'bool', 'default' => true, 'label' => 'Vertumètre',
                'help' => 'Évaluation mensuelle des vertus, avec suivi par le patriarche.'],
            'module.resolutions' => ['type' => 'bool', 'default' => true, 'label' => 'Résolutions du mois (RM)',
                'help' => 'Résolutions des membres, bilan du mois et ressources pour les aider.'],
            'module.assignments' => ['type' => 'bool', 'default' => true, 'label' => 'Devoirs (DS)',
                'help' => 'Devoir de la semaine (vidéo ou audio) et résumé des membres.'],
            'module.exercises' => ['type' => 'bool', 'default' => true, 'label' => 'Exercices',
                'help' => 'Exercices confiés aux membres (lecture, vidéo, questions).'],
            'module.appointments' => ['type' => 'bool', 'default' => true, 'label' => 'Rendez-vous avec le pasteur',
                'help' => 'Prise de rendez-vous par les membres et organisation par le protocole.'],
            'module.spiritual' => ['type' => 'bool', 'default' => true, 'label' => 'Ma vie spirituelle',
                'help' => 'Journal personnel du membre (témoignages, étapes de la foi).'],
        ]],
        'membres' => ['Rappels aux membres', 'Messages envoyés automatiquement aux fidèles (application, et courriel quand il est prévu).', [
            'automation.fiss' => ['type' => 'bool', 'default' => true, 'step' => 'fiss', 'label' => 'Fiche de santé spirituelle (FISS)',
                'help' => 'Rappels pour remplir la FISS du mois, puis relance avant la fin du mois.'],
            'automation.vertumetre' => ['type' => 'bool', 'default' => true, 'step' => 'vertumetre', 'module' => 'module.vertumetre', 'label' => 'Vertumètre',
                'help' => 'Rappel en fin de mois pour remplir le Vertumètre.'],
            'automation.resolutions' => ['type' => 'bool', 'default' => true, 'step' => 'resolutions', 'module' => 'module.resolutions', 'label' => 'Résolutions du mois (RM)',
                'help' => 'Invitation en début de mois, puis relance vers le 10.'],
            'automation.assignments' => ['type' => 'bool', 'default' => true, 'step' => 'assignments', 'module' => 'module.assignments', 'label' => 'Devoirs (DS)',
                'help' => 'Rappel quelques jours après la publication d’un devoir à ceux qui ne l’ont pas fait.'],
            'automation.tasks' => ['type' => 'bool', 'default' => true, 'step' => 'tasks', 'module' => 'module.exercises', 'label' => 'Exercices',
                'help' => 'Rappels avant la clôture d’un exercice, puis avis de clôture.'],
            'automation.event_reminders' => ['type' => 'bool', 'default' => true, 'step' => 'event-reminders', 'label' => 'Événements et cultes',
                'help' => 'Rappel la veille et peu avant chaque événement ou culte.'],
            'automation.service_digest' => ['type' => 'bool', 'default' => true, 'step' => 'service-digest', 'label' => 'Programme du lendemain',
                'help' => 'La veille au soir, le programme des cultes et rendez-vous du lendemain.'],
            'automation.birthdays' => ['type' => 'bool', 'default' => true, 'step' => 'birthdays', 'label' => 'Anniversaires',
                'help' => 'Vœux le jour de l’anniversaire.'],
            'automation.weddings' => ['type' => 'bool', 'default' => true, 'step' => 'weddings', 'label' => 'Anniversaires de mariage',
                'help' => 'Vœux le jour de l’anniversaire de mariage.'],
            'automation.profiles' => ['type' => 'bool', 'default' => true, 'step' => 'profiles', 'label' => 'Profil incomplet',
                'help' => 'Rappel aux membres dont le profil n’est pas terminé.'],
            'automation.email_invites' => ['type' => 'bool', 'default' => true, 'step' => 'email-invites', 'label' => 'Ajout d’un courriel',
                'help' => 'Invitation à ajouter une adresse courriel, puis un seul rappel trois semaines plus tard.'],
            'automation.absences' => ['type' => 'bool', 'default' => true, 'step' => 'absences', 'label' => 'Membres absents',
                'help' => 'Après quelques jours sans ouvrir l’application : un message au membre, la liste à son patriarche et à son AP.'],
        ]],
        'aide' => ['Ce que la plateforme fait pour les membres', 'Des tâches faites automatiquement, pour ne pas surcharger les fidèles ni leurs responsables.', [
            'automation.rm_auto_renew' => ['type' => 'bool', 'default' => false, 'step' => 'rm-renew', 'module' => 'module.resolutions', 'label' => 'Renouveler les RM oubliées',
                'help' => 'Le 15 du mois, les résolutions du mois précédent sont reprises pour ceux qui n’ont rien écrit ; ils en sont prévenus et peuvent les modifier.'],
            'services.reminder_after_days' => ['type' => 'int', 'default' => 2, 'min' => 1, 'max' => 14, 'unit' => 'jours',
                'label' => 'Relance des inscriptions aux services', 'help' => 'Une demande d’inscription à un service sans réponse est rappelée à son responsable après ce délai.'],
            'services.auto_approve_after_days' => ['type' => 'int', 'default' => 0, 'min' => 0, 'max' => 30, 'unit' => 'jours',
                'label' => 'Validation automatique des inscriptions aux services', 'help' => 'Sans réponse du responsable après ce délai, la demande est acceptée et chacun est prévenu. 0 = jamais.'],
            'notifications.prompt_snooze_days' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 14, 'unit' => 'jours',
                'label' => 'Nouvelle invitation à activer les notifications', 'help' => 'Un membre sans notifications sur son téléphone est réinvité (un seul clic suffit) après ce délai s’il a répondu « Plus tard ».'],
        ]],
        'horaires' => ['Horaires et délais', 'Quand les messages partent.', [
            'reminders.service_lead_minutes' => ['type' => 'int', 'default' => 35, 'min' => 15, 'max' => 120, 'unit' => 'minutes',
                'label' => 'Rappel avant chaque culte', 'help' => 'Combien de temps avant le début d’un culte régulier le rappel est envoyé.'],
            'reminders.digest_hour' => ['type' => 'int', 'default' => 18, 'min' => 16, 'max' => 21, 'unit' => 'h',
                'label' => 'Programme du lendemain envoyé à partir de', 'help' => 'Heure à partir de laquelle part le programme du lendemain.'],
            'fiss.reminder_from_day' => ['type' => 'int', 'default' => 8, 'min' => 1, 'max' => 20, 'unit' => 'du mois',
                'label' => 'Premier rappel de la FISS', 'help' => 'Jour du mois à partir duquel le rappel de la FISS est envoyé (relance la dernière semaine).'],
            'assignments.reminder_after_days' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 7, 'unit' => 'jours',
                'label' => 'Rappel du devoir (DS)', 'help' => 'Délai après la publication d’un devoir avant le rappel à ceux qui ne l’ont pas fait.'],
            'leader_reports.opens_on_day' => ['type' => 'int', 'default' => 25, 'min' => 20, 'max' => 28, 'unit' => 'du mois',
                'label' => 'Ouverture du rapport mensuel des responsables', 'help' => 'Jour à partir duquel le rapport du mois en cours peut être rempli.'],
        ]],
        'suivi' => ['Suivi des membres', 'Quand un membre est considéré absent ou inactif, et quand les responsables sont relancés.', [
            'absences.after_days' => ['type' => 'int', 'default' => 7, 'min' => 3, 'max' => 21, 'unit' => 'jours',
                'label' => 'Membre absent après', 'help' => 'Jours sans ouvrir l’application avant le message au membre et la liste à ses responsables.'],
            'members.inactive_after_months' => ['type' => 'int', 'default' => 3, 'min' => 2, 'max' => 12, 'unit' => 'mois',
                'label' => 'Membre inactif après', 'help' => 'Sans connexion, FISS ni présence pendant ce temps, le membre passe « inactif ».'],
            'followups.welcome_after_days' => ['type' => 'int', 'default' => 3, 'min' => 1, 'max' => 14, 'unit' => 'jours',
                'label' => 'Relance pour accueillir un nouvel inscrit', 'help' => 'Délai avant de rappeler à ses responsables un nouvel inscrit pas encore accueilli.'],
            'followups.request_after_hours' => ['type' => 'int', 'default' => 48, 'min' => 12, 'max' => 168, 'unit' => 'heures',
                'label' => 'Relance d’une demande sans réponse', 'help' => 'Délai avant de rappeler une demande (« Nous contacter ») à ceux qui la traitent.'],
        ]],
        'textes' => ['Textes affichés', 'Les messages vus par les membres. Le texte d’origine se rétablit d’un clic.', [
            'texts.login_notice' => ['type' => 'text', 'default' => '', 'max' => 280, 'lines' => 2, 'label' => 'Message sur l’écran de connexion',
                'help' => 'Affiché au-dessus du formulaire de connexion (par exemple un culte spécial). Laissez vide pour ne rien afficher.'],
            'texts.welcome_title' => ['type' => 'text', 'default' => 'Bienvenue dans votre famille spirituelle', 'max' => 120, 'label' => 'Titre de bienvenue',
                'help' => 'Première ouverture de l’application après l’inscription. Le prénom du membre est ajouté à la fin.'],
            'texts.welcome_message' => ['type' => 'text', 'default' => 'Nous sommes heureux de vous accueillir sur My vasesdhonneur, l\'espace des Vases d\'Honneur Chicoutimi.', 'max' => 400, 'lines' => 3,
                'label' => 'Message de bienvenue', 'help' => 'Affiché sous le titre de bienvenue.'],
            'texts.pending_message' => ['type' => 'text', 'default' => 'Le pasteur va valider votre inscription. Votre espace s\'ouvrira alors automatiquement.', 'max' => 400, 'lines' => 3,
                'label' => 'Message d’attente de validation', 'help' => 'Vu par une personne qui vient de s’inscrire, en attendant la validation de son compte.'],
        ]],
        'limites' => ['Limites', 'Pour ne pas déranger inutilement.', [
            'push.hourly_limit' => ['type' => 'int', 'default' => 6, 'min' => 3, 'max' => 20, 'unit' => 'par heure',
                'label' => 'Notifications sur le téléphone, par personne', 'help' => 'Au-delà, les notifications restent dans l’application sans faire sonner le téléphone.'],
            'new_members.window_days' => ['type' => 'int', 'default' => 30, 'min' => 7, 'max' => 90, 'unit' => 'jours',
                'label' => 'Période d’accueil des nouveaux membres', 'help' => 'Pendant combien de temps un inscrit apparaît dans la liste « Nouveaux membres ».'],
        ]],
    ];

    /** Etapes de app:tick qui dependent d'un module (rappels arretes quand le module est desactive). */
    public const MODULE_STEPS = [
        'vertumetre' => 'module.vertumetre',
        'resolutions' => 'module.resolutions',
        'rm-renew' => 'module.resolutions',
        'assignments' => 'module.assignments',
        'tasks' => 'module.exercises',
        'appointments' => 'module.appointments',
    ];

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $out = [];
        foreach (self::GROUPS as [, , $items]) {
            $out += $items;
        }

        return $out;
    }

    /** Valeurs enregistrees (sans les valeurs par defaut), en cache jusqu'a la prochaine modification. */
    private static function stored(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            if (! Schema::hasTable('platform_settings')) {
                return [];
            }

            return DB::table('platform_settings')->pluck('value', 'key')
                ->map(fn ($v) => json_decode((string) $v, true))->all();
        });
    }

    public static function get(string $key): mixed
    {
        $def = self::definitions()[$key] ?? throw new \InvalidArgumentException("Réglage inconnu : {$key}");
        $value = self::stored()[$key] ?? null;

        return $value === null ? $def['default'] : self::cast($def, $value);
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function enabled(string $key): bool
    {
        return (bool) self::get($key);
    }

    /** Etape de app:tick suspendue par les reglages (les etapes techniques ne le sont jamais). */
    public static function stepEnabled(string $step): bool
    {
        if (isset(self::MODULE_STEPS[$step]) && ! self::enabled(self::MODULE_STEPS[$step])) {
            return false;
        }
        foreach (self::definitions() as $key => $def) {
            if (($def['step'] ?? null) === $step) {
                return self::enabled($key);
            }
        }

        return true;
    }

    /** Module active (vertumetre, resolutions, assignments, exercises, appointments, spiritual). */
    public static function module(string $name): bool
    {
        return self::enabled("module.{$name}");
    }

    /**
     * Reglages utiles a l'application (envoyes avec le compte connecte) : modules actifs et delai avant
     * une nouvelle invitation a activer les notifications. Rien de sensible.
     *
     * @return array<string, mixed>
     */
    public static function client(): array
    {
        $modules = [];
        foreach (self::definitions() as $key => $def) {
            if (str_starts_with($key, 'module.')) {
                $modules[substr($key, 7)] = self::enabled($key);
            }
        }

        return ['modules' => $modules, 'push_prompt_snooze_days' => self::int('notifications.prompt_snooze_days')];
    }

    /** @return list<array<string, mixed>> Ecran des reglages : groupes, valeurs, valeurs par defaut. */
    public static function present(): array
    {
        $stored = self::stored();
        $groups = [];
        foreach (self::GROUPS as $id => [$title, $description, $items]) {
            $settings = [];
            foreach ($items as $key => $def) {
                $settings[] = [
                    'key' => $key,
                    'type' => $def['type'],
                    'label' => $def['label'],
                    'help' => $def['help'],
                    'value' => self::get($key),
                    'default' => $def['default'],
                    'changed' => array_key_exists($key, $stored) && $stored[$key] !== null && self::cast($def, $stored[$key]) !== $def['default'],
                ] + array_intersect_key($def, array_flip(['min', 'max', 'unit', 'lines']));
            }
            $groups[] = ['id' => $id, 'title' => $title, 'description' => $description, 'settings' => $settings];
        }

        return $groups;
    }

    /**
     * Enregistre des valeurs (null = retour a la valeur par defaut). Toute valeur hors bornes est refusee.
     *
     * @param  array<string, mixed>  $values
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} anciennes et nouvelles valeurs modifiees
     */
    public static function update(array $values, ?User $actor): array
    {
        $defs = self::definitions();
        $errors = [];
        $clean = [];
        foreach ($values as $key => $value) {
            $def = $defs[$key] ?? null;
            if (! $def) {
                $errors["values.{$key}"] = ['Réglage inconnu.'];

                continue;
            }
            if ($value === null) {
                $clean[$key] = null;

                continue;
            }
            if ($def['type'] === 'bool') {
                if (! is_bool($value)) {
                    $errors["values.{$key}"] = ['Choisissez activé ou désactivé.'];

                    continue;
                }
            } elseif ($def['type'] === 'text') {
                if (! is_string($value)) {
                    $errors["values.{$key}"] = ['Écrivez un texte.'];

                    continue;
                }
                $value = self::cleanText($value, (int) ($def['lines'] ?? 1) > 1);
                if (mb_strlen($value) > $def['max']) {
                    $errors["values.{$key}"] = ["Ce texte est trop long : {$def['max']} caractères au plus."];

                    continue;
                }
                if ($value === '' && $def['default'] !== '') {
                    $errors["values.{$key}"] = ['Écrivez un texte, ou cliquez sur « Rétablir » pour revenir au texte d’origine.'];

                    continue;
                }
            } elseif (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
                $errors["values.{$key}"] = ['Indiquez un nombre entier.'];

                continue;
            } elseif ((int) $value < $def['min'] || (int) $value > $def['max']) {
                $errors["values.{$key}"] = ["Choisissez une valeur entre {$def['min']} et {$def['max']}."];

                continue;
            }
            $clean[$key] = self::cast($def, $value);
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $old = [];
        $new = [];
        DB::transaction(function () use ($clean, $defs, $actor, &$old, &$new) {
            foreach ($clean as $key => $value) {
                $before = self::get($key);
                $after = $value ?? $defs[$key]['default'];
                if ($value === null || $value === $defs[$key]['default']) {
                    DB::table('platform_settings')->where('key', $key)->delete();
                } else {
                    DB::table('platform_settings')->updateOrInsert(['key' => $key], [
                        'value' => json_encode($value), 'updated_by' => $actor?->id, 'updated_at' => now(), 'created_at' => now(),
                    ]);
                }
                if ($before !== $after) {
                    $old[$key] = $before;
                    $new[$key] = $after;
                }
            }
        });
        Cache::forget(self::CACHE_KEY);

        return [$old, $new];
    }

    private static function cast(array $def, mixed $value): mixed
    {
        return match ($def['type']) {
            'bool' => (bool) $value,
            'text' => (string) $value,
            default => (int) $value,
        };
    }

    /** Texte saisi : espaces de bord retires, caracteres invisibles supprimes, sauts de ligne limites. */
    private static function cleanText(string $value, bool $multiline): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[\x{0000}-\x{0009}\x{000B}-\x{001F}\x{007F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $value);
        $value = $multiline ? (string) preg_replace("/\n{3,}/", "\n\n", $value) : str_replace("\n", ' ', $value);

        return trim((string) preg_replace('/[ \t]{2,}/', ' ', $value));
    }
}
