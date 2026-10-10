<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Code d'activation du compte de gestion principal (a usage unique ; seule l'empreinte est conservee). */
class ManagerActivationCode extends Model
{
    protected $fillable = ['code_hash', 'expires_at', 'used_at', 'used_by'];

    protected $hidden = ['code_hash'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];
}
