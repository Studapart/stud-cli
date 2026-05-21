<?php

declare(strict_types=1);

namespace App\DTO;

final class PullRequestFeedbackLocation
{
    public function __construct(
        public readonly ?string $path = null,
        public readonly ?int $line = null,
        public readonly ?int $startLine = null,
        public readonly ?string $side = null,
        public readonly ?int $originalLine = null,
    ) {
    }
}
