<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Milestone extends Model
{
    protected $fillable = ['member_user_id', 'milestone_key', 'reached_at', 'recorded_by'];

    protected $casts = ['reached_at' => 'date:Y-m-d'];
}
