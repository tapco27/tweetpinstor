<?php

namespace App\Support;

final class ProviderTemplates
{
    /**
     * @return array<string,array>
     */
    public static function all(): array
    {
        $all = config('provider_templates');
        return is_array($all) ? $all : [];
    }

    public static function get(string $code): ?array
    {
        $code = trim((string) $code);
        if ($code === '') return null;

        // Backward-compatible aliases (older code used tweetpin / tweet-pin)
        $aliases = [
            'tweetpin' => 'tweet_pin',
            'tweet-pin' => 'tweet_pin',
        ];

        $lower = strtolower($code);
        if (array_key_exists($lower, $aliases)) {
            $code = $aliases[$lower];
        }

        $all = self::all();
        return $all[$code] ?? null;
    }

    public static function exists(string $code): bool
    {
        return self::get($code) !== null;
    }

    public static function typeFor(string $code, string $default = 'api'): string
    {
        $tpl = self::get($code);
        $type = is_array($tpl) ? ($tpl['type'] ?? null) : null;
        $type = is_string($type) ? trim($type) : '';
        if ($type === '') return $default;
        return $type;
    }
}
