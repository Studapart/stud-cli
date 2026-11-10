<?php

declare(strict_types=1);

namespace App\Service;

final class BranchCleanupPipeline
{
    public function __construct(
        public readonly BranchDeletionEligibilityResolver $eligibility,
        public readonly BranchCleanupExecutor $executor,
        public readonly BranchCleanupPlanner $planner,
    ) {
    }
}
