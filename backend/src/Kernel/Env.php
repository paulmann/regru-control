<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * `.env` loader.
 *
 * Real environment variables always win over the file, which makes container
 * deployments (Docker, systemd, CI) predictable. The parser understands
 * comments, single/double quotes and `export ` prefixes; it deliberately does
 * not support variable interpolation — configuration stays obvious.
 */
final class Env
{
    /** @var array<string, string> */
    private static array $loaded = [];

    public static function load(string $file): void
    {
        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $content = file_get_contents($file);

        if ($content === false) {
            return;
        }

        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $position = strpos($line, '=');

            if ($position === false) {
                continue;
            }

            $name = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));

            if ($name === '' || preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name) !== 1) {
                continue;
            }

            $value = self::unquote($value);
            self::$loaded[$name] = $value;

            // Never override a value that came from the real environment.
            if (getenv($name) !== false) {
                continue;
            }

            putenv($name . '=' . $value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }

    public static function get(string $name, ?string $default = null): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        if ($value === false || $value === null) {
            return self::$loaded[$name] ?? $default;
        }

        return (string) $value;
    }

    public static function has(string $name): bool
    {
        return self::get($name) !== null;
    }

    /**
     * Test helper: forget everything loaded from files.
     */
    public static function reset(): void
    {
        self::$loaded = [];
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];
            $last = $value[$length - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);

                if ($first === '"') {
                    $value = str_replace(['\\n', '\\r', '\\t', '\\"'], ["\n", "\r", "\t", '"'], $value);
                }

                return $value;
            }
        }

        // Trailing inline comment: `VALUE   # comment` (only after whitespace).
        return preg_replace('/\s+#.*$/', '', $value) ?? $value;
    }
}
