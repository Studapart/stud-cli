<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Enum\WorkItemCommandProfile;
use App\Exception\IssueTrackerResolutionException;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\DTO\ProviderResolutionResult;
use App\Service\DualTrackerPolicy;

/**
 * Resolves the issue-tracker provider from configuration when no invocation hint applies:
 * dual-tracker default, active project provider, then the single globally configured provider.
 */
class ConfiguredProviderResolver
{
    public function __construct(
        private readonly IssueTrackerResolutionDeps $deps = new IssueTrackerResolutionDeps(),
        private readonly DualTrackerPolicy $dualTracker = new DualTrackerPolicy(),
    ) {
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function resolve(ProviderResolutionRequest $request, array $projectConfig): ProviderResolutionResult
    {
        if (in_array($request->commandProfile, [WorkItemCommandProfile::DualAggregate, WorkItemCommandProfile::FilterByName], true)
            && $this->dualTracker->isDualAuto($request->globalConfig, $projectConfig)) {
            return ProviderResolutionResult::dualAggregate(
                [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                'dual_default',
            );
        }

        $active = $this->deps->resolver->resolveActiveProvider($request->globalConfig, $projectConfig);
        if ($active['ok']) {
            return ProviderResolutionResult::single([$active['provider']], 'project_config');
        }

        try {
            $provider = $this->deps->factory->resolveType(null, $request->globalConfig, $projectConfig, null);

            return ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($provider)->vendorSlug()], 'global_single');
        } catch (IssueTrackerResolutionException $e) {
            return ProviderResolutionResult::blocked($e->messageRef);
        } catch (\Throwable) {
            return ProviderResolutionResult::blocked(
                MessageRef::key('guard.error.ambiguous_issue_tracker_provider'),
            );
        }
    }
}
