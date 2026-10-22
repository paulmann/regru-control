<?php

/**
 * Controllers registered in the router, in documentation order.
 *
 * Keeping the list in configuration (instead of scanning the filesystem) makes
 * boot deterministic and cheap: no reflection over unrelated files.
 *
 * @return list<class-string>
 */

declare(strict_types=1);

use RegRuControl\Http\Controller\AuditController;
use RegRuControl\Http\Controller\AuthController;
use RegRuControl\Http\Controller\BillingController;
use RegRuControl\Http\Controller\BootstrapController;
use RegRuControl\Http\Controller\CredentialsController;
use RegRuControl\Http\Controller\DashboardController;
use RegRuControl\Http\Controller\DnsController;
use RegRuControl\Http\Controller\DomainsController;
use RegRuControl\Http\Controller\HealthController;
use RegRuControl\Http\Controller\HostingsController;
use RegRuControl\Http\Controller\LocaleController;
use RegRuControl\Http\Controller\MetaController;
use RegRuControl\Http\Controller\PageController;
use RegRuControl\Http\Controller\ServicesController;
use RegRuControl\Http\Controller\SettingsController;
use RegRuControl\Http\Controller\SyncController;
use RegRuControl\Http\Controller\TokensController;

return [
    PageController::class,
    HealthController::class,
    LocaleController::class,
    BootstrapController::class,
    AuthController::class,
    DashboardController::class,
    DomainsController::class,
    DnsController::class,
    ServicesController::class,
    HostingsController::class,
    CredentialsController::class,
    BillingController::class,
    SyncController::class,
    TokensController::class,
    SettingsController::class,
    AuditController::class,
    MetaController::class,
];
