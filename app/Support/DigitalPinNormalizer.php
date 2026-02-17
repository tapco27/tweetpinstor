<?php

namespace App\Support;

class DigitalPinNormalizer
{
  /**
   * Trim + normalize a code for *storage/display* (keep formatting as much as possible).
   */
  public static function normalizeForStore(string $code): string
  {
    // Keep original separators but remove surrounding whitespace and control chars.
    $c = trim($code);
    $c = str_replace(["\r", "\n", "\t"], '', $c);
    return $c;
  }

  /**
   * Normalize a code for *deduplication* (canonical fingerprint).
   *
   * We treat common formatting differences as same code:
   * - case-insensitive
   * - ignore spaces
   * - ignore hyphens
   */
  public static function normalizeForHash(string $code): string
  {
    $c = self::normalizeForStore($code);

    // Uppercase for stable hashing
    $c = mb_strtoupper($c);

    // Remove spaces and hyphens
    $c = preg_replace('/[\s\-]+/u', '', $c) ?? $c;

    return $c;
  }

  public static function hash(string $code): string
  {
    return hash('sha256', self::normalizeForHash($code));
  }

  /**
   * Parse codes from either an array, or a textarea (newline separated).
   */
  public static function parseCodes(mixed $codes, ?string $codesText = null): array
  {
    $list = [];

    if (is_array($codes)) {
      $list = $codes;
    } elseif (is_string($codesText) && trim($codesText) !== '') {
      $list = preg_split('/\r\n|\r|\n/', $codesText) ?: [];
    }

    $out = [];
    foreach ($list as $raw) {
      if (!is_string($raw)) continue;
      $c = self::normalizeForStore($raw);
      if ($c === '') continue;
      $out[] = $c;
    }

    return $out;
  }
}
