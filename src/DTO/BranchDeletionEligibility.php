<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\BranchAutoCleanDecision;

final class BranchDeletionEligibility
{
    public function __construct(
        public readonly BranchAutoCleanDecision $decision,
        public readonly string $reason,
        public readonly string $status,
        public readonly bool $hasPullRequest,
    ) {
    }
}
