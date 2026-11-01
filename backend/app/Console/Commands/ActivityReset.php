<?php

namespace App\Console\Commands;

use App\Services\ActivityService;
use App\Support\Audit;
use Illuminate\Console\Command;

/**
 * Remet a zero le compteur d'inactivite de tous les membres : le delai repart d'aujourd'hui. Les dates de
 * derniere visite restent vraies et aucun message n'est envoye.
 */
class ActivityReset extends Command
{
    protected $signature = 'app:activity-reset';

    protected $description = 'Le compteur d\'inactivité de tous les membres repart d\'aujourd\'hui.';

    public function handle(): int
    {
        $previous = ActivityService::countedFrom()?->toDateString();
        $date = ActivityService::resetCounter();
        Audit::log('members.activity_reset', null, null, ['counted_from' => $previous], ['counted_from' => $date->toDateString()]);
        $this->info('Compteur d\'inactivité : départ le '.$date->toDateString().'.');

        return self::SUCCESS;
    }
}
