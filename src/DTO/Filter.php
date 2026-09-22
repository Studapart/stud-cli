<?php

declare(strict_types=1);

namespace App\DTO;

final class Filter
{
    public function __construct(
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly ?string $provider = null,
    ) {
    }
}
