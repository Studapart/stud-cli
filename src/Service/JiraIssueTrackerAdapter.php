<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Exception\ApiException;
use App\Service\Jira\JiraAssignedActiveJqlBuilder;

/**
 * Jira adapter for {@see IssueTrackerPort}; delegates to existing Jira services.
 */
final class JiraIssueTrackerAdapter implements IssueTrackerPort
{
    public function __construct(
        private readonly JiraApiClient $jiraApiClient,
        private readonly JiraAttachmentService $attachmentService,
    ) {
    }

    public function getIssue(string $key, bool $renderFields = false): WorkItem
    {
        return $this->jiraApiClient->getIssue($key, $renderFields);
    }

    public function search(string $query): array
    {
        return array_values($this->jiraApiClient->searchIssues($query));
    }

    public function listAssignedActive(?string $projectKey = null, bool $onlyMine = true): array
    {
        $jql = JiraAssignedActiveJqlBuilder::build($projectKey, $onlyMine);

        return array_values($this->jiraApiClient->searchIssues($jql));
    }

    /**
     * @param array<string, mixed> $input
     * @return array{key: string, self: string}
     */
    public function create(array $input): array
    {
        return $this->jiraApiClient->createIssue($input);
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(string $key, array $input): void
    {
        $this->jiraApiClient->updateIssue($key, $input);
    }

    public function getCreateMetaFields(string $projectKey, string $issueTypeId): array
    {
        return $this->jiraApiClient->getCreateMetaFields($projectKey, $issueTypeId);
    }

    public function getEditMetaFields(string $key): array
    {
        return $this->jiraApiClient->getEditMetaFields($key);
    }

    public function formatDescription(string $text, string $format = 'plain'): array
    {
        return $this->jiraApiClient->descriptionToAdf($text, $format);
    }

    public function listProjectStateChanges(string $projectKey): array
    {
        return $this->mapJiraTransitions($this->jiraApiClient->getProjectTransitions($projectKey));
    }

    public function listItemStateChanges(string $itemKey): array
    {
        return $this->mapJiraTransitions($this->jiraApiClient->getTransitions($itemKey));
    }

    public function applyStateChange(string $itemKey, string $changeId): void
    {
        $this->jiraApiClient->transitionIssue($itemKey, (int) $changeId);
    }

    public function assign(string $key, ?string $user = null): void
    {
        $this->jiraApiClient->assignIssue($key, $user ?? 'currentUser()');
    }

    public function listTeams(): array
    {
        return array_values($this->jiraApiClient->getProjects());
    }

    public function listFiltersOrViews(): array
    {
        return array_values($this->jiraApiClient->getFilters());
    }

    public function runFilterOrView(string $name): array
    {
        $jql = 'filter = "' . $name . '"';

        return array_values($this->jiraApiClient->searchIssues($jql));
    }

    public function listWorkflowMetadata(?string $projectKey = null): array
    {
        if ($projectKey === null || $projectKey === '') {
            return [];
        }

        return [
            'issueTypes' => $this->jiraApiClient->getCreateMetaIssueTypes($projectKey),
        ];
    }

    public function listTypeLabels(?string $projectKey = null): array
    {
        if ($projectKey === null || $projectKey === '') {
            return [];
        }

        $issueTypes = $this->jiraApiClient->getCreateMetaIssueTypes($projectKey);

        return array_values(array_map(
            static fn (array $type): string => $type['name'],
            $issueTypes,
        ));
    }

    public function ping(): void
    {
        $this->jiraApiClient->getProjects();
    }

    public function listAttachments(string $key): array
    {
        return $this->jiraApiClient->getIssue($key, true)->attachments;
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
