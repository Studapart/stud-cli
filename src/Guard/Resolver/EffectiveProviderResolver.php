<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\Enum\IssueTrackerProvider;
use App\Service\DualTrackerPolicy;
use App\Service\GlobalConfigProviderResolver;
use App\Service\IssueTrackerResolver;

/**
 * Resolves git and work-item providers in the context of the current command run.
 *
 * Global GIT_PROVIDERS / ISSUE_TRACKER_PROVIDERS describe what is configured globally;
 * project config and git remote detection narrow what this command actually needs.
 */
class EffectiveProviderResolver
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
        private readonly IssueTrackerResolver $issueTrackerResolver = new IssueTrackerResolver(),
        private readonly DualTrackerPolicy $dualTracker = new DualTrackerPolicy(),
    ) {
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @return list<string>
     */
    public function resolveGitProviders(
        array $globalConfig,
        ?array $projectConfig,
        bool $hasGitRepository,
        ?string $resolvedGitProvider,
    ): array {
        if ($resolvedGitProvider !== null && in_array($resolvedGitProvider, ['github', 'gitlab'], true)) {
            return [$resolvedGitProvider];
        }

        if ($hasGitRepository && $projectConfig !== null) {
            $stored = $projectConfig['gitProvider'] ?? null;
            if (is_string($stored) && in_array($stored, ['github', 'gitlab'], true)) {
                return [$stored];
            }
        }

        return $this->globalResolver->resolveGitProviders($globalConfig);
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @return array{providers: list<string>, ambiguous: bool}
     */
    public function resolveIssueTrackerProviders(
        array $globalConfig,
        ?array $projectConfig,
        ?string $providerOverride = null,
        bool $dualAutoAggregate = false,
    ): array {
        if ($providerOverride !== null) {
            return ['providers' => [$providerOverride], 'ambiguous' => false];
        }

        if ($projectConfig === null) {
            return [
                'providers' => $this->globalResolver->resolveIssueTrackerProviders($globalConfig),
                'ambiguous' => false,
            ];
        }

        $active = $this->issueTrackerResolver->resolveActiveProvider($globalConfig, $projectConfig);
        if ($active['ok']) {
            return ['providers' => [$active['provider']], 'ambiguous' => false];
        }

        if ($dualAutoAggregate && $this->dualTracker->isDualAuto($globalConfig, $projectConfig)) {
            return [
                'providers' => [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value],
                'ambiguous' => false,
            ];
        }

        return [
            'providers' => $this->globalResolver->resolveIssueTrackerProviders($globalConfig),
            'ambiguous' => true,
        ];
    }
}
