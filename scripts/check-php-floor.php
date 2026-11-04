<?php

declare(strict_types=1);

$version = $argv[1] ?? PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION;
if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $version, $matches)) {
    fwrite(STDERR, "PHP version is not major.minor.patch: {$version}\n");
    exit(2);
}

$major = (int) $matches[1];
$minor = (int) $matches[2];
$patch = (int) $matches[3];
$accepted = $major > 8
    || ($major === 8 && $minor > 5)
    || ($major === 8 && $minor === 5 && $patch >= 12);
if ($accepted) {
    exit(0);
}

fwrite(STDERR, "PHP {$version} found. PHP 8.5.12 or newer is required.\n");
exit(1);
