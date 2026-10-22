<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

use RuntimeException;

/**
 * JSON-dictionary translator with English fallback.
 *
 * Keys are dotted (`error.not_found`, `validation.required`). Missing keys fall
 * back to English, then to the key itself, so a partial translation never
 * breaks the response.
 */
final class Translator
{
    /** @var array<string, string> */
    private array $messages = [];

    /** @var array<string, string> */
    private array $fallback = [];

    public function __construct(
        private readonly string $directory,
        private readonly AppLocale $locale,
    ) {
        $this->fallback = $this->load(AppLocale::En);

        if ($locale !== AppLocale::En) {
            $this->messages = $this->load($locale);
        } else {
            $this->messages = $this->fallback;
        }
    }

    public function locale(): AppLocale
    {
        return $this->locale;
    }

    public function withLocale(AppLocale $locale): self
    {
        return new self($this->directory, $locale);
    }

    /**
     * @param array<string, scalar> $params
     */
    public function t(string $key, array $params = [], ?string $fallback = null): string
    {
        $value = $this->messages[$key] ?? $this->fallback[$key] ?? $fallback ?? $key;

        if ($params === []) {
            return $value;
        }

        $replacements = [];

        foreach ($params as $name => $param) {
            $replacements['{' . $name . '}'] = (string) $param;
        }

        return strtr($value, $replacements);
    }

    public function has(string $key): bool
    {
        return isset($this->messages[$key]) || isset($this->fallback[$key]);
    }

    /**
     * @return array<string, string>
     */
    private function load(AppLocale $locale): array
    {
        $file = rtrim($this->directory, '/') . '/' . $locale->value . '.json';

        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);

        if ($raw === false) {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Translation file "%s" is not valid JSON.', $file));
        }

        $messages = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $messages[$key] = $value;
            }
        }

        return $messages;
    }
}
