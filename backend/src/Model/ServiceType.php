<?php

declare(strict_types=1);

namespace RegRuControl\Model;

use RegRuControl\Kernel\I18n;

/**
 * REG.RU service types (`servtype` in API responses).
 */
enum ServiceType: string
{
    case Domain = 'domain';
    case DomainPremium = 'dom_pp';
    case HostingIsp = 'srv_hosting_ispmgr';
    case HostingCpanel = 'srv_hosting_cpanel';
    case HostingPlesk = 'srv_hosting_plesk';
    case Vps = 'srv_vps';
    case CloudVps = 'srv_cloudvps';
    case Dedicated = 'srv_dedicated';
    case Parking = 'srv_parking_shop';
    case LicenseIsp = 'srv_license_isp';
    case Certificate = 'srv_certificate';
    case WebForward = 'srv_webfwd';
    case Antispam = 'srv_antispam';
    case AddIp = 'srv_addip';
    case Voucher = 'srv_voucher';
    case Domaintools = 'srv_domaintools';
    case Seowizard = 'srv_seowizard';
    case Dns = 'srv_dns_both';

    public static function fromApi(?string $raw): ?self
    {
        if ($raw === null) {
            return null;
        }

        return self::tryFrom($raw);
    }

    public function label(): string
    {
        return I18n::t('label.service_type.' . $this->value, [], match ($this) {
            self::Domain, self::DomainPremium => 'Домен',
            self::HostingIsp => 'Хостинг ISPmanager',
            self::HostingCpanel => 'Хостинг cPanel',
            self::HostingPlesk => 'Хостинг Plesk',
            self::Vps => 'VPS',
            self::CloudVps => 'Облачный сервер',
            self::Dedicated => 'Выделенный сервер',
            self::Parking => 'Парковка домена',
            self::LicenseIsp => 'Лицензия ISPmanager',
            self::Certificate => 'SSL-сертификат',
            self::WebForward => 'Web-форвардинг',
            self::Antispam => 'Антиспам',
            self::AddIp => 'Дополнительный IP',
            self::Voucher => 'Свидетельство на домен',
            self::Domaintools => 'Инструменты домена',
            self::Seowizard => 'SEO-продвижение',
            self::Dns => 'Поддержка DNS',
        });
    }

    public function category(): ServiceCategory
    {
        return match ($this) {
            self::Domain, self::DomainPremium => ServiceCategory::Domain,
            self::HostingIsp, self::HostingCpanel, self::HostingPlesk => ServiceCategory::Hosting,
            self::Vps, self::CloudVps, self::Dedicated => ServiceCategory::Server,
            self::LicenseIsp => ServiceCategory::License,
            self::Parking, self::WebForward, self::Antispam, self::AddIp, self::Voucher,
            self::Domaintools, self::Seowizard, self::Dns, self::Certificate => ServiceCategory::Addon,
        };
    }

    public function icon(): string
    {
        return match ($this->category()) {
            ServiceCategory::Domain => 'globe',
            ServiceCategory::Hosting => 'server',
            ServiceCategory::Server => 'cpu',
            ServiceCategory::License => 'key',
            ServiceCategory::Addon => 'puzzle',
        };
    }

    public function canHavePanelCredentials(): bool
    {
        return in_array($this, [self::HostingIsp, self::HostingCpanel, self::HostingPlesk, self::Vps, self::CloudVps, self::Dedicated], true);
    }
}
