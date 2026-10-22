<?php

/**
 * Application bootstrap.
 *
 * Builds the service container from `.env` + `config/defaults.php` and returns
 * it. Both the HTTP front controller and the CLI use this single entry point,
 * which guarantees identical configuration in every execution mode.
 *
 * @package RegRuControl
 */

declare(strict_types=1);

use RegRuControl\Kernel\AppLocale;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\Container;
use RegRuControl\Kernel\Env;
use RegRuControl\Kernel\SystemClock;
use RegRuControl\Kernel\Translator;
use RegRuControl\RegRu\Contract\AccountApiInterface;
use RegRuControl\RegRu\Contract\BillingApiInterface;
use RegRuControl\RegRu\Contract\DomainApiInterface;
use RegRuControl\RegRu\Contract\ServicesApiInterface;
use RegRuControl\RegRu\Contract\ZoneApiInterface;
use RegRuControl\RegRu\Endpoint\AccountApi;
use RegRuControl\RegRu\Endpoint\BillingApi;
use RegRuControl\RegRu\Endpoint\DomainApi;
use RegRuControl\RegRu\Endpoint\ServicesApi;
use RegRuControl\RegRu\Endpoint\ZoneApi;
use RegRuControl\RegRu\RegRuAccountProvider;
use RegRuControl\RegRu\RegRuCommandCatalog;
use RegRuControl\Service\DatabaseAccountProvider;
use RegRuControl\Security\NativeSessionStorage;
use RegRuControl\Security\SessionStorage;

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once __DIR__ . '/autoload.php';

Env::load(APP_ROOT . '/.env');

$config = Config::fromEnvironment(require __DIR__ . '/config/defaults.php');

$container = new Container($config);
$container->bind(Clock::class, SystemClock::class);
$container->bind(SessionStorage::class, NativeSessionStorage::class);
$container->bind(RegRuAccountProvider::class, DatabaseAccountProvider::class);
$container->bind(AccountApiInterface::class, AccountApi::class);
$container->bind(ServicesApiInterface::class, ServicesApi::class);
$container->bind(DomainApiInterface::class, DomainApi::class);
$container->bind(ZoneApiInterface::class, ZoneApi::class);
$container->bind(BillingApiInterface::class, BillingApi::class);
$container->set(
    RegRuCommandCatalog::class,
    new RegRuCommandCatalog(APP_ROOT . '/backend/config/regru-commands.json'),
);
$container->set(
    Translator::class,
    new Translator(APP_ROOT . '/backend/resources/locales', AppLocale::En),
);

return $container;
