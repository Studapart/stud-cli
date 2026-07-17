<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigKeys;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;

/**
 * Builds operator hints when a pinned-provider fetch fails for an issue key.
 */
class IssueTrackerFetchFailureHintBuilder
{
    public function __construct(
        private readonly IssueTrackerFactory $factory = new IssueTrackerFactory(),
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function build(
        string $issueKey,
        string $attemptedProvider,
        ?string $cliOverride,
        array $globalConfig,
        array $projectConfig,
    ): ?MessageRef {
        if ($cliOverride !== null && trim($cliOverride) !== '') {
            return null;
        }

        $pinned = $this->factory->readPinnedProvider($projectConfig);
        if ($pinned === null) {
            return null;
        }

        $claiming = $this->factory->providerClaimingIssueKey($issueKey, $projectConfig);
        if ($claiming !== null && $claiming->vendorSlug() !== $attemptedProvider) {
            if (! GlobalStudConfigKeys::hasCredentialsFor($claiming, $globalConfig)) {
                return null;
            }

            $prefix = $this->factory->issueKeyPrefixOrNull($issueKey) ?? strtoupper(trim($issueKey));

            return MessageRef::key('issue_tracker_provider.fetch_failed_prefix_matches_other', [
                '%key%' => strtoupper(trim($issueKey)),
                '%attempted%' => $attemptedProvider,
                '%prefix%' => $prefix,
                '%alternate%' => $claiming->vendorSlug(),
            ]);
        }

        if (! $this->isDualPmConfigured($globalConfig)) {
            return null;
        }

        return MessageRef::key('issue_tracker_provider.fetch_failed_suggest_override', [
            '%key%' => strtoupper(trim($issueKey)),
            '%attempted%' => $attemptedProvider,
        ]);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    private function isDualPmConfigured(array $globalConfig): bool
    {
        $providers = $this->globalResolver->resolveIssueTrackerProviders($globalConfig);
        if (! $this->globalResolver->collectsJira($providers)
            || ! $this->globalResolver->collectsLinear($providers)) {
            return false;
        }

        return GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, $globalConfig)
            && GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Linear, $globalConfig);
    }
}
