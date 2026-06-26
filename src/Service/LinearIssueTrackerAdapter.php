<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\WorkItem;

/**
 * Linear adapter for IssueTrackerPort (SCI-164+ implements delegation).
 *
 * @codeCoverageIgnore
 */
final class LinearIssueTrackerAdapter implements IssueTrackerPort
{
    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function search(string $query): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listAssignedActive(?string $projectKey = null, bool $onlyMine = true): array
    {
        unset($projectKey, $onlyMine);

        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    /**
     * @param array<string, mixed> $input
     * @return array{key: string, self: string}
     */
    public function create(array $input): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $key, array $input): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function getCreateMetaFields(string $projectKey, string $issueTypeId): array
    {
        unset($projectKey, $issueTypeId);

        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function getEditMetaFields(string $key): array
    {
        unset($key);

        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function formatDescription(string $text, string $format = 'plain'): array
    {
        unset($text, $format);

        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listProjectStateChanges(string $projectKey): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listItemStateChanges(string $itemKey): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function applyStateChange(string $itemKey, string $changeId): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function assign(string $key, ?string $user = null): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listTeams(): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listFiltersOrViews(): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function runFilterOrView(string $name): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listWorkflowMetadata(?string $projectKey = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listTypeLabels(?string $projectKey = null): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function ping(): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function listAttachments(string $key): array
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function uploadAttachment(string $key, string $localPath): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }

    public function downloadAttachment(string $url, string $destPath): void
    {
        throw new \BadMethodCallException('Not implemented until SCI-164');
    }
}
