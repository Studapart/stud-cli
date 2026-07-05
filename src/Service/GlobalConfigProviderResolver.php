<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigKeys;
use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;

/**
 * Normalizes and infers global Git / work-item provider lists for config:init.
 */
class GlobalConfigProviderResolver
{
    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function normalizeGitProviders(array $values): array
    {
        $normalized = [];
        foreach ($values as $provider) {
            $enum = GitProvider::tryFrom(strtolower(trim($provider)));
            if ($enum !== null) {
                $normalized[] = $enum->value;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    public function normalizeIssueTrackerProviders(array $values): array
    {
        $normalized = [];
        foreach ($values as $provider) {
            $enum = IssueTrackerProvider::tryFromNormalized($provider);
            if ($enum !== null) {
                $normalized[] = $enum->value;
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return list<string>
     */
    public function inferDefaultGitProviders(array $existingConfig): array
    {
        $fromCredentials = $this->inferGitProvidersFromLegacy($existingConfig);
        if ($fromCredentials !== []) {
            return $fromCredentials;
        }

        return [GitProvider::Github->value];
    }

    /**
     * @param array<string, mixed> $global
     * @return list<string>
     */
    public function resolveGitProviders(array $global): array
    {
        if (isset($global[GlobalStudConfigKeys::GIT_PROVIDERS]) && is_array($global[GlobalStudConfigKeys::GIT_PROVIDERS])) {
            $normalized = $this->normalizeGitProviders($this->coerceStringList($global[GlobalStudConfigKeys::GIT_PROVIDERS]));
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return $this->inferGitProvidersFromLegacy($global);
    }

    /**
     * @param array<string, mixed> $global
     * @return list<string>
     */
    public function inferGitProvidersFromLegacy(array $global): array
    {
        $providers = [];
        if ($this->nonEmptyStoredString($global[GlobalStudConfigKeys::GITHUB_TOKEN] ?? null) !== null) {
            $providers[] = GitProvider::Github->value;
        }
        if ($this->nonEmptyStoredString($global[GlobalStudConfigKeys::GITLAB_TOKEN] ?? null) !== null) {
            $providers[] = GitProvider::Gitlab->value;
        }

        if ($providers === []) {
            $legacyToken = $this->nonEmptyStoredString($global[GlobalStudConfigKeys::GIT_TOKEN] ?? null);
            $legacyProvider = isset($global[GlobalStudConfigKeys::GIT_PROVIDER]) && is_string($global[GlobalStudConfigKeys::GIT_PROVIDER])
                ? strtolower(trim($global[GlobalStudConfigKeys::GIT_PROVIDER]))
                : null;
            if ($legacyToken !== null && in_array($legacyProvider, ['github', 'gitlab'], true)) {
                $providers[] = $legacyProvider;
            }
        }

        $providers = array_values(array_unique($providers));
        sort($providers);

        return $providers;
    }

    /**
     * @param array<string, mixed> $global
     * @return list<string>
     */
    public function resolveIssueTrackerProviders(array $global): array
    {
        $storedList = GlobalStudConfigKeys::readIssueTrackerProvidersList($global);
        if ($storedList !== null) {
            $normalized = $this->normalizeIssueTrackerProviders($storedList);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return $this->inferIssueTrackerProvidersFromLegacy($global);
    }

    /**
     * @param array<string, mixed> $global
     * @return list<string>
     */
    public function inferIssueTrackerProvidersFromLegacy(array $global): array
    {
        $providers = $this->inferIssueTrackerProvidersFromCredentials($global);
        if ($providers !== []) {
            return $providers;
        }

        return [IssueTrackerProvider::Jira->value];
    }

    /**
     * @param array<string, mixed> $global
     * @return list<string>
     */
    public function inferIssueTrackerProvidersFromCredentials(array $global): array
    {
        $providers = [];
        if ($this->nonEmptyStoredString($global[GlobalStudConfigKeys::JIRA_URL] ?? null) !== null) {
            $providers[] = IssueTrackerProvider::Jira->value;
        }
        if ($this->nonEmptyStoredString($global[GlobalStudConfigKeys::LINEAR_API_KEY] ?? null) !== null) {
            $providers[] = IssueTrackerProvider::Linear->value;
        }

        $providers = array_values(array_unique($providers));
        sort($providers);

        return $providers;
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return list<string>
     */
    public function inferDefaultIssueTrackerProviders(array $existingConfig): array
    {
        $hasJira = $this->nonEmptyStoredString($existingConfig[GlobalStudConfigKeys::JIRA_URL] ?? null) !== null;
        $hasLinear = $this->nonEmptyStoredString($existingConfig[GlobalStudConfigKeys::LINEAR_API_KEY] ?? null) !== null;

        if ($hasJira && $hasLinear) {
            return [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value];
        }
        if ($hasLinear) {
            return [IssueTrackerProvider::Linear->value];
        }

        return [IssueTrackerProvider::Jira->value];
    }

    /**
     * @param list<string> $gitProviders
     */
    public function collectsGithub(array $gitProviders): bool
    {
        return in_array(GitProvider::Github->value, $gitProviders, true);
    }

    /**
     * @param list<string> $gitProviders
     */
    public function collectsGitlab(array $gitProviders): bool
    {
        return in_array(GitProvider::Gitlab->value, $gitProviders, true);
    }

    /**
     * @param list<string> $issueTrackerProviders
     */
    public function collectsIssueTracker(IssueTrackerProvider $provider, array $issueTrackerProviders): bool
    {
        if ($provider === IssueTrackerProvider::Auto) {
            return false;
        }

        return in_array($provider->value, $issueTrackerProviders, true);
    }

    /**
     * @param list<string> $issueTrackerProviders
     */
    public function collectsJira(array $issueTrackerProviders): bool
    {
        return $this->collectsIssueTracker(IssueTrackerProvider::Jira, $issueTrackerProviders);
    }

    /**
     * @param list<string> $issueTrackerProviders
     */
    public function collectsLinear(array $issueTrackerProviders): bool
    {
        return $this->collectsIssueTracker(IssueTrackerProvider::Linear, $issueTrackerProviders);
    }

    protected function nonEmptyStoredString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param array<mixed, mixed> $values
     * @return list<string>
     */
    protected function coerceStringList(array $values): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        }

        return $strings;
    }
}
