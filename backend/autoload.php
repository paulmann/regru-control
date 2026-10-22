<?php

/**
 * Minimal, dependency-free PSR-4 autoloader.
 *
 * Composer users get the same mapping from composer.json; this file keeps the
 * application runnable on a bare PHP installation (no `vendor/` required).
 *
 * @package RegRuControl
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefixes = [
        'RegRuControl\\Tests\\' => __DIR__ . '/tests/',
        'RegRuControl\\' => __DIR__ . '/src/',
    ];

    foreach ($prefixes as $prefix => $baseDir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $file = $baseDir . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;

            return;
        }
    }
});
