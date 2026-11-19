<?php

declare(strict_types=1);

namespace App\Service;

final readonly class UpdateTarget
{
    public function __construct(
        public UpdateRepositoryContext $repository,
        public string $currentVersion,
        public string $binaryPath,
    ) {
    }
}
