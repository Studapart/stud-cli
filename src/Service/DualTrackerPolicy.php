<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\EffectiveIssueTrackerCredentials;
use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;

/**
 * Owns the dual-tracker rule: the project leaves the tracker on auto and both Jira and Linear
 * are configured with credentials. Commands ask this policy instead of repeating the check.
 */
class DualTrackerPolicy
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * A create run without --provider in a dual-auto project takes its tracker from the project lookup.
     *
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function createTakesTrackerFromLookup(?string $providerOverride, array $globalConfig, array $projectConfig): bool
    {
        if ($providerOverride !== null && trim($providerOverride) !== '') {
            return false;
        }

        return $this->isDualAuto($globalConfig, $projectConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function isDualAuto(array $globalConfig, array $projectConfig): bool
    {
        if (! $this->isProjectAuto($projectConfig)) {
            return false;
        }

        $credentials = (new EffectiveIssueTrackerCredentials())->overlay($globalConfig, $projectConfig);
        $providers = $this->globalResolver->resolveIssueTrackerProviders($credentials);
        if (! $this->globalResolver->collectsJira($providers)
            || ! $this->globalResolver->collectsLinear($providers)) {
            return false;
        }

        return GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, $credentials)
            && GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Linear, $credentials);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function isProjectAuto(array $projectConfig): bool
    {
        $stored = ProjectStudConfigKeys::readIssueTrackerProvider($projectConfig);

        return $stored === null || $stored === IssueTrackerProvider::Auto->value;
    }
}
