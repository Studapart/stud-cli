<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;

/**
 * Resolves which issue-tracker providers to query for dual-capable list commands.
 */
final class IssueTrackerProvidersQueryResolver
{
    public function __construct(
        private readonly ProjectScopeKeyResolver $scopeKeyResolver = new ProjectScopeKeyResolver(),
        private readonly GlobalConfigProviderResolver $globalProviderResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return list<string>
     */
    public function resolve(
        array $globalConfig,
        array $projectConfig,
        ?string $providerOverride,
        ?string $scopeKey = null,
    ): array {
        if ($providerOverride !== null) {
            return [$providerOverride];
        }

        $explicit = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);
        if ($explicit !== null && $explicit !== IssueTrackerProvider::Auto->value) {
            return [$explicit];
        }

        if ($scopeKey !== null && trim($scopeKey) !== '') {
            return $this->resolveForScope(trim($scopeKey), $globalConfig, $projectConfig);
        }

        if ($this->shouldDualAutoAggregate($globalConfig, $projectConfig)) {
            return [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return list<string>
     */
    private function resolveForScope(string $project, array $globalConfig, array $projectConfig): array
    {
        $scope = strtoupper($project);
        $matchJira = $this->scopeKeyResolver->scopeMatchesJira($scope, $projectConfig);
        $matchLinear = $this->scopeKeyResolver->scopeMatchesLinear($scope, $projectConfig);

        if ($matchJira && ! $matchLinear) {
            return [IssueTrackerProvider::Jira->value];
        }

        if ($matchLinear && ! $matchJira) {
            return [IssueTrackerProvider::Linear->value];
        }

        if ($this->shouldDualAutoAggregate($globalConfig, $projectConfig)) {
            return [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    private function shouldDualAutoAggregate(array $globalConfig, array $projectConfig): bool
    {
        unset($projectConfig);

        $providers = $this->globalProviderResolver->resolveIssueTrackerProviders($globalConfig);
        if (! $this->globalProviderResolver->collectsJira($providers)
            || ! $this->globalProviderResolver->collectsLinear($providers)) {
            return false;
        }

        return GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, $globalConfig)
            && GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Linear, $globalConfig);
    }
}
