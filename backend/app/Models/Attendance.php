<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Attendance extends Model
{
    protected $fillable = ['member_user_id', 'attended_on', 'event', 'kind', 'status', 'recorded_by'];

    protected $casts = ['attended_on' => 'date:Y-m-d'];
}
