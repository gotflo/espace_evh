<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Journal de securite PRIVE : operations du compte de gestion principal, activations, tentatives
 * d'usurpation. Aucune route de l'application ne le lit ; consultation sur le serveur seulement
 * (php artisan app:security-log). Jamais modifie ni supprime par l'application.
 */
class SecurityLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'level', 'action', 'subject_type', 'subject_id', 'member_user_id', 'details', 'ip', 'user_agent',
    ];

    protected $casts = [
        'details' => 'array',
        'created_at' => 'datetime',
    ];
}
