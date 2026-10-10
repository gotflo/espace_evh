<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Reglages des rendez-vous d'un pasteur : duree d'un rendez-vous, rendez-vous ouverts ou non. */
class AppointmentSetting extends Model
{
    protected $fillable = ['user_id', 'slot_minutes', 'accepting'];

    protected $casts = ['slot_minutes' => 'integer', 'accepting' => 'boolean'];
}
