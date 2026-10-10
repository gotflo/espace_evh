<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Plage de disponibilite d'un pasteur : chaque semaine (weekday) ou a une date precise (date). */
class AppointmentWindow extends Model
{
    protected $fillable = ['host_id', 'weekday', 'date', 'starts_at', 'ends_at'];

    protected $casts = ['weekday' => 'integer', 'date' => 'date:Y-m-d'];
}
