<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\IssueTrackerException;

class IssueTrackerFactory
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     */
    public function resolveType(
        ?string $cliOverride,
        array $globalConfig,
        array $projectConfig,
    ): string {
        $normalizedOverride = $this->normalizeOverride($cliOverride);
        if ($normalizedOverride === 'jira' || $normalizedOverride === 'linear') {
            return $normalizedOverride;
        }

        $projectProvider = $this->readProjectProvider($projectConfig);
        if ($projectProvider === 'jira' || $projectProvider === 'linear') {
            return $projectProvider;
        }

        return $this->resolveAutoType($globalConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function assertCredentials(string $type, array $globalConfig): void
    {
        if ($type === 'jira' && ! $this->hasJiraCredentials($globalConfig)) {
            throw IssueTrackerException::missingJiraConfiguration();
        }

        if ($type === 'linear' && ! $this->hasLinearCredentials($globalConfig)) {
            throw IssueTrackerException::missingLinearApiKey();
        }
    }

    public function create(
        string $type,
        ?JiraApiClient $jiraService = null,
        ?JiraAttachmentService $attachmentService = null,
    ): IssueTrackerPort {
        return match ($type) {
            'jira' => new JiraIssueTrackerAdapter(
                $jiraService ?? throw new \InvalidArgumentException('Jira service is required for the jira work-item provider'),
                $attachmentService ?? throw new \InvalidArgumentException('Jira attachment service is required for the jira work-item provider'),
            ),
            'linear' => new LinearIssueTrackerAdapter(),
            default => throw new \InvalidArgumentException(sprintf('Unknown work-item provider type: %s', $type)),
        };
    }

    private function normalizeOverride(?string $cliOverride): ?string
    {
        if ($cliOverride === null || trim($cliOverride) === '') {
            return null;
        }

        $normalized = strtolower(trim($cliOverride));
        if ($normalized === 'auto') {
            return null;
        }

        if ($normalized === 'jira' || $normalized === 'linear') {
            return $normalized;
        }

        throw new \InvalidArgumentException(sprintf('Unknown work-item provider override: %s', $cliOverride));
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function readProjectProvider(array $projectConfig): ?string
    {
        if (! isset($projectConfig['workItemProvider']) || ! is_string($projectConfig['workItemProvider'])) {
            return null;
        }

        $normalized = strtolower(trim($projectConfig['workItemProvider']));
        if ($normalized === 'auto') {
            return null;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    private function resolveAutoType(array $globalConfig): string
    {
        $globalProviders = $this->globalResolver->resolveWorkItemProviders($globalConfig);
        $hasJira = $this->globalResolver->collectsJira($globalProviders);
        $hasLinear = $this->globalResolver->collectsLinear($globalProviders);

        if ($hasJira && ! $hasLinear) {
            return 'jira';
        }

        if ($hasLinear && ! $hasJira) {
            return 'linear';
        }

        if ($this->hasJiraCredentials($globalConfig)) {
            return 'jira';
        }

        if ($this->hasLinearCredentials($globalConfig)) {
            return 'linear';
        }

        throw IssueTrackerException::notConfigured();
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function hasJiraCredentials(array $globalConfig): bool
    {
        foreach (['JIRA_URL', 'JIRA_EMAIL', 'JIRA_API_TOKEN'] as $key) {
            if (! isset($globalConfig[$key]) || ! is_string($globalConfig[$key]) || trim($globalConfig[$key]) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function hasLinearCredentials(array $globalConfig): bool
    {
        $apiKey = $globalConfig['LINEAR_API_KEY'] ?? null;

        return is_string($apiKey) && trim($apiKey) !== '';
    }
}
