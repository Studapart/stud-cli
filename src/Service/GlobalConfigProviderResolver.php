<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\GitProvider;
use App\Enum\WorkItemProvider;

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
    public function normalizeWorkItemProviders(array $values): array
    {
        $normalized = [];
        foreach ($values as $provider) {
            $enum = WorkItemProvider::tryFrom(strtolower(trim($provider)));
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
        if (isset($global['GIT_PROVIDERS']) && is_array($global['GIT_PROVIDERS'])) {
            $normalized = $this->normalizeGitProviders($this->coerceStringList($global['GIT_PROVIDERS']));
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
        if ($this->nonEmptyStoredString($global['GITHUB_TOKEN'] ?? null) !== null) {
            $providers[] = GitProvider::Github->value;
        }
        if ($this->nonEmptyStoredString($global['GITLAB_TOKEN'] ?? null) !== null) {
            $providers[] = GitProvider::Gitlab->value;
        }

        if ($providers === []) {
            $legacyToken = $this->nonEmptyStoredString($global['GIT_TOKEN'] ?? null);
            $legacyProvider = isset($global['GIT_PROVIDER']) && is_string($global['GIT_PROVIDER'])
                ? strtolower(trim($global['GIT_PROVIDER']))
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
    public function resolveWorkItemProviders(array $global): array
    {
        if (isset($global['WORK_ITEM_PROVIDERS']) && is_array($global['WORK_ITEM_PROVIDERS'])) {
            $normalized = $this->normalizeWorkItemProviders($this->coerceStringList($global['WORK_ITEM_PROVIDERS']));
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return $this->inferWorkItemProvidersFromLegacy($global);
    }

    /**
     * @param array<string, mixed> $global
     * @return list<string>
     */
    public function inferWorkItemProvidersFromLegacy(array $global): array
    {
        $providers = $this->inferWorkItemProvidersFromCredentials($global);
        if ($providers !== []) {
            return $providers;
        }

        return [WorkItemProvider::Jira->value];
    }

    /**
     * @param array<string, mixed> $global
     * @return list<string>
     */
    public function inferWorkItemProvidersFromCredentials(array $global): array
    {
        $providers = [];
        if ($this->nonEmptyStoredString($global['JIRA_URL'] ?? null) !== null) {
            $providers[] = WorkItemProvider::Jira->value;
        }
        if ($this->nonEmptyStoredString($global['LINEAR_API_KEY'] ?? null) !== null) {
            $providers[] = WorkItemProvider::Linear->value;
        }

        $providers = array_values(array_unique($providers));
        sort($providers);

        return $providers;
    }

    /**
     * @param array<string, mixed> $existingConfig
     * @return list<string>
     */
    public function inferDefaultWorkItemProviders(array $existingConfig): array
    {
        $hasJira = $this->nonEmptyStoredString($existingConfig['JIRA_URL'] ?? null) !== null;
        $hasLinear = $this->nonEmptyStoredString($existingConfig['LINEAR_API_KEY'] ?? null) !== null;

        if ($hasJira && $hasLinear) {
            return [WorkItemProvider::Jira->value, WorkItemProvider::Linear->value];
        }
        if ($hasLinear) {
            return [WorkItemProvider::Linear->value];
        }

        return [WorkItemProvider::Jira->value];
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
     * @param list<string> $workItemProviders
     */
    public function collectsJira(array $workItemProviders): bool
    {
        return in_array(WorkItemProvider::Jira->value, $workItemProviders, true);
    }

    /**
     * @param list<string> $workItemProviders
     */
    public function collectsLinear(array $workItemProviders): bool
    {
        return in_array(WorkItemProvider::Linear->value, $workItemProviders, true);
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
