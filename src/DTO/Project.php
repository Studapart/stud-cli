<?php

declare(strict_types=1);

namespace App\DTO;

class Project
{
    public function __construct(
        public string $key,
        public string $name,
    ) {}
}
