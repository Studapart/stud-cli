<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
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
        ?LinearApiClient $linearApiClient = null,
        ?GitRepository $gitRepository = null,
        ?LinearAttachmentService $linearAttachmentService = null,
    ): IssueTrackerPort {
        return match ($type) {
            'jira' => new JiraIssueTrackerAdapter(
                $jiraService ?? throw new \InvalidArgumentException('Jira service is required for the jira work-item provider'),
                $attachmentService ?? throw new \InvalidArgumentException('Jira attachment service is required for the jira work-item provider'),
            ),
            'linear' => new LinearIssueTrackerAdapter(
                $linearApiClient ?? throw new \InvalidArgumentException('Linear API client is required for the linear work-item provider'),
                gitRepository: $gitRepository,
                linearAttachmentService: $linearAttachmentService,
            ),
            default => throw new \InvalidArgumentException(sprintf('Unknown work-item provider type: %s', $type)),
        };
    }

    /**
     * @param 'jira'|'linear' $type
     *
     * @throws IssueTrackerException
     */
    public function createForProvider(
        string $type,
        ?JiraApiClient $jiraApiClient,
        ?JiraAttachmentService $attachmentService,
        ?LinearApiClient $linearApiClient,
        ?GitRepository $gitRepository = null,
        ?LinearAttachmentService $linearAttachmentService = null,
    ): IssueTrackerPort {
        if ($type === 'jira') {
            if ($jiraApiClient === null || $attachmentService === null) {
                throw IssueTrackerException::missingJiraConfiguration();
            }

            return $this->create('jira', $jiraApiClient, $attachmentService);
        }

        if ($linearApiClient === null) {
            throw IssueTrackerException::missingLinearApiKey();
        }

        return $this->create(
            'linear',
            linearApiClient: $linearApiClient,
            gitRepository: $gitRepository,
            linearAttachmentService: $linearAttachmentService,
        );
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
        if (! isset($projectConfig[ProjectStudConfigKeys::WORK_ITEM_PROVIDER]) || ! is_string($projectConfig[ProjectStudConfigKeys::WORK_ITEM_PROVIDER])) {
            return null;
        }

        $normalized = strtolower(trim($projectConfig[ProjectStudConfigKeys::WORK_ITEM_PROVIDER]));
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
        return GlobalStudConfigKeys::hasJiraCredentials($globalConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function hasLinearCredentials(array $globalConfig): bool
    {
        return GlobalStudConfigKeys::hasLinearApiKey($globalConfig);
    }
}
