<?php

declare(strict_types=1);

$path = $argv[1] ?? '';
if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Clover file is required.\n");
    exit(2);
}

$xml = simplexml_load_file($path);
if ($xml === false) {
    fwrite(STDERR, "Clover file is not valid XML.\n");
    exit(2);
}

$failures = [];
$baseline = loadBaseline($argv[2] ?? '');
foreach ($xml->xpath('//line[@type="method" and @crap]') ?: [] as $line) {
    $crap = (float) $line['crap'];
    if ($crap <= 10) {
        continue;
    }

    $fileNodes = $line->xpath('ancestor::file[1]') ?: [];
    $file = $fileNodes === [] ? '' : (string) $fileNodes[0]['name'];
    $relative = relativePath($file);
    $name = (string) ($line['name'] ?? 'method');
    $key = $relative . ' ' . $name;
    if (isset($baseline[$key])) {
        if ($crap <= $baseline[$key]) {
            continue;
        }
        $failures[] = sprintf('%s %s crap %s above recorded %s', $relative, $name, (string) $line['crap'], (string) $baseline[$key]);
        continue;
    }

    $failures[] = sprintf('%s %s crap %s', $relative, $name, (string) $line['crap']);
}

if ($failures === []) {
    exit(0);
}

fwrite(STDERR, "CRAP gate failed:\n");
foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}

exit(1);

/**
 * @return array<string, float>
 */
function loadBaseline(string $path): array
{
    if ($path === '') {
        return [];
    }
    if (!is_file($path)) {
        fwrite(STDERR, "CRAP baseline is not a file.\n");
        exit(2);
    }

    $entries = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = preg_split('/\s+/', $line) ?: [];
        $score = array_pop($parts);
        $name = array_pop($parts);
        if ($score === null || $name === null || !is_numeric($score) || $parts === []) {
            fwrite(STDERR, "CRAP baseline line must be \"file method score\": {$line}\n");
            exit(2);
        }
        $entries[implode(' ', $parts) . ' ' . $name] = (float) $score;
    }

    return $entries;
}

function relativePath(string $file): string
{
    $cwd = getcwd();
    if ($cwd !== false && str_starts_with($file, $cwd . '/')) {
        return substr($file, strlen($cwd) + 1);
    }

    return $file;
}

