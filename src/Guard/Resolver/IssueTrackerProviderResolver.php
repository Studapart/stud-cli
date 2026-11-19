<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Enum\WorkItemCommandProfile;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\DTO\ProviderResolutionResult;
use App\Service\DualTrackerPolicy;

/**
 * Central issue-tracker provider resolution for guard and runtime (SCI-197 R1–R5).
 */
final class IssueTrackerProviderResolver
{
    public function __construct(
        private readonly ProviderHintResolver $hintResolver = new ProviderHintResolver(),
        private readonly ConfiguredProviderResolver $configuredResolver = new ConfiguredProviderResolver(),
        private readonly DualTrackerPolicy $dualTracker = new DualTrackerPolicy(),
    ) {
    }

    public function resolve(ProviderResolutionRequest $request): ProviderResolutionResult
    {
        if ($request->cliOverride !== null) {
            $provider = IssueTrackerProvider::tryFromNormalized($request->cliOverride);
            if ($provider === null || $provider->isAuto()) {
                return ProviderResolutionResult::blocked(
                    MessageRef::key('issue_tracker_provider.invalid_override', ['%value%' => $request->cliOverride]),
                );
            }

            return ProviderResolutionResult::single([$provider->vendorSlug()], 'override');
        }

        $projectConfig = $request->projectConfig ?? [];

        if ($request->commandProfile === WorkItemCommandProfile::SearchSingle
            && $this->dualTracker->isDualAuto($request->globalConfig, $projectConfig)) {
            return ProviderResolutionResult::blocked(
                MessageRef::key('issue_tracker_provider.search_requires_explicit_provider'),
            );
        }

        $result = $this->hintResolver->resolve($request, $projectConfig)
            ?? $this->configuredResolver->resolve($request, $projectConfig);

        return $this->applyCommandRule($request->commandProfile, $result);
    }

    private function applyCommandRule(
        WorkItemCommandProfile $profile,
        ProviderResolutionResult $result,
    ): ProviderResolutionResult {
        if ($profile === WorkItemCommandProfile::LinearOnly
            && count($result->providers) === 1
            && $result->providers[0] === IssueTrackerProvider::Jira->value) {
            return ProviderResolutionResult::blocked(
                MessageRef::key('project.labels.requires_linear'),
            );
        }

        return $result;
    }
}
