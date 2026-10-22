<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use RegRuControl\Kernel\I18n;

/**
 * API token scopes.
 *
 * `admin` implies everything; `write` implies `read`; `dns:write` implies
 * `write` (changing DNS is a write operation worth its own switch).
 */
enum TokenScope: string
{
    case Read = 'read';
    case Write = 'write';
    case DnsWrite = 'dns:write';
    case SecretsRead = 'secrets:read';
    case Admin = 'admin';
    case RegruRaw = 'regru:raw';

    public function label(): string
    {
        return I18n::t('label.scope.' . $this->value, [], match ($this) {
            self::Read => 'Чтение',
            self::Write => 'Изменение',
            self::DnsWrite => 'Управление DNS',
            self::SecretsRead => 'Доступ к секретам',
            self::Admin => 'Администратор',
            self::RegruRaw => 'Произвольные вызовы API',
        });
    }

    public function description(): string
    {
        return I18n::t('label.scope_description.' . $this->value, [], match ($this) {
            self::Read => 'Список доменов, услуг, хостингов, баланс, журналы.',
            self::Write => 'Продление, автопродление, синхронизация, доступы.',
            self::DnsWrite => 'Создание, изменение и удаление DNS-записей.',
            self::SecretsRead => 'Расшифровка паролей из хранилища (каждое чтение пишется в журнал).',
            self::Admin => 'Полный доступ, включая управление токенами и настройками.',
            self::RegruRaw => 'Вызов любой команды REG.RU API2 без типизированной обёртки.',
        });
    }

    public function implies(self $other): bool
    {
        if ($this === $other || $this === self::Admin) {
            return true;
        }

        return match ($this) {
            self::Write => $other === self::Read,
            self::DnsWrite => $other === self::Read || $other === self::Write,
            default => false,
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
