<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Une cible de publication : toute l'eglise, une tribu, un GEM ou un departement. */
class PublicationScope extends Model
{
    public $timestamps = false;

    protected $fillable = ['scopable_type', 'scopable_id', 'scope_type', 'scope_id'];

    protected $casts = ['scope_id' => 'integer', 'scopable_id' => 'integer'];
}
