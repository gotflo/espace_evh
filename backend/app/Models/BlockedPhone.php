<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Numero d'un compte supprime : refuse pour toute nouvelle inscription (le Pasteur Resident peut le debloquer). */
class BlockedPhone extends Model
{
    protected $fillable = ['phone', 'member_name', 'reason', 'blocked_by'];
}
