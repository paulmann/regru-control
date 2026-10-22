<?php

/**
 * Extracts user-facing Russian strings from the SPA into a translation catalog.
 *
 *   php tools/i18n-extract.php
 *
 * The Russian string itself is the message id (gettext-style), so existing
 * templates need no manual key wrapping. `${expr}` placeholders are normalised
 * to `{p}` and must be preserved by translators.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$sourceDir = $root . '/frontend';
$outFile = $root . '/frontend/assets/js/locales/source.json';

$skip = ['/locales/'];
$strings = [];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());

    foreach ($skip as $needle) {
        if (str_contains($path, $needle)) {
            continue 2;
        }
    }

    if (!in_array(strtolower($file->getExtension()), ['js', 'html'], true)) {
        continue;
    }

    $content = (string) file_get_contents($file->getPathname());

    // Quoted strings: '...', "...", `...`
    $pattern = <<<'REGEX'
    ~(?<![A-Za-z0-9_])(?:'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"|`((?:[^`\\]|\\.)*)`)~u
    REGEX;

    if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER) === false) {
        continue;
    }

    foreach ($matches as $match) {
        $raw = ($match[1] ?? '') . ($match[2] ?? '') . ($match[3] ?? '');

        if (preg_match('/[\x{0400}-\x{04FF}]/u', $raw) !== 1) {
            continue;
        }

        $text = preg_replace('/\$\{[^}]*\}/u', '{p}', $raw) ?? $raw;
        $text = str_replace(['\\n', "\\'", '\\"', '\\`'], [' ', "'", '"', '`'], $text);
        $text = trim($text);

        if ($text === '' || mb_strlen($text) > 400) {
            continue;
        }

        // Drop obvious code fragments caught by the regex.
        $withoutPlaceholders = str_replace('{p}', '', $text);

        if (preg_match('~[<>{}\[\]]|=>|function\s*\(|document\.|window\.|classList|querySelector~u', $withoutPlaceholders) === 1) {
            continue;
        }

        if (preg_match('/^\s*\$\{|^\s*[a-zA-Z_]+\s*$/u', $text) === 1) {
            continue;
        }

        $strings[$text] = true;
    }
}

$list = array_keys($strings);
sort($list, SORT_NATURAL | SORT_FLAG_CASE);

if (!is_dir(dirname($outFile))) {
    mkdir(dirname($outFile), 0775, true);
}

file_put_contents($outFile, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

fwrite(STDOUT, sprintf("strings: %d\nout: %s\n", count($list), $outFile));
