<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $fillable = [
        'actor_type','actor_id',
        'auditable_type','auditable_id',
        'action',
        'old_values','new_values','meta',
        'ip','user_agent',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'meta' => 'array',
    ];
}
