<?php

declare(strict_types=1);

namespace App\DTO;

final class Project
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $provider = null,
    ) {
    }
}
