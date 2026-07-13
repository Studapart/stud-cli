<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;
use App\Enum\WorkItemCommandProfile;
use App\Exception\IssueTrackerResolutionException;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\DTO\ProviderResolutionResult;
use App\Service\AttachmentUrlProviderGuesser;
use App\Service\GlobalConfigProviderResolver;
use App\Service\IssueTrackerFactory;
use App\Service\IssueTrackerResolver;
use App\Service\ProjectScopeKeyResolver;

/**
 * Central issue-tracker provider resolution for guard and runtime (SCI-197 R1–R5).
 */
final class IssueTrackerProviderResolver
{
    public function __construct(
        private readonly IssueTrackerFactory $issueTrackerFactory = new IssueTrackerFactory(),
        private readonly IssueTrackerResolver $issueTrackerResolver = new IssueTrackerResolver(),
        private readonly ProjectScopeKeyResolver $scopeKeyResolver = new ProjectScopeKeyResolver(),
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
        private readonly AttachmentUrlProviderGuesser $attachmentUrlGuesser = new AttachmentUrlProviderGuesser(),
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
            && $this->isDualPmWithCredentials($request->globalConfig, $projectConfig)) {
            return ProviderResolutionResult::blocked(
                MessageRef::key('issue_tracker_provider.search_requires_explicit_provider'),
            );
        }

        $issueKey = $this->effectiveIssueKey($request);
        if ($issueKey !== null && $this->profileUsesIssueKey($request->commandProfile)) {
            $keyResult = $this->resolveFromIssueKey($request->globalConfig, $projectConfig, $issueKey);

            if ($keyResult->isBlocked()) {
                return $keyResult;
            }

            return $this->applyCommandRule($request->commandProfile, $keyResult);
        }

        if ($request->attachmentUrl !== null
            && $request->commandProfile === WorkItemCommandProfile::KeySingle
            && $issueKey === null) {
            $guessed = $this->attachmentUrlGuesser->guess($request->attachmentUrl);
            if ($guessed === null) {
                return ProviderResolutionResult::blocked(
                    MessageRef::key('item.download.error_unknown_attachment_host'),
                );
            }

            return $this->applyCommandRule(
                $request->commandProfile,
                ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($guessed)->vendorSlug()], 'attachment_url'),
            );
        }

        $scopeKey = $this->effectiveScopeKey($request);
        if ($scopeKey !== null && $this->profileUsesScopeKey($request->commandProfile)) {
            $scopeResult = $this->scopeKeyResolver->resolveProviderForDiscoveryScope($scopeKey, $projectConfig);
            if (! $scopeResult['ok']) {
                return ProviderResolutionResult::blocked($scopeResult['error']);
            }

            return $this->applyCommandRule(
                $request->commandProfile,
                ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($scopeResult['provider'])->vendorSlug()], 'scope_key'),
            );
        }

        if ($request->commandProfile === WorkItemCommandProfile::DualAggregate
            || $request->commandProfile === WorkItemCommandProfile::FilterByName) {
            if ($this->isDualPmWithCredentials($request->globalConfig, $projectConfig)) {
                return ProviderResolutionResult::dualAggregate(
                    [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                    'dual_default',
                );
            }
        }

        $active = $this->issueTrackerResolver->resolveActiveProvider($request->globalConfig, $projectConfig);
        if ($active['ok']) {
            return $this->applyCommandRule(
                $request->commandProfile,
                ProviderResolutionResult::single([$active['provider']], 'project_config'),
            );
        }

        try {
            $provider = $this->issueTrackerFactory->resolveType(
                null,
                $request->globalConfig,
                $projectConfig,
                null,
            );

            return $this->applyCommandRule(
                $request->commandProfile,
                ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($provider)->vendorSlug()], 'global_single'),
            );
        } catch (IssueTrackerResolutionException $e) {
            return ProviderResolutionResult::blocked($e->messageRef);
        } catch (\Throwable) {
            return ProviderResolutionResult::blocked(
                MessageRef::key('guard.error.ambiguous_issue_tracker_provider'),
            );
        }
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function resolveFromIssueKey(
        array $globalConfig,
        array $projectConfig,
        string $issueKey,
    ): ProviderResolutionResult {
        try {
            $provider = $this->issueTrackerFactory->resolveType(
                null,
                $globalConfig,
                $projectConfig,
                $issueKey,
            );

            return ProviderResolutionResult::single([IssueTrackerProvider::fromResolved($provider)->vendorSlug()], 'issue_key');
        } catch (IssueTrackerResolutionException $e) {
            return ProviderResolutionResult::blocked($e->messageRef);
        }
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

    private function effectiveIssueKey(ProviderResolutionRequest $request): ?string
    {
        foreach ([$request->issueKey, $request->branchIssueKey] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return strtoupper(trim($candidate));
            }
        }

        return null;
    }

    private function effectiveScopeKey(ProviderResolutionRequest $request): ?string
    {
        if (is_string($request->scopeKey) && trim($request->scopeKey) !== '') {
            return strtoupper(trim($request->scopeKey));
        }

        return null;
    }

    private function profileUsesIssueKey(WorkItemCommandProfile $profile): bool
    {
        return in_array($profile, [
            WorkItemCommandProfile::KeySingle,
            WorkItemCommandProfile::KeyOrBranch,
        ], true);
    }

    private function profileUsesScopeKey(WorkItemCommandProfile $profile): bool
    {
        return in_array($profile, [
            WorkItemCommandProfile::ScopeDiscovery,
            WorkItemCommandProfile::LinearOnly,
        ], true);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function isDualPmWithCredentials(array $globalConfig, array $projectConfig): bool
    {
        if (! $this->isProjectAuto($projectConfig)) {
            return false;
        }

        $providers = $this->globalResolver->resolveIssueTrackerProviders($globalConfig);
        if (! $this->globalResolver->collectsJira($providers)
            || ! $this->globalResolver->collectsLinear($providers)) {
            return false;
        }

        return GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, $globalConfig)
            && GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Linear, $globalConfig);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function isProjectAuto(array $projectConfig): bool
    {
        $stored = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);
        if ($stored === null) {
            return true;
        }

        return $stored === IssueTrackerProvider::Auto->value;
    }
}
