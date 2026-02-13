<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use RuntimeException;
use Tymon\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory, Notifiable, HasRoles;

    // مهم: لأنك عامل roles على guard_name = api
    protected $guard_name = 'api';

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    // حماية إضافية (حتى لو حد حاول يعمل mass-assign)
    protected $guarded = [
        'currency',
        'currency_selected_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'currency_selected_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // JWTSubject
    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    // منع تغيير العملة بعد إنشائها
    protected static function booted(): void
    {
        static::updating(function (User $user) {
            if ($user->isDirty('currency') || $user->isDirty('currency_selected_at')) {
                throw new RuntimeException('Currency is immutable once set.');
            }
        });
    }
}
