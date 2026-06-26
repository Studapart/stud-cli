<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Exception\ApiException;

/**
 * Jira adapter for {@see WorkItemProviderInterface}; delegates to existing Jira services.
 */
final class JiraWorkItemProvider implements WorkItemProviderInterface
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly JiraAttachmentService $attachmentService,
    ) {
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        return $this->jiraService->getIssue($key, $renderFields);
    }

    public function search(string $query, ?string $context = null): array
    {
        unset($context);

        return array_values($this->jiraService->searchIssues($query));
    }

    public function listAssignedActive(?string $projectKey = null): array
    {
        $jqlParts = [
            'assignee = currentUser()',
            "statusCategory in ('To Do', 'In Progress')",
        ];
        if ($projectKey !== null && $projectKey !== '') {
            $jqlParts[] = 'project = ' . strtoupper($projectKey);
        }

        $jql = implode(' AND ', $jqlParts) . ' ORDER BY updated DESC';

        return array_values($this->jiraService->searchIssues($jql));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{key: string, self: string}
     */
    public function create(array $input): array
    {
        return $this->jiraService->createIssue($input);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $key, array $input): void
    {
        $this->jiraService->updateIssue($key, $input);
    }

    public function listProjectStateChanges(string $projectKey): array
    {
        return $this->mapJiraTransitions($this->jiraService->getProjectTransitions($projectKey));
    }

    public function listItemStateChanges(string $itemKey): array
    {
        return $this->mapJiraTransitions($this->jiraService->getTransitions($itemKey));
    }

    public function applyStateChange(string $itemKey, string $changeId): void
    {
        $this->jiraService->transitionIssue($itemKey, (int) $changeId);
    }

    public function assign(string $key, ?string $user = null): void
    {
        $this->jiraService->assignIssue($key, $user ?? 'currentUser()');
    }

    public function listTeams(): array
    {
        return array_values($this->jiraService->getProjects());
    }

    public function listFiltersOrViews(): array
    {
        return array_values($this->jiraService->getFilters());
    }

    public function runFilterOrView(string $name): array
    {
        $jql = 'filter = "' . $name . '"';

        return array_values($this->jiraService->searchIssues($jql));
    }

    public function listWorkflowMetadata(?string $projectKey = null): array
    {
        if ($projectKey === null || $projectKey === '') {
            return [];
        }

        return [
            'issueTypes' => $this->jiraService->getCreateMetaIssueTypes($projectKey),
        ];
    }

    public function listTypeLabels(?string $projectKey = null): array
    {
        if ($projectKey === null || $projectKey === '') {
            return [];
        }

        $issueTypes = $this->jiraService->getCreateMetaIssueTypes($projectKey);

        return array_values(array_map(
            static fn (array $type): string => $type['name'],
            $issueTypes,
        ));
    }

    public function ping(): void
    {
        $this->jiraService->getProjects();
    }

    public function listAttachments(string $key): array
    {
        return $this->jiraService->getIssue($key, true)->attachments;
    }

    public function uploadAttachment(string $key, string $localPath): void
    {
        $this->attachmentService->uploadFileToIssue($key, $localPath);
    }

    public function downloadAttachment(string $url, string $destPath): void
    {
        $content = $this->attachmentService->downloadAttachmentContent($url);
        if (@file_put_contents($destPath, $content) === false) {
            throw new ApiException(
                'Could not write attachment to destination path.',
                sprintf('Failed to write file: %s', $destPath),
                500,
            );
        }
    }

    /**
     * @param array<int, array{id: int|string, name: string, to?: array{name?: string}}> $transitions
     * @return list<StateChange>
     */
    private function mapJiraTransitions(array $transitions): array
    {
        $stateChanges = [];
        foreach ($transitions as $transition) {
            $stateChanges[] = new StateChange(
                id: (string) $transition['id'],
                name: (string) $transition['name'],
                targetStatus: isset($transition['to']['name']) ? (string) $transition['to']['name'] : null,
            );
        }

        return $stateChanges;
    }
}
