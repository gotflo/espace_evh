<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Seance (sans heures) ou creneau bloque a une date : aucun rendez-vous possible. */
class AppointmentBlock extends Model
{
    protected $fillable = ['host_id', 'date', 'starts_at', 'ends_at', 'reason', 'created_by'];

    protected $casts = ['date' => 'date:Y-m-d'];
}
