<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\WorkItem;

/**
 * Jira adapter for WorkItemProviderInterface (SCI-161 implements delegation).
 *
 * @codeCoverageIgnore
 */
final class JiraWorkItemProvider implements WorkItemProviderInterface
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly ?JiraAttachmentService $attachmentService = null,
    ) {
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function search(string $query, ?string $context = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function listAssignedActive(?string $projectKey = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    /**
     * @param array<string, mixed> $input
     * @return array{key: string, self: string}
     */
    public function create(array $input): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $key, array $input): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function listProjectStateChanges(string $projectKey): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function listItemStateChanges(string $itemKey): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function applyStateChange(string $itemKey, string $changeId): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function assign(string $key, ?string $user = null): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function listTeams(): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function listFiltersOrViews(): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function runFilterOrView(string $name): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function listWorkflowMetadata(?string $projectKey = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function listTypeLabels(?string $projectKey = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function ping(): void
    {
        $this->jiraService->getProjects();
    }

    public function listAttachments(string $key): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function uploadAttachment(string $key, string $localPath): void
    {
        if ($this->attachmentService === null) {
            throw new \BadMethodCallException('Not implemented until SCI-161');
        }

        throw new \BadMethodCallException('Not implemented until SCI-161');
    }

    public function downloadAttachment(string $url, string $destPath): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-161');
    }
}
