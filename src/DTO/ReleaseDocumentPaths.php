<?php

declare(strict_types=1);

namespace App\DTO;

final class ReleaseDocumentPaths
{
    public function __construct(
        public readonly string $composerJson = 'composer.json',
        public readonly string $changelog = 'CHANGELOG.md',
        public readonly string $readme = 'README.md',
    ) {
    }
}
