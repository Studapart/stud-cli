<?php

declare(strict_types=1);

namespace App\DTO;

final class SubmitOptions
{
    public function __construct(
        public readonly bool $draft = false,
        public readonly ?string $labels = null,
        public readonly bool $quiet = false,
        public readonly bool $assignToAuthor = false,
    ) {
    }
}
