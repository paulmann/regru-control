<?php

declare(strict_types=1);

namespace RegRuControl\Kernel;

/**
 * Supported interface/API locales.
 *
 * English is the default: when the request carries no usable preference the
 * panel and the API answer in English.
 */
enum AppLocale: string
{
    case En = 'en';
    case Ru = 'ru';
    case Zh = 'zh';
    case Hi = 'hi';
    case De = 'de';
    case Es = 'es';

    public static function fromTag(?string $tag): ?self
    {
        $tag = strtolower(trim((string) $tag));

        if ($tag === '') {
            return null;
        }

        $base = explode('-', str_replace('_', '-', $tag))[0];

        return match ($base) {
            'en' => self::En,
            'ru' => self::Ru,
            'zh', 'cn' => self::Zh,
            'hi' => self::Hi,
            'de' => self::De,
            'es' => self::Es,
            default => null,
        };
    }

    public static function default(): self
    {
        return self::En;
    }

    /** Name in English, for APIs and logs. */
    public function label(): string
    {
        return match ($this) {
            self::En => 'English',
            self::Ru => 'Russian',
            self::Zh => 'Chinese',
            self::Hi => 'Hindi',
            self::De => 'German',
            self::Es => 'Spanish',
        };
    }

    /** Name in its own language, for the language switcher. */
    public function nativeLabel(): string
    {
        return match ($this) {
            self::En => 'English',
            self::Ru => 'Русский',
            self::Zh => '中文',
            self::Hi => 'हिन्दी',
            self::De => 'Deutsch',
            self::Es => 'Español',
        };
    }

    /** Value for the `lang` attribute and Intl APIs. */
    public function htmlLang(): string
    {
        return match ($this) {
            self::En => 'en',
            self::Ru => 'ru',
            self::Zh => 'zh-CN',
            self::Hi => 'hi',
            self::De => 'de',
            self::Es => 'es',
        };
    }

    public function isDefault(): bool
    {
        return $this === self::default();
    }
}
