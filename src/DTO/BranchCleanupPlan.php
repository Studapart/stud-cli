<?php

declare(strict_types=1);

namespace App\DTO;

use App\Enum\BranchCleanupLocalAction;
use App\Enum\BranchCleanupRemoteAction;

final class BranchCleanupPlan
{
    public function __construct(
        public readonly string $branch,
        public readonly BranchDeletionEligibility $eligibility,
        public readonly bool $remoteExists,
        public readonly BranchCleanupLocalAction $localAction,
        public readonly BranchCleanupRemoteAction $remoteAction,
    ) {
    }
}
