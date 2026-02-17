<?php

namespace App\Services\Social;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AppleIdTokenVerifier
{
    /**
     * Verify Apple identity token (JWT) using Apple's JWKS.
     *
     * Returns normalized array:
     *  - provider_user_id (sub)
     *  - email (nullable)
     *  - email_verified (bool)
     *  - name (always null here; Apple may provide name from client)
     *  - payload (raw)
     */
    public function verify(string $idToken): array
    {
        [$h64, $p64, $s64] = $this->splitJwt($idToken);

        $header = $this->jsonDecode($this->base64UrlDecode($h64));
        $payload = $this->jsonDecode($this->base64UrlDecode($p64));
        $sig = $this->base64UrlDecode($s64);

        $kid = (string) ($header['kid'] ?? '');
        $alg = (string) ($header['alg'] ?? '');

        if ($kid === '' || $alg !== 'RS256') {
            throw new \RuntimeException('Invalid Apple token header');
        }

        $jwks = $this->getAppleJwks();
        $jwk = $this->findKey($jwks, $kid);

        $pem = $this->jwkToPem($jwk);

        $data = $h64 . '.' . $p64;
        $verified = openssl_verify($data, $sig, $pem, OPENSSL_ALGO_SHA256);

        if ($verified !== 1) {
            throw new \RuntimeException('Invalid Apple token signature');
        }

        // Validate claims
        $iss = (string) ($payload['iss'] ?? '');
        if ($iss !== 'https://appleid.apple.com') {
            throw new \RuntimeException('Apple token issuer mismatch');
        }

        $aud = (string) ($payload['aud'] ?? '');
        $allowed = $this->allowedClientIds();
        if (!empty($allowed) && !in_array($aud, $allowed, true)) {
            throw new \RuntimeException('Apple token audience mismatch');
        }

        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp <= 0 || $exp < time()) {
            throw new \RuntimeException('Apple token expired');
        }

        $sub = (string) ($payload['sub'] ?? '');
        if ($sub === '') {
            throw new \RuntimeException('Invalid Apple token payload');
        }

        $email = !empty($payload['email']) ? (string) $payload['email'] : null;
        $emailVerified = filter_var($payload['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);

        return [
            'provider_user_id' => $sub,
            'email' => $email,
            'email_verified' => (bool) $emailVerified,
            'name' => null,
            'payload' => $payload,
        ];
    }

    private function allowedClientIds(): array
    {
        $ids = config('services.apple.client_ids');
        if (is_array($ids)) {
            return array_values(array_filter(array_map('trim', $ids)));
        }

        $raw = (string) env('APPLE_CLIENT_IDS', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private function getAppleJwks(): array
    {
        return Cache::remember('apple_sign_in_jwks', now()->addHours(6), function () {
            $res = Http::timeout(6)
                ->acceptJson()
                ->get('https://appleid.apple.com/auth/keys');

            if (!$res->ok()) {
                throw new \RuntimeException('Unable to fetch Apple keys');
            }

            $json = (array) $res->json();
            return $json;
        });
    }

    private function findKey(array $jwks, string $kid): array
    {
        $keys = $jwks['keys'] ?? [];
        if (!is_array($keys)) {
            throw new \RuntimeException('Invalid Apple keys response');
        }

        foreach ($keys as $k) {
            if (!is_array($k)) continue;
            if (($k['kid'] ?? null) === $kid) {
                return $k;
            }
        }

        throw new \RuntimeException('Apple key not found');
    }

    private function splitJwt(string $jwt): array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWT format');
        }
        return [$parts[0], $parts[1], $parts[2]];
    }

    private function jsonDecode(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JWT JSON');
        }
        return $data;
    }

    private function base64UrlDecode(string $data): string
    {
        $data = strtr($data, '-_', '+/');
        $pad = strlen($data) % 4;
        if ($pad) {
            $data .= str_repeat('=', 4 - $pad);
        }
        $decoded = base64_decode($data, true);
        if ($decoded === false) {
            throw new \RuntimeException('Invalid base64url');
        }
        return $decoded;
    }

    /**
     * Convert RSA JWK to PEM (SubjectPublicKeyInfo).
     * Based on ASN.1 DER encoding for rsaEncryption.
     */
    private function jwkToPem(array $jwk): string
    {
        if (($jwk['kty'] ?? null) !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            throw new \RuntimeException('Invalid JWK');
        }

        $n = $this->base64UrlDecode((string) $jwk['n']);
        $e = $this->base64UrlDecode((string) $jwk['e']);

        // RSAPublicKey ::= SEQUENCE { modulus INTEGER, publicExponent INTEGER }
        $rsaPublicKey = $this->asn1Sequence(
            $this->asn1Integer($n) .
            $this->asn1Integer($e)
        );

        // AlgorithmIdentifier for rsaEncryption OID 1.2.840.113549.1.1.1 with NULL params
        $algo = $this->asn1Sequence(
            "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x01\x01" . // OID
            "\x05\x00" // NULL
        );

        // SubjectPublicKeyInfo ::= SEQUENCE { algorithm AlgorithmIdentifier, subjectPublicKey BIT STRING }
        $bitString = "\x03" . $this->asn1Length(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = $this->asn1Sequence($algo . $bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n";
        $pem .= chunk_split(base64_encode($spki), 64, "\n");
        $pem .= "-----END PUBLIC KEY-----\n";
        return $pem;
    }

    private function asn1Sequence(string $data): string
    {
        return "\x30" . $this->asn1Length(strlen($data)) . $data;
    }

    private function asn1Integer(string $data): string
    {
        // Strip leading zeros
        $data = ltrim($data, "\x00");
        if ($data === '') {
            $data = "\x00";
        }

        // If high bit is set, prefix with 0x00 to indicate positive integer
        if ((ord($data[0]) & 0x80) !== 0) {
            $data = "\x00" . $data;
        }

        return "\x02" . $this->asn1Length(strlen($data)) . $data;
    }

    private function asn1Length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $temp = '';
        while ($length > 0) {
            $temp = chr($length & 0xFF) . $temp;
            $length >>= 8;
        }

        return chr(0x80 | strlen($temp)) . $temp;
    }
}
