<?php

namespace App\Support;

use App\Models\Gem;
use App\Models\Profile;
use Illuminate\Validation\ValidationException;

/**
 * Regles des GEMs, appliquees partout (page GEMs, attribution du role Garde, profil, appartenance) :
 * - un Garde ne peut mener qu'un GEM de SA tribu ;
 * - un membre n'appartient qu'a un GEM de sa tribu ;
 * - changer de tribu fait quitter le GEM (et la responsabilite Garde) de l'ancienne tribu.
 */
class GemRules
{
    /** Le futur Garde doit etre un membre de la tribu du GEM. */
    public static function assertLeaderInTribe(?int $leaderId, int $tribeId): void
    {
        if (! $leaderId) {
            return;
        }
        $profile = Profile::where('user_id', $leaderId)->first();
        if (! $profile) {
            throw ValidationException::withMessages(['leader_user_id' => 'Le responsable choisi doit être un membre.']);
        }
        if ((int) $profile->tribe_id !== $tribeId) {
            throw ValidationException::withMessages([
                'leader_user_id' => "{$profile->full_name} n'appartient pas à la tribu de ce GEM : le Garde doit être choisi parmi les membres de la tribu.",
            ]);
        }
    }

    /** Un membre ne peut etre place que dans un GEM de sa tribu. */
    public static function assertGemInTribe(?int $gemId, ?int $tribeId): void
    {
        if ($gemId && ! Gem::whereKey($gemId)->where('tribe_id', $tribeId)->exists()) {
            throw ValidationException::withMessages(['gem_id' => 'Ce GEM n\'appartient pas à la tribu choisie.']);
        }
    }

    /**
     * Nomme (ou retire) le Garde d'un GEM : role Garde synchronise (un seul Garde par GEM) et,
     * s'il n'est dans aucun GEM, le Garde rejoint automatiquement celui qu'il mene.
     */
    public static function appointLeader(Gem $gem, ?int $userId, int $actorId): void
    {
        self::assertLeaderInTribe($userId, (int) $gem->tribe_id);
        if ((int) $gem->leader_user_id !== (int) $userId) {
            $gem->forceFill(['leader_user_id' => $userId])->save();
        }
        LeaderRole::sync('garde', 'gem', $gem->id, $userId, $actorId);

        if ($userId) {
            Profile::where('user_id', $userId)->whereNull('gem_id')->update(['gem_id' => $gem->id]);
        }
    }

    /**
     * Apres un changement de tribu : le membre quitte son GEM s'il est d'une autre tribu,
     * et perd la responsabilite (Garde) des GEMs de son ancienne tribu.
     */
    public static function afterTribeChange(Profile $profile, int $actorId): void
    {
        if ($profile->gem_id && ! Gem::whereKey($profile->gem_id)->where('tribe_id', $profile->tribe_id)->exists()) {
            $profile->forceFill(['gem_id' => null])->save();
        }

        $led = Gem::where('leader_user_id', $profile->user_id)
            ->where(fn ($q) => $q->whereNull('tribe_id')->orWhere('tribe_id', '!=', $profile->tribe_id ?? 0))
            ->get();
        foreach ($led as $gem) {
            $gem->forceFill(['leader_user_id' => null])->save();
            LeaderRole::sync('garde', 'gem', $gem->id, null, $actorId);
        }
    }
}
