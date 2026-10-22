<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use RegRuControl\Kernel\I18n;

enum ServiceCategory: string
{
    case Domain = 'domain';
    case Hosting = 'hosting';
    case Server = 'server';
    case License = 'license';
    case Addon = 'addon';

    public function label(): string
    {
        return I18n::t('label.category.' . $this->value, [], match ($this) {
            self::Domain => 'Домены',
            self::Hosting => 'Хостинги',
            self::Server => 'Серверы',
            self::License => 'Лицензии',
            self::Addon => 'Дополнительные услуги',
        });
    }
}
