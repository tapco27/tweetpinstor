<?php

namespace App\Support;

/**
 * Central list of supported purchase metadata fields.
 *
 * These keys are used inside OrderItem.metadata and configured per Category.
 */
class PurchaseRequirements
{
    /**
     * Supported keys (keep in sync with Admin UI + Flutter).
     */
    public const ALLOWED_KEYS = [
        'uid',
        'player_id',
        'email',
        'phone',
        'wallet_id',
    ];

    /**
     * Return a normalized list of requirement keys.
     *
     * - trims
     * - removes empty
     * - unique
     */
    public static function normalize(?array $keys): array
    {
        if (!$keys) {
            return [];
        }

        $out = [];
        foreach ($keys as $k) {
            if (!is_string($k)) {
                continue;
            }
            $k = trim($k);
            if ($k === '') {
                continue;
            }
            $out[] = $k;
        }

        return array_values(array_unique($out));
    }

    /**
     * Minimal field-level validation for metadata.
     *
     * Returns an error message if invalid, otherwise null.
     */
    public static function validateValue(string $key, mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        if ($value === '') {
            return 'Required field';
        }

        if ($key === 'email') {
            if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                return 'Invalid email';
            }
        }

        if ($key === 'phone') {
            $phone = preg_replace('/\s+/', '', $value);
            // Simple check: digits only, optional + prefix
            if (!preg_match('/^\+?[0-9]{7,15}$/', (string) $phone)) {
                return 'Invalid phone number';
            }
        }

        return null;
    }
}
