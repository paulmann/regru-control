<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * Process-wide translation facade.
 *
 * Set once per request by the kernel; used by exceptions and the validator so
 * that error messages do not have to be threaded through every call signature.
 */
final class I18n
{
    private static ?Translator $translator = null;

    public static function init(Translator $translator): void
    {
        self::$translator = $translator;
    }

    /**
     * @param array<string, scalar> $params
     */
    public static function t(string $key, array $params = [], ?string $fallback = null): string
    {
        if (self::$translator === null) {
            return $fallback ?? $key;
        }

        return self::$translator->t($key, $params, $fallback);
    }

    public static function locale(): AppLocale
    {
        return self::$translator?->locale() ?? AppLocale::default();
    }

    public static function has(string $key): bool
    {
        return self::$translator?->has($key) ?? false;
    }
}
