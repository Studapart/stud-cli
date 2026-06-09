<?php

declare(strict_types=1);

namespace App\Service;

final class UpdateRepositoryContext
{
    public function __construct(
        public readonly string $owner,
        public readonly string $name,
        public readonly ?string $token = null,
    ) {
    }
}
