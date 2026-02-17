<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Http;

class GoogleIdTokenVerifier
{
    /**
     * Verify Google ID token using Google's tokeninfo endpoint.
     *
     * Returns normalized array:
     *  - provider_user_id (sub)
     *  - email
     *  - email_verified (bool)
     *  - name (nullable)
     *  - payload (raw)
     */
    public function verify(string $idToken): array
    {
        $res = Http::timeout(6)
            ->acceptJson()
            ->get('https://oauth2.googleapis.com/tokeninfo', [
                'id_token' => $idToken,
            ]);

        if (!$res->ok()) {
            throw new \RuntimeException('Invalid Google token');
        }

        $payload = (array) $res->json();

        $sub = (string) ($payload['sub'] ?? '');
        $email = (string) ($payload['email'] ?? '');

        if ($sub === '' || $email === '') {
            throw new \RuntimeException('Invalid Google token payload');
        }

        // Validate audience
        $aud = (string) ($payload['aud'] ?? '');
        $allowed = $this->allowedClientIds();
        if (!empty($allowed) && !in_array($aud, $allowed, true)) {
            throw new \RuntimeException('Google token audience mismatch');
        }

        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'provider_user_id' => $sub,
            'email' => $email,
            'email_verified' => (bool) $emailVerified,
            'name' => !empty($payload['name']) ? (string) $payload['name'] : null,
            'payload' => $payload,
        ];
    }

    private function allowedClientIds(): array
    {
        $ids = config('services.google.client_ids');
        if (is_array($ids)) {
            return array_values(array_filter(array_map('trim', $ids)));
        }

        $raw = (string) env('GOOGLE_OAUTH_CLIENT_IDS', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
