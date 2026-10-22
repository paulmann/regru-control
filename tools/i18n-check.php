<?php

/**
 * Validates translation catalogs against the extracted message catalog.
 *
 *   php tools/i18n-check.php
 *
 * Checks: valid JSON, every source string translated, no extra keys, the same
 * number of `{p}` placeholders in source and translation.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$localesDir = $root . '/frontend/assets/js/locales';
$sourceFile = $localesDir . '/source.json';

if (!is_file($sourceFile)) {
    fwrite(STDERR, "Missing {$sourceFile}; run tools/i18n-extract.php first.\n");
    exit(1);
}

$source = json_decode((string) file_get_contents($sourceFile), true, 512, JSON_THROW_ON_ERROR);

if (!is_array($source)) {
    fwrite(STDERR, "source.json must be a JSON array.\n");
    exit(1);
}

$sourceSet = array_flip($source);
$problems = [];
$locales = ['en', 'zh', 'hi', 'de', 'es'];

foreach ($locales as $locale) {
    $file = $localesDir . '/' . $locale . '.json';

    if (!is_file($file)) {
        $problems[] = sprintf('%s: missing catalog', $locale);

        continue;
    }

    try {
        $catalog = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        $problems[] = sprintf('%s: invalid JSON (%s)', $locale, $exception->getMessage());

        continue;
    }

    if (!is_array($catalog)) {
        $problems[] = sprintf('%s: catalog must be a JSON object', $locale);

        continue;
    }

    $missing = array_diff_key($sourceSet, $catalog);
    $extra = array_diff_key($catalog, $sourceSet);

    if ($missing !== []) {
        $problems[] = sprintf('%s: %d missing key(s), e.g. %s', $locale, count($missing), (string) array_key_first($missing));
    }

    if ($extra !== []) {
        $problems[] = sprintf('%s: %d stale key(s), e.g. %s', $locale, count($extra), (string) array_key_first($extra));
    }

    foreach ($source as $message) {
        if (!isset($catalog[$message])) {
            continue;
        }

        $expected = substr_count($message, '{p}');
        $actual = substr_count((string) $catalog[$message], '{p}');

        if ($expected !== $actual) {
            $problems[] = sprintf('%s: placeholder mismatch in "%s"', $locale, mb_substr($message, 0, 60));
        }
    }
}

if ($problems === []) {
    fwrite(STDOUT, sprintf("OK: %d messages × %d locales are consistent\n", count($source), count($locales)));

    exit(0);
}

fwrite(STDERR, sprintf("i18n problems: %d\n", count($problems)));

foreach ($problems as $problem) {
    fwrite(STDERR, '  - ' . $problem . "\n");
}

exit(1);
