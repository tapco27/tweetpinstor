<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

    // تثبيت العملة: يسمح بتعيينها مرة واحدة فقط (من null -> قيمة)
    protected static function booted(): void
    {
        static::updating(function (User $user) {
            $originalCurrency = $user->getOriginal('currency');

            $isCurrencyChanging = $user->isDirty('currency');
            $isSelectedAtChanging = $user->isDirty('currency_selected_at');

            // إذا كانت العملة محددة مسبقاً -> ممنوع تغييرها أو تغيير تاريخ اختيارها
            if (!empty($originalCurrency) && ($isCurrencyChanging || $isSelectedAtChanging)) {
                // حتى لو حاول يكتب نفس القيمة، اعتبرها immutable لتجنب عبث غير مقصود
                throw new RuntimeException('Currency is immutable once set.');
            }

            // إذا كانت العملة null سابقاً: اسمح بتعيينها + currency_selected_at
            // (لا ترمي أي استثناء)
        });
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(\App\Models\Wallet::class);
    }
}
