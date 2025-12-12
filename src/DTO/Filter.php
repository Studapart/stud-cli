<?php

declare(strict_types=1);

namespace App\DTO;

class Filter
{
    public function __construct(
        public string $name,
        public ?string $description = null,
    ) {
    }
}
