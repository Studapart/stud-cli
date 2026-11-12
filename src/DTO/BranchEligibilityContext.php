<?php

declare(strict_types=1);

namespace App\DTO;

final class BranchEligibilityContext
{
    /**
     * @param array<string, array<string, mixed>> $prMap Pull requests keyed by head branch name
     */
    public function __construct(
        public readonly string $currentBranch,
        public readonly ?string $baseBranch,
        public readonly array $prMap,
        public readonly bool $providerDataAvailable,
    ) {
    }
}
