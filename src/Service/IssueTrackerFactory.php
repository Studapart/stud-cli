<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\Enum\WorkItemProvider;
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
        if ($normalizedOverride !== null) {
            return $normalizedOverride->value;
        }

        $projectProvider = $this->readProjectProvider($projectConfig);
        if ($projectProvider !== null) {
            return $projectProvider->value;
        }

        return $this->resolveAutoType($globalConfig);
    }

    /**
     * @param array<string, mixed> $globalConfig
     */
    public function assertCredentials(string $type, array $globalConfig): void
    {
        if ($type === WorkItemProvider::Jira->value && ! $this->hasJiraCredentials($globalConfig)) {
            throw IssueTrackerException::missingJiraConfiguration();
        }

        if ($type === WorkItemProvider::Linear->value && ! $this->hasLinearCredentials($globalConfig)) {
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
            WorkItemProvider::Jira->value => new JiraIssueTrackerAdapter(
                $jiraService ?? throw new \InvalidArgumentException('Jira service is required for the jira work-item provider'),
                $attachmentService ?? throw new \InvalidArgumentException('Jira attachment service is required for the jira work-item provider'),
            ),
            WorkItemProvider::Linear->value => new LinearIssueTrackerAdapter(
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
        if ($type === WorkItemProvider::Jira->value) {
            if ($jiraApiClient === null || $attachmentService === null) {
                throw IssueTrackerException::missingJiraConfiguration();
            }

            return $this->create(WorkItemProvider::Jira->value, $jiraApiClient, $attachmentService);
        }

        if ($linearApiClient === null) {
            throw IssueTrackerException::missingLinearApiKey();
        }

        return $this->create(
            WorkItemProvider::Linear->value,
            linearApiClient: $linearApiClient,
            gitRepository: $gitRepository,
            linearAttachmentService: $linearAttachmentService,
        );
    }

    private function normalizeOverride(?string $cliOverride): ?WorkItemProvider
    {
        if ($cliOverride === null || trim($cliOverride) === '') {
            return null;
        }

        $normalized = strtolower(trim($cliOverride));
        if ($normalized === 'auto') {
            return null;
        }

        $provider = WorkItemProvider::tryFrom($normalized);
        if ($provider === null) {
            throw new \InvalidArgumentException(sprintf('Unknown work-item provider override: %s', $cliOverride));
        }

        return $provider;
    }

    /**
     * @param array<string, mixed> $projectConfig
     */
    private function readProjectProvider(array $projectConfig): ?WorkItemProvider
    {
        if (! isset($projectConfig[ProjectStudConfigKeys::WORK_ITEM_PROVIDER]) || ! is_string($projectConfig[ProjectStudConfigKeys::WORK_ITEM_PROVIDER])) {
            return null;
        }

        $normalized = strtolower(trim($projectConfig[ProjectStudConfigKeys::WORK_ITEM_PROVIDER]));
        if ($normalized === 'auto') {
            return null;
        }

        return WorkItemProvider::tryFrom($normalized);
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
            return WorkItemProvider::Jira->value;
        }

        if ($hasLinear && ! $hasJira) {
            return WorkItemProvider::Linear->value;
        }

        if ($this->hasJiraCredentials($globalConfig)) {
            return WorkItemProvider::Jira->value;
        }

        if ($this->hasLinearCredentials($globalConfig)) {
            return WorkItemProvider::Linear->value;
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
