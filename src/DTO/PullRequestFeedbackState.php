<?php

declare(strict_types=1);

namespace App\DTO;

final class PullRequestFeedbackState
{
    public function __construct(
        public readonly ?bool $resolved = null,
        public readonly ?bool $resolvable = null,
        public readonly ?bool $outdated = null,
        public readonly ?bool $minimized = null,
        public readonly ?bool $dismissed = null,
        public readonly ?string $providerState = null,
        public readonly ?string $resolvedBy = null,
        public readonly ?\DateTimeInterface $resolvedAt = null,
    ) {
    }
}
