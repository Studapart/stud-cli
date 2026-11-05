<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\ConfigValidatePlan;
use App\DTO\MessageRef;
use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerResolutionException;

/**
 * Builds a config:validate plan from configuration only.
 */
final class ConfigValidatePlanner
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
        private readonly ProjectScopeKeyResolver $scopeKeyResolver = new ProjectScopeKeyResolver(),
        private readonly IssueTrackerFactory $issueTrackerFactory = new IssueTrackerFactory(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function plan(
        array $globalConfig,
        array $projectConfig,
        bool $inRepo,
        bool $skipJira,
        bool $skipLinear,
        bool $skipGit,
        ?string $detectedGitHost = null,
        ?MessageRef $projectConfigReadError = null,
    ): ConfigValidatePlan {
        $trackers = $this->globalResolver->resolveIssueTrackerProviders($globalConfig);
        $gitProviders = $this->globalResolver->resolveGitProviders($globalConfig);
        $hasStoredProjectConfig = $projectConfig !== [];
        $hasProjectConfig = $hasStoredProjectConfig || $projectConfigReadError !== null;
        $selection = $projectConfigReadError !== null
            ? ['tracker' => null, 'error' => $projectConfigReadError]
            : $this->selectTracker($globalConfig, $projectConfig, $hasStoredProjectConfig);

        return new ConfigValidatePlan(
            $inRepo,
            $hasProjectConfig,
            $this->globalResolver->collectsJira($trackers) && ! $skipJira,
            $this->globalResolver->collectsLinear($trackers) && ! $skipLinear,
            $this->collectsGit($gitProviders) && ! $skipGit,
            $this->globalResolver->collectsGithub($gitProviders),
            $this->globalResolver->collectsGitlab($gitProviders),
            $selection['tracker'],
            $selection['error'],
            $this->projectGit($projectConfig, $hasProjectConfig, $detectedGitHost),
            $skipJira,
            $skipLinear,
        );
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return array{tracker: ?IssueTrackerProvider, error: ?MessageRef}
     */
    private function selectTracker(array $globalConfig, array $projectConfig, bool $hasProjectConfig): array
    {
        if (! $hasProjectConfig) {
            return ['tracker' => null, 'error' => null];
        }

        $pinned = $this->issueTrackerFactory->readPinnedProvider($projectConfig);
        if ($pinned !== null) {
            return ['tracker' => $pinned, 'error' => null];
        }

        return $this->selectUnpinnedTracker($globalConfig, $projectConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return array{tracker: ?IssueTrackerProvider, error: ?MessageRef}
     */
    private function selectUnpinnedTracker(array $globalConfig, array $projectConfig): array
    {
        $listed = $this->listedTrackers($globalConfig);
        if (count($listed) === 1) {
            return ['tracker' => $listed[0], 'error' => null];
        }

        $jiraKey = $this->scopeKeyResolver->resolveJiraProjectKey($projectConfig);
        $explicitLinearKey = $this->explicitLinearTeamKey($projectConfig);
        $globalTrackers = $this->globalResolver->resolveIssueTrackerProviders($globalConfig);

        if ($jiraKey !== null && $explicitLinearKey === null && $this->globalResolver->collectsJira($globalTrackers)) {
            return ['tracker' => IssueTrackerProvider::Jira, 'error' => null];
        }

        if ($explicitLinearKey !== null && $jiraKey === null && $this->globalResolver->collectsLinear($globalTrackers)) {
            return ['tracker' => IssueTrackerProvider::Linear, 'error' => null];
        }

        $scopeKey = $jiraKey ?? $explicitLinearKey;
        if ($scopeKey !== null) {
            return $this->trackerFromScope($scopeKey, $projectConfig);
        }

        return [
            'tracker' => null,
            'error' => count($listed) > 1
                ? IssueTrackerResolutionException::autoRequiresIssueKey()->messageRef
                : MessageRef::key('guard.error.ambiguous_issue_tracker_provider'),
        ];
    }

    /**
     * @param array<string, mixed> $projectConfig
     *
     * @return array{tracker: ?IssueTrackerProvider, error: ?MessageRef}
     */
    private function trackerFromScope(string $scopeKey, array $projectConfig): array
    {
        $resolved = $this->scopeKeyResolver->resolveProviderForDiscoveryScope($scopeKey, $projectConfig);
        if (! $resolved['ok']) {
            return ['tracker' => null, 'error' => $resolved['error']];
        }

        return [
            'tracker' => IssueTrackerProvider::fromResolved($resolved['provider']),
            'error' => null,
        ];
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function explicitLinearTeamKey(array $projectConfig): ?string
    {
        $linearTeamKey = $projectConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] ?? null;
        if (! is_string($linearTeamKey) || trim($linearTeamKey) === '') {
            return null;
        }

        return strtoupper(trim($linearTeamKey));
    }

    /**
     * @param array<string, mixed> $globalConfig
     *
     * @return list<IssueTrackerProvider>
     */
    private function listedTrackers(array $globalConfig): array
    {
        $providers = $this->globalResolver->resolveIssueTrackerProviders($globalConfig);
        $listed = [];
        if ($this->globalResolver->collectsJira($providers)) {
            $listed[] = IssueTrackerProvider::Jira;
        }
        if ($this->globalResolver->collectsLinear($providers)) {
            $listed[] = IssueTrackerProvider::Linear;
        }

        return $listed;
    }

    /**
     * @param list<string> $gitProviders
     */
    private function collectsGit(array $gitProviders): bool
    {
        return $this->globalResolver->collectsGithub($gitProviders)
            || $this->globalResolver->collectsGitlab($gitProviders);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function projectGit(array $projectConfig, bool $hasProjectConfig, ?string $detectedGitHost): ?GitProvider
    {
        $configured = $projectConfig[ProjectStudConfigKeys::GIT_PROVIDER] ?? null;
        $fromConfig = GitProvider::tryFromProjectValue(is_string($configured) ? $configured : null);
        if ($fromConfig !== null || ! $hasProjectConfig) {
            return $fromConfig;
        }

        return GitProvider::tryFromProjectValue($detectedGitHost);
    }
}
