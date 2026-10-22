<?php

/**
 * HTTP front controller.
 *
 * Works both in the repository layout (public/ is the web root, backend/ next to
 * it) and in the deployed layout (/reg.ru/index.php with /reg.ru/backend/).
 */

declare(strict_types=1);

use RegRuControl\Kernel\App;

// PHP's built-in development server uses this file as a router script, which
// disables its automatic static file handling. Return false for existing files
// (CSS/JS/SVG) so they are served as-is; production nginx does this itself.
if (PHP_SAPI === 'cli-server') {
    $requestPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $requestPath = is_string($requestPath) ? rawurldecode($requestPath) : '/';

    if ($requestPath !== '/' && !str_contains($requestPath, '..') && is_file(__DIR__ . $requestPath)) {
        return false;
    }
}

$backend = is_dir(__DIR__ . '/backend') ? __DIR__ . '/backend' : dirname(__DIR__) . '/backend';

if (!is_file($backend . '/bootstrap.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "REG.RU Control: backend/bootstrap.php not found.\n";

    exit;
}

/** @var \RegRuControl\Kernel\Container $container */
$container = require $backend . '/bootstrap.php';

$container->get(App::class)->run();
