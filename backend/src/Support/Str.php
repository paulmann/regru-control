<?php

declare(strict_types=1);

namespace RegRuControl\Support;

use InvalidArgumentException;

final class Str
{
    /**
     * URL-safe random string (base64url without padding).
     */
    public static function random(int $bytes = 24): string
    {
        if ($bytes < 8) {
            throw new InvalidArgumentException('At least 8 random bytes are required.');
        }

        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public static function mask(string $value, int $visible = 4): string
    {
        $length = mb_strlen($value);

        if ($length === 0) {
            return '';
        }

        if ($length <= $visible) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', max(4, $length - $visible)) . mb_substr($value, -$visible);
    }

    public static function truncate(string $value, int $length = 120, string $suffix = '…'): string
    {
        if (mb_strlen($value) <= $length) {
            return $value;
        }

        return mb_substr($value, 0, $length) . $suffix;
    }

    public static function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/u', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value === '' ? 'item' : $value;
    }

    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Parses a byte size such as "5M" into bytes. Returns null for garbage.
     */
    public static function toBytes(string $value): ?int
    {
        if (preg_match('/^\s*(\d+(?:\.\d+)?)\s*([kmgt]?)b?\s*$/i', $value, $matches) !== 1) {
            return null;
        }

        $number = (float) $matches[1];
        $unit = strtolower($matches[2]);

        $factor = match ($unit) {
            'k' => 1024,
            'm' => 1024 ** 2,
            'g' => 1024 ** 3,
            't' => 1024 ** 4,
            default => 1,
        };

        return (int) round($number * $factor);
    }
}
