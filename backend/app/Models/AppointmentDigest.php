<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Courriel « rendez-vous du jour » d'un pasteur : un seul par jour (et sa mise a jour eventuelle). */
class AppointmentDigest extends Model
{
    protected $fillable = ['host_id', 'date', 'signature', 'reason', 'sent_at', 'updated_sent_at'];

    protected $casts = ['date' => 'date:Y-m-d', 'sent_at' => 'datetime', 'updated_sent_at' => 'datetime'];
}
