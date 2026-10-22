<?php

declare(strict_types=1);

namespace RegRuControl\Support;

use JsonException;
use RuntimeException;

/**
 * JSON helpers with a single encoding policy across the whole application.
 */
final class Json
{
    public const ENCODE_FLAGS = JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
        | JSON_THROW_ON_ERROR;

    public static function encode(mixed $data, int $flags = 0): string
    {
        try {
            return json_encode($data, self::ENCODE_FLAGS | $flags);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot encode JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Cannot decode JSON: ' . $exception->getMessage(), 0, $exception);
        }

        if (!is_array($data)) {
            throw new RuntimeException('JSON root must be an object or an array.');
        }

        return $data;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function tryDecode(?string $json): ?array
    {
        if ($json === null || trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true, 512);

        return is_array($decoded) ? $decoded : null;
    }

    public static function pretty(mixed $data): string
    {
        return self::encode($data, JSON_PRETTY_PRINT);
    }
}
