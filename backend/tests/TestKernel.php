<?php

declare(strict_types=1);

namespace RegRuControl\Tests;

use RegRuControl\Database\Database;
use RegRuControl\Database\Migrator;
use RegRuControl\Kernel\AppLocale;
use RegRuControl\Kernel\Clock;
use RegRuControl\Kernel\Config;
use RegRuControl\Kernel\Container;
use RegRuControl\Kernel\Logger;
use RegRuControl\Kernel\SystemClock;
use RegRuControl\Kernel\Translator;
use RegRuControl\RegRu\Contract\AccountApiInterface;
use RegRuControl\RegRu\Contract\BillingApiInterface;
use RegRuControl\RegRu\Contract\DomainApiInterface;
use RegRuControl\RegRu\Contract\ServicesApiInterface;
use RegRuControl\RegRu\Contract\ZoneApiInterface;
use RegRuControl\RegRu\RegRuAccountProvider;
use RegRuControl\RegRu\RegRuCommandCatalog;
use RegRuControl\Security\ArraySessionStorage;
use RegRuControl\Security\SessionStorage;
use RegRuControl\Tests\Fakes\FakeAccountApi;
use RegRuControl\Tests\Fakes\FakeAccountProvider;
use RegRuControl\Tests\Fakes\FakeBillingApi;
use RegRuControl\Tests\Fakes\FakeDomainApi;
use RegRuControl\Tests\Fakes\FakeServicesApi;
use RegRuControl\Tests\Fakes\FakeZoneApi;

/**
 * Builds a fully wired container against an in-memory database and fake REG.RU
 * endpoints. No network, no global state.
 */
final class TestKernel
{
    public static function boot(): Container
    {
        $key = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $config = Config::fromEnvironment(require APP_ROOT . '/backend/config/defaults.php')->with([
            'app' => ['env' => 'test', 'debug' => true, 'key' => $key],
            'db' => ['path' => ':memory:'],
            'log' => ['path' => APP_ROOT . '/var/log/test.log', 'level' => 'error'],
            'session' => ['secure' => false],
        ]);

        $container = new Container($config, [
            SessionStorage::class => new ArraySessionStorage(),
            RegRuAccountProvider::class => new FakeAccountProvider(),
            AccountApiInterface::class => new FakeAccountApi(),
            ServicesApiInterface::class => new FakeServicesApi(),
            DomainApiInterface::class => new FakeDomainApi(),
            ZoneApiInterface::class => new FakeZoneApi(),
            BillingApiInterface::class => new FakeBillingApi(),
        ]);

        $container->bind(Clock::class, SystemClock::class);
        $container->set(
            Translator::class,
            new Translator(APP_ROOT . '/backend/resources/locales', AppLocale::En),
        );
        $container->set(
            RegRuCommandCatalog::class,
            new RegRuCommandCatalog(APP_ROOT . '/backend/config/regru-commands.json'),
        );

        $database = $container->get(Database::class);
        (new Migrator($database, $container->get(Logger::class), APP_ROOT . '/backend/migrations'))->migrate();

        return $container;
    }
}
