<?php

declare(strict_types=1);

namespace RegRuControl\Model;

/**
 * DNS record types supported by REG.RU API2 and the matching `zone/*` actions.
 */
enum RecordType: string
{
    case A = 'A';
    case AAAA = 'AAAA';
    case CNAME = 'CNAME';
    case MX = 'MX';
    case TXT = 'TXT';
    case NS = 'NS';
    case SRV = 'SRV';
    case CAA = 'CAA';

    public static function fromApi(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        return self::tryFrom(strtoupper($raw));
    }

    /** `zone/update_records` action for adding this record type. */
    public function addAction(): string
    {
        return match ($this) {
            self::A => 'add_alias',
            self::AAAA => 'add_aaaa',
            self::CNAME => 'add_cname',
            self::MX => 'add_mx',
            self::TXT => 'add_txt',
            self::NS => 'add_ns',
            self::SRV => 'add_srv',
            self::CAA => 'add_caa',
        };
    }

    public function label(): string
    {
        return $this->value;
    }

    public function description(): string
    {
        return match ($this) {
            self::A => 'IPv4-адрес',
            self::AAAA => 'IPv6-адрес',
            self::CNAME => 'Синоним (алиас) другого имени',
            self::MX => 'Почтовый сервер',
            self::TXT => 'Произвольный текст (SPF, DKIM, подтверждения)',
            self::NS => 'DNS-сервер поддомена',
            self::SRV => 'Сервисная запись (порт, вес, приоритет)',
            self::CAA => 'Правила выпуска SSL-сертификатов',
        };
    }

    public function supportsPriority(): bool
    {
        return in_array($this, [self::MX, self::SRV], true);
    }

    public function contentLabel(): string
    {
        return match ($this) {
            self::A => 'IPv4-адрес',
            self::AAAA => 'IPv6-адрес',
            self::CNAME => 'Каноническое имя',
            self::MX => 'Почтовый сервер',
            self::TXT => 'Текст записи',
            self::NS => 'Имя DNS-сервера',
            self::SRV => 'Цель (target)',
            self::CAA => 'Значение',
        };
    }

    public function placeholder(): string
    {
        return match ($this) {
            self::A => '203.0.113.10',
            self::AAAA => '2001:db8::1',
            self::CNAME => 'example.com.',
            self::MX => 'mail.example.com.',
            self::TXT => 'v=spf1 -all',
            self::NS => 'ns1.example.com.',
            self::SRV => 'sip.example.com.',
            self::CAA => 'letsencrypt.org',
        };
    }

    /** @return list<self> */
    public static function all(): array
    {
        return self::cases();
    }
}
