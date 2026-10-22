<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use RegRuControl\Kernel\I18n;

/**
 * Service state as returned by REG.RU API2.
 */
enum ServiceState: string
{
    case Active = 'A';
    case Suspended = 'S';
    case Inactive = 'N';
    case Deleted = 'D';
    case Transferred = 'O';
    case Unknown = '?';

    public static function fromApi(?string $raw): self
    {
        if ($raw === null || $raw === '') {
            return self::Unknown;
        }

        return self::tryFrom(strtoupper($raw)) ?? self::Unknown;
    }

    public function label(): string
    {
        return I18n::t('label.state.' . $this->value, [], match ($this) {
            self::Active => 'Активна',
            self::Suspended => 'Приостановлена',
            self::Inactive => 'Не активирована',
            self::Deleted => 'Удалена',
            self::Transferred => 'Передана',
            self::Unknown => 'Неизвестно',
        });
    }

    /** UI tone: ok | warn | bad | muted */
    public function tone(): string
    {
        return match ($this) {
            self::Active => 'ok',
            self::Suspended, self::Inactive => 'warn',
            self::Deleted, self::Transferred => 'bad',
            self::Unknown => 'muted',
        };
    }

    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
