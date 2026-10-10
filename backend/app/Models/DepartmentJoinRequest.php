<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Demande d'inscription a un service (departement), validee par son responsable. */
class DepartmentJoinRequest extends Model
{
    protected $fillable = ['department_id', 'user_id', 'status', 'decided_by', 'decided_at'];

    protected $casts = ['decided_at' => 'datetime'];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
