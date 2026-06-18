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
        $hasGithub = $this->nonEmptyStoredString($existingConfig['GITHUB_TOKEN'] ?? null) !== null;
        $hasGitlab = $this->nonEmptyStoredString($existingConfig['GITLAB_TOKEN'] ?? null) !== null;

        if ($hasGithub && $hasGitlab) {
            return [GitProvider::Github->value, GitProvider::Gitlab->value];
        }
        if ($hasGitlab) {
            return [GitProvider::Gitlab->value];
        }

        return [GitProvider::Github->value];
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
}
