<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Models\SpiritualHealthForm;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class FissRemind extends Command
{
    protected $signature = 'fiss:remind {--type=auto : advance | urgent | auto (selon le jour du mois)}';

    protected $description = 'Rappelle aux fidèles de remplir leur fiche de santé spirituelle (FISS) du mois.';

    public function handle(): int
    {
        $period = now()->format('Y-m');
        $monthLabel = now()->locale('fr')->isoFormat('MMMM YYYY');

        // Type de rappel : auto -> urgent si <=3 jours de la fin du mois, sinon advance.
        $type = $this->option('type');
        if ($type === 'auto') {
            $type = now()->diffInDays(now()->endOfMonth()) <= 3 ? 'urgent' : 'advance';
        }

        // Fideles (avec profil) qui n'ont PAS de fiche pour ce mois.
        $filled = SpiritualHealthForm::where('period', $period)->pluck('user_id');
        $missing = User::whereHas('profile')->whereNotIn('id', $filled)->pluck('id');

        if ($missing->isEmpty()) {
            $this->info("Aucun rappel a envoyer : toutes les fiches de {$monthLabel} sont remplies.");

            return self::SUCCESS;
        }

        $title = "Rappel : fiche de santé spirituelle de {$monthLabel}";
        $body = $type === 'urgent'
            ? "Le mois se termine bientôt. Merci de remplir votre fiche de santé spirituelle de {$monthLabel} dès aujourd'hui."
            : "Pensez à remplir votre fiche de santé spirituelle de {$monthLabel} avant la fin du mois.";

        // Une seule annonce-rappel par mois : on la recree a chaque passage avec la liste a jour.
        Announcement::where('title', $title)->delete();
        $ann = Announcement::create([
            'title' => $title,
            'body' => $body,
            'category' => 'important',
            'target_type' => 'all',
            'created_by' => null,
        ]);
        $ann->recipients()->attach($missing->all());

        $this->info("Rappel ({$type}) envoyé à {$missing->count()} fidèle(s) pour {$monthLabel}.");

        return self::SUCCESS;
    }
}
