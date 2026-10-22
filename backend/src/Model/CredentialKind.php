<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use RegRuControl\Kernel\I18n;

enum CredentialKind: string
{
    case Panel = 'panel';
    case Ftp = 'ftp';
    case Ssh = 'ssh';
    case Database = 'database';
    case Email = 'email';
    case Dns = 'dns';
    case Registrar = 'registrar';
    case Other = 'other';

    public static function fromString(?string $raw): self
    {
        if ($raw === null) {
            return self::Other;
        }

        return self::tryFrom($raw) ?? self::Other;
    }

    public function label(): string
    {
        return I18n::t('label.kind.' . $this->value, [], match ($this) {
            self::Panel => 'Панель управления',
            self::Ftp => 'FTP/SFTP',
            self::Ssh => 'SSH',
            self::Database => 'База данных',
            self::Email => 'Почта',
            self::Dns => 'DNS',
            self::Registrar => 'Регистратор',
            self::Other => 'Другое',
        });
    }

    public function icon(): string
    {
        return match ($this) {
            self::Panel => 'layout',
            self::Ftp => 'folder',
            self::Ssh => 'terminal',
            self::Database => 'database',
            self::Email => 'mail',
            self::Dns => 'globe',
            self::Registrar => 'shield',
            self::Other => 'key',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
