<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\Service\IssueTrackerFactory;
use App\Service\IssueTrackerResolver;
use App\Service\ProjectScopeKeyResolver;

final class IssueTrackerResolutionDeps
{
    public function __construct(
        public readonly IssueTrackerFactory $factory = new IssueTrackerFactory(),
        public readonly IssueTrackerResolver $resolver = new IssueTrackerResolver(),
        public readonly ProjectScopeKeyResolver $scope = new ProjectScopeKeyResolver(),
    ) {
    }
}
