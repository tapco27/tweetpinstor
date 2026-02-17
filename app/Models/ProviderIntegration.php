<?php

namespace App\Models;

use App\Support\ProviderTemplates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ProviderIntegration extends Model
{
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    protected $hidden = [
        'credentials_encrypted',
    ];

    protected $appends = [
        'template',
        'type',
        'has_credentials',
        'credential_keys',
    ];

    public function getTemplateAttribute(): ?array
    {
        $code = (string) ($this->template_code ?? '');
        return ProviderTemplates::get($code);
    }

    public function getTypeAttribute(): string
    {
        $code = (string) ($this->template_code ?? '');
        return ProviderTemplates::typeFor($code, 'api');
    }

    public function getHasCredentialsAttribute(): bool
    {
        return !empty((string) ($this->credentials_encrypted ?? ''));
    }

    public function getCredentialKeysAttribute(): array
    {
        $creds = $this->credentials;
        if (!is_array($creds)) return [];
        return array_values(array_map('strval', array_keys($creds)));
    }

    /**
     * Accessor: decrypted credentials as array.
     * Never expose this publicly in controllers.
     */
    public function getCredentialsAttribute(): array
    {
        $enc = (string) ($this->credentials_encrypted ?? '');
        if ($enc === '') return [];

        try {
            $json = Crypt::decryptString($enc);
            $arr = json_decode($json, true);
            return is_array($arr) ? $arr : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Mutator: accepts array and encrypts to credentials_encrypted.
     */
    public function setCredentialsAttribute($value): void
    {
        if ($value === null) {
            $this->attributes['credentials_encrypted'] = null;
            return;
        }

        if (!is_array($value)) {
            $this->attributes['credentials_encrypted'] = null;
            return;
        }

        $clean = [];
        foreach ($value as $k => $v) {
            if (!is_string($k)) continue;
            $k = trim($k);
            if ($k === '') continue;
            if (is_scalar($v) || $v === null) {
                $clean[$k] = $v;
            }
        }

        if (count($clean) === 0) {
            $this->attributes['credentials_encrypted'] = null;
            return;
        }

        $this->attributes['credentials_encrypted'] = Crypt::encryptString(json_encode($clean));
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
