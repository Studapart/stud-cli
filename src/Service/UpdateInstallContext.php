<?php

declare(strict_types=1);

namespace App\Service;

final class UpdateInstallContext
{
    public const MODE_PHAR = 'phar';
    public const MODE_PORTABLE = 'portable';

    public function __construct(
        public readonly string $mode,
        public readonly string $currentVersion,
        public readonly string $binaryPath,
        public readonly ?string $platform = null,
        public readonly ?string $bundleRoot = null,
        public readonly ?string $portableRoot = null,
        public readonly ?string $launcherPath = null,
        public readonly ?string $managedSymlink = null,
        public readonly bool $legacyPortableLayout = false,
    ) {
    }
}
