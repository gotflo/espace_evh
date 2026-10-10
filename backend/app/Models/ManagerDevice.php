<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Appareil de confiance du compte de gestion principal (seule l'empreinte du jeton est conservee). */
class ManagerDevice extends Model
{
    protected $fillable = ['user_id', 'token_hash', 'label', 'last_used_at', 'revoked_at'];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];
}
