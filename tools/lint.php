<?php

/**
 * Dependency-free project linter.
 *
 *   php tools/lint.php              PHP syntax + strict_types on every source file
 *   php tools/lint.php --text-files also check BOM/CRLF hygiene for text assets
 *
 * Exit code 0 = clean, 1 = problems.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$textMode = in_array('--text-files', $argv, true);
$failures = [];
$checked = 0;

$directories = [
    $root . '/backend/src',
    $root . '/backend/bin',
    $root . '/backend/tests',
    $root . '/backend/config',
    $root . '/public',
    $root . '/tools',
    $root . '/mcp',
];

$phpFiles = [];

foreach ($directories as $directory) {
    if (!is_dir($directory)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $path = $file->getPathname();

            if (str_ends_with($path, '.php') || basename($path) === 'regru') {
                $phpFiles[] = $path;
            }
        }
    }
}

sort($phpFiles);

foreach ($phpFiles as $file) {
    $checked++;
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($file) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        $failures[] = 'SYNTAX ' . $file . ': ' . implode(' ', $output);

        continue;
    }

    $content = (string) file_get_contents($file);

    if (str_ends_with($file, '.php') && !str_contains($content, 'declare(strict_types=1);')) {
        $failures[] = 'NO_STRICT_TYPES ' . $file;
    }
}

if ($textMode) {
    $textExtensions = ['php', 'js', 'mjs', 'css', 'html', 'md', 'json', 'yml', 'yaml', 'sql', 'sh', 'ps1', 'txt', 'example', 'conf'];
    $skipDirectories = ['/var/', '/.git/', '/vendor/', '/build/', '/deploy/'];

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());

        foreach ($skipDirectories as $skip) {
            if (str_contains($path, $skip)) {
                continue 2;
            }
        }

        $extension = strtolower($file->getExtension());

        if (basename($path) === '.env.example') {
            $extension = 'example';
        }

        if (!in_array($extension, $textExtensions, true)) {
            continue;
        }

        if ($extension === 'ps1' || $extension === 'bat' || $extension === 'cmd') {
            continue;
        }

        $bytes = (string) file_get_contents($file->getPathname());
        $checked++;

        if (str_starts_with($bytes, "\xEF\xBB\xBF")) {
            $failures[] = 'BOM ' . $path;
        }

        if (str_contains($bytes, "\r\n")) {
            $failures[] = 'CRLF ' . $path;
        }
    }
}

if ($failures === []) {
    fwrite(STDOUT, sprintf("OK: %d checks passed%s\n", $checked, $textMode ? ' (syntax + text hygiene)' : ' (syntax)'));

    exit(0);
}

fwrite(STDERR, sprintf("FAILED: %d problem(s)\n", count($failures)));

foreach ($failures as $failure) {
    fwrite(STDERR, '  - ' . $failure . "\n");
}

exit(1);
