<?php

declare(strict_types=1);

namespace App\DTO;

class PullRequestData
{
    public function __construct(
        public readonly string $title,
        public readonly string $head,
        public readonly string $base,
        public readonly string $body,
        public readonly bool $draft = false,
    ) {
    }
}
