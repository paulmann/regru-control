<?php

/**
 * Secret and personal-data scanner.
 *
 *   php tools/secret-scan.php --repo=.
 *   php tools/secret-scan.php --deny-file=path/to/local-denylist.txt
 *
 * The committed rules cover generic secret shapes. Real values of a particular
 * installation (hostnames, IPs, logins, passwords, real domains) belong in a
 * local denylist that is never committed: one needle per line, `#` comments.
 */

declare(strict_types=1);

$options = getopt('', ['repo::', 'deny-file::']);
$root = realpath((string) ($options['repo'] ?? dirname(__DIR__))) ?: dirname(__DIR__);
$denyFile = isset($options['deny-file']) ? (string) $options['deny-file'] : null;

$needles = [];

if ($denyFile !== null && is_file($denyFile)) {
    foreach (file($denyFile, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $needles[$line] = 'local denylist';
    }
}

$patterns = [
    '/-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----/' => 'private key',
    '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/' => 'AWS access key',
    '/\bgh[pousr]_[A-Za-z0-9]{30,}\b/' => 'GitHub token',
    '/\brg_[A-Za-z0-9_-]{30,}\b/' => 'REG.RU Control API token',
    '/\b(?:password|passwd|pwd|secret|token)\s*[:=]\s*[\'"][^\'"\s]{8,}[\'"]/i' => 'hardcoded credential',
    '/\b\d{1,3}(?:\.\d{1,3}){3}\b/' => 'IPv4 address',
];

$skipDirectories = ['.git', 'vendor', 'var', 'build', 'node_modules'];
$skipFiles = ['.env', '.env.deploy'];
$extensions = ['php', 'js', 'mjs', 'css', 'html', 'md', 'json', 'yml', 'yaml', 'sql', 'sh', 'ps1', 'txt', 'example', 'conf'];

$findings = [];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
        continue;
    }

    $path = str_replace('\\', '/', $file->getPathname());
    $relative = ltrim(str_replace(str_replace('\\', '/', $root), '', $path), '/');

    foreach ($skipDirectories as $skip) {
        if (str_contains('/' . $relative, '/' . $skip . '/')) {
            continue 2;
        }
    }

    if (in_array(basename($relative), $skipFiles, true)) {
        continue;
    }

    $extension = strtolower($file->getExtension());

    if (basename($relative) === '.env.example') {
        $extension = 'example';
    }

    if (!in_array($extension, $extensions, true)) {
        continue;
    }

    $content = (string) file_get_contents($file->getPathname());

    foreach ($needles as $needle => $why) {
        if ($needle !== '' && str_contains($content, $needle)) {
            $findings[] = sprintf('%s: contains forbidden value (%s)', $relative, $why);
        }
    }

    foreach ($patterns as $pattern => $label) {
        if (preg_match($pattern, $content, $match) !== 1) {
            continue;
        }

        $value = $match[0];

        // Placeholders and documentation examples are fine.
        if (preg_match('/example\.com|example\.org|203\.0\.113\.|198\.51\.100\.|192\.0\.2\.|127\.0\.0\.1|0\.0\.0\.0|YOUR_|CHANGE_ME|ВАШ|ваш_|<[a-z_]+>/iu', $value) === 1) {
            continue;
        }

        // Documentation examples and test fixtures are informational only.
        if ($label === 'hardcoded credential' && (str_ends_with($relative, '.md') || str_contains($relative, 'backend/tests/'))) {
            continue;
        }

        $findings[] = sprintf('%s: %s (%s)', $relative, $label, mb_substr($value, 0, 60));
    }
}

$findings = array_values(array_unique($findings));
sort($findings);

if ($findings === []) {
    fwrite(STDOUT, "OK: no secrets or personal data found in {$root}\n");
    exit(0);
}

fwrite(STDERR, "Found " . count($findings) . " potential leak(s):\n");

foreach ($findings as $finding) {
    fwrite(STDERR, '  - ' . $finding . "\n");
}

exit(1);
