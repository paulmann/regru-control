<?php

/**
 * Default configuration values.
 *
 * Every key can be overridden through the environment / `.env` file, see
 * `Config::ENV_MAP`. Nested arrays are flattened with dot notation:
 * `$config->get('regru.timeout')`.
 *
 * @package RegRuControl
 */

declare(strict_types=1);

return [
    'app' => [
        'name' => 'REG.RU Control',
        'env' => 'production',
        'debug' => false,
        'url' => 'http://localhost',
        'timezone' => 'Europe/Moscow',
        'key' => '',
        'trusted_proxies' => '',
        'version' => '1.0.0',
    ],
    'db' => [
        'path' => 'var/regru.sqlite',
        'busy_timeout' => 5000,
    ],
    'log' => [
        'path' => 'var/log/app.log',
        'level' => 'info',
        'rotate_bytes' => 5_242_880,
        'keep_files' => 5,
    ],
    'session' => [
        'name' => 'rg_session',
        'idle' => 1800,
        'secure' => true,
    ],
    'regru' => [
        'base_url' => 'https://api.reg.ru/api/regru2/',
        'timeout' => 20,
        'retries' => 3,
        'rate_limit_per_hour' => 1100,
    ],
    'sync' => [
        'ttl' => 3600,
        'details_batch' => 20,
    ],
    'security' => [
        'login_attempts' => 10,
        'login_window' => 900,
        'api_rate_limit' => 600,
        'api_rate_window' => 60,
        'reveal_ttl' => 300,
    ],
];
