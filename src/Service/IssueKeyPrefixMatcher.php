<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\ProjectStudConfigKeys;
use App\Enum\IssueTrackerProvider;
use App\Exception\IssueTrackerResolutionException;

class IssueKeyPrefixMatcher
{
    public function prefixOrNull(string $issueKey): ?string
    {
        $trimmedKey = trim($issueKey);
        if ($trimmedKey === '') {
            return null;
        }

        try {
            return GitProjectConfigService::extractIssueKeyPrefix($trimmedKey);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Provider whose configured scope claims this issue-key prefix, or null when none / invalid key / ambiguous.
     *
     * @param array<string, mixed> $projectConfig
     */
    public function providerClaimingIssueKey(string $issueKey, array $projectConfig): ?IssueTrackerProvider
    {
        $prefix = $this->prefixOrNull($issueKey);
        if ($prefix === null) {
            return null;
        }

        try {
            return $this->providerForPrefix($prefix, $projectConfig);
        } catch (IssueTrackerResolutionException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $projectConfig
     *
     * @throws IssueTrackerResolutionException when the key is missing, invalid, unclaimed, or ambiguous
     */
    public function providerForIssueKey(?string $issueKey, array $projectConfig): IssueTrackerProvider
    {
        $trimmedKey = $issueKey !== null ? trim($issueKey) : '';
        if ($trimmedKey === '') {
            throw IssueTrackerResolutionException::autoRequiresIssueKey();
        }

        try {
            $prefix = GitProjectConfigService::extractIssueKeyPrefix($trimmedKey);
        } catch (\RuntimeException) {
            throw IssueTrackerResolutionException::unknownPrefix(
                $trimmedKey,
                $this->formatConfiguredKeyPrefixes($projectConfig),
            );
        }

        return $this->providerForPrefix($prefix, $projectConfig)
            ?? throw IssueTrackerResolutionException::unknownPrefix(
                $prefix,
                $this->formatConfiguredKeyPrefixes($projectConfig),
            );
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function providerForPrefix(string $prefix, array $projectConfig): ?IssueTrackerProvider
    {
        $matchesJira = $this->prefixMatchesJira($prefix, $projectConfig);
        $matchesLinear = $this->prefixMatchesLinear($prefix, $projectConfig);

        if ($matchesJira && $matchesLinear) {
            throw IssueTrackerResolutionException::ambiguousPrefix($prefix);
        }

        if ($matchesJira) {
            return IssueTrackerProvider::Jira;
        }

        if ($matchesLinear) {
            return IssueTrackerProvider::Linear;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function prefixMatchesJira(string $prefix, array $projectConfig): bool
    {
        foreach ($this->jiraKeyPrefixes($projectConfig) as $configuredPrefix) {
            if (strcasecmp($prefix, $configuredPrefix) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function prefixMatchesLinear(string $prefix, array $projectConfig): bool
    {
        $linearTeamKey = $projectConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] ?? null;
        if (! is_string($linearTeamKey) || trim($linearTeamKey) === '') {
            return false;
        }

        return strcasecmp($prefix, trim($linearTeamKey)) === 0;
    }

    /**
     * @param array<string, mixed> $projectConfig
     * @return list<string>
     */
    private function jiraKeyPrefixes(array $projectConfig): array
    {
        $prefixes = [];
        foreach ([ProjectStudConfigKeys::PROJECT_KEY, ProjectStudConfigKeys::JIRA_DEFAULT_PROJECT] as $key) {
            $value = $projectConfig[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                $prefixes[] = strtoupper(trim($value));
            }
        }

        return array_values(array_unique($prefixes));
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function formatConfiguredKeyPrefixes(array $projectConfig): string
    {
        $parts = [];
        foreach ($this->jiraKeyPrefixes($projectConfig) as $prefix) {
            $parts[] = $prefix . ' (' . IssueTrackerProvider::Jira->value . ')';
        }

        $linearTeamKey = $projectConfig[ProjectStudConfigKeys::LINEAR_TEAM_KEY] ?? null;
        if (is_string($linearTeamKey) && trim($linearTeamKey) !== '') {
            $parts[] = strtoupper(trim($linearTeamKey)) . ' (' . IssueTrackerProvider::Linear->value . ')';
        }

        if ($parts === []) {
            return '(none)';
        }

        return implode(', ', $parts);
    }
}
