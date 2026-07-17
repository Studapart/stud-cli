<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;

/**
 * Resolves Jira project and Linear team scope keys from project config.
 */
final class ProjectScopeKeyResolver
{
    /**
     * @param array<string, mixed> $projectConfig
     */
    public function resolveJiraProjectKey(array $projectConfig): ?string
    {
        $projectKey = $projectConfig[ProjectStudConfigKeys::PROJECT_KEY] ?? null;
        if (is_string($projectKey) && trim($projectKey) !== '') {
            return strtoupper(trim($projectKey));
        }

        $defaultProject = $projectConfig[ProjectStudConfigKeys::JIRA_DEFAULT_PROJECT] ?? null;
        if (is_string($defaultProject) && trim($defaultProject) !== '') {
            return strtoupper(trim($defaultProject));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function resolveLinearTeamKey(array $projectConfig): ?string
    {
        $linearTeamKey = $projectConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] ?? null;
        if (is_string($linearTeamKey) && trim($linearTeamKey) !== '') {
            return strtoupper(trim($linearTeamKey));
        }

        return $this->resolveJiraProjectKey($projectConfig);
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function scopeMatchesJira(string $scopeKey, array $projectConfig): bool
    {
        $normalized = strtoupper(trim($scopeKey));
        foreach ($this->jiraScopeKeys($projectConfig) as $configured) {
            if (strcasecmp($normalized, $configured) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function scopeMatchesLinear(string $scopeKey, array $projectConfig): bool
    {
        $teamKey = $this->resolveLinearTeamKey($projectConfig);
        if ($teamKey === null) {
            return false;
        }

        return strcasecmp(strtoupper(trim($scopeKey)), $teamKey) === 0;
    }

    /**
     * @param array<string, mixed> $projectConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear'}|array{ok: false, error: MessageRef}
     */
    public function resolveProviderForDiscoveryScope(string $scopeKey, array $projectConfig): array
    {
        $matchesJira = $this->scopeMatchesJira($scopeKey, $projectConfig);
        $matchesLinear = $this->scopeMatchesLinear($scopeKey, $projectConfig);

        if ($matchesJira && $matchesLinear) {
            return [
                'ok' => false,
                'error' => MessageRef::key('issue_tracker_provider.ambiguous_prefix', [
                    '%prefix%' => strtoupper(trim($scopeKey)),
                ]),
            ];
        }

        if ($matchesJira) {
            return ['ok' => true, 'provider' => IssueTrackerProvider::Jira->value];
        }

        if ($matchesLinear) {
            return ['ok' => true, 'provider' => IssueTrackerProvider::Linear->value];
        }

        return [
            'ok' => false,
            'error' => MessageRef::key('issue_tracker_provider.unknown_prefix', [
                '%prefix%' => strtoupper(trim($scopeKey)),
                '%configuredKeys%' => $this->formatConfiguredScopeKeys($projectConfig),
            ]),
        ];
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    public function formatConfiguredScopeKeys(array $projectConfig): string
    {
        $parts = [];
        foreach ($this->jiraScopeKeys($projectConfig) as $prefix) {
            $parts[] = $prefix . ' (' . IssueTrackerProvider::Jira->value . ')';
        }

        $linearTeamKey = $this->resolveLinearTeamKey($projectConfig);
        if ($linearTeamKey !== null && ! in_array($linearTeamKey, $this->jiraScopeKeys($projectConfig), true)) {
            $parts[] = $linearTeamKey . ' (' . IssueTrackerProvider::Linear->value . ')';
        }

        if ($parts === []) {
            return '(none)';
        }

        return implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $projectConfig
     *
     * @return list<string>
     */
    private function jiraScopeKeys(array $projectConfig): array
    {
        $keys = [];
        foreach ([ProjectStudConfigKeys::PROJECT_KEY, ProjectStudConfigKeys::JIRA_DEFAULT_PROJECT] as $configKey) {
            $value = $projectConfig[$configKey] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $keys[] = strtoupper(trim($value));
            }
        }

        return array_values(array_unique($keys));
    }
}
