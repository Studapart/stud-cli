<?php

declare(strict_types=1);

namespace App\Service;

final class IssueTrackerScopeResolvers
{
    public function __construct(
        public readonly ProjectScopeKeyResolver $scope = new ProjectScopeKeyResolver(),
        public readonly DualTrackerPolicy $dualTracker = new DualTrackerPolicy(),
    ) {
    }
}
