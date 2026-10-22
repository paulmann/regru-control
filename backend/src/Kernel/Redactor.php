<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * Recursively replaces values of sensitive keys.
 */
final class Redactor
{
    /** @var list<string> */
    private const SENSITIVE = ['password', 'passwd', 'secret', 'token', 'authorization', 'authinfo', 'api_key', 'apikey', 'cookie', 'private'];

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, int $depth = 0): array
    {
        if ($depth > 6) {
            return ['...' => 'max depth'];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && self::isSensitive($key)) {
                $clean[$key] = '***';

                continue;
            }

            if (is_array($value)) {
                $clean[$key] = self::redact($value, $depth + 1);

                continue;
            }

            if ($value instanceof Throwable) {
                $clean[$key] = $value::class . ': ' . $value->getMessage();

                continue;
            }

            if ($value instanceof Stringable) {
                $value = (string) $value;
            }

            if (is_string($value) && strlen($value) > 2000) {
                $value = substr($value, 0, 2000) . '…[truncated]';
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private static function isSensitive(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (self::SENSITIVE as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
