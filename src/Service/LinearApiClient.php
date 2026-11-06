<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Project;
use App\Service\Linear\LinearIssueMutationKeys;
use App\Service\Linear\LinearIssueQueryClient;
use App\Service\Linear\LinearMutationClient;
use App\Service\Linear\LinearTeamMetadataClient;
use App\Service\Linear\LinearWorkspaceClient;

/**
 * Linear API facade: delegates team metadata, workspace lookups, issue reads, and writes to focused clients.
 */
class LinearApiClient
{
    public function __construct(
        private readonly LinearTeamMetadataClient $teamMetadataClient,
        private readonly LinearWorkspaceClient $workspaceClient,
        private readonly LinearIssueQueryClient $issueQueryClient,
        private readonly LinearMutationClient $mutationClient,
    ) {
    }

    /**
     * @return list<array{id: string, name: string, type: string}>
     */
    public function getTeamWorkflowStates(string $teamKey): array
    {
        return $this->teamMetadataClient->getTeamWorkflowStates($teamKey);
    }

    /**
     * @return list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}>
     */
    public function getTeamLabelGroups(string $teamKey, bool $groupsOnly): array
    {
        return $this->teamMetadataClient->getTeamLabelGroups($teamKey, $groupsOnly);
    }

    public function getTeamByKey(string $teamKey): ?Project
    {
        return $this->workspaceClient->getTeamByKey($teamKey);
    }

    public function resolveTeamId(string $teamKey): string
    {
        return $this->workspaceClient->resolveTeamId($teamKey);
    }

    public function resolveIssueId(string $identifier): string
    {
        return $this->issueQueryClient->resolveIssueId($identifier);
    }

    public function resolveTeamKeyFromIssue(string $identifier): string
    {
        return $this->issueQueryClient->resolveTeamKeyFromIssue($identifier);
    }

    /**
     * @return array<string, mixed>
     */
    public function getIssue(string $identifier): array
    {
        return $this->issueQueryClient->getIssue($identifier);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAssignedActiveIssues(?string $teamKey, bool $onlyMine): array
    {
        return $this->issueQueryClient->listAssignedActiveIssues($teamKey, $onlyMine);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<array<string, mixed>>
     */
    public function listIssuesByFilter(array $filter): array
    {
        return $this->issueQueryClient->listIssuesByFilter($filter);
    }

    /**
     * @return list<array{id: string, name: string, description: ?string, filterData: array<string, mixed>}>
     */
    public function listCustomViews(): array
    {
        return $this->workspaceClient->listCustomViews();
    }

    /**
     * @return array{id: string, name: string, description: ?string, filterData: array<string, mixed>}|null
     */
    public function resolveCustomViewByName(string $name): ?array
    {
        return $this->workspaceClient->resolveCustomViewByName($name);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchIssues(string $term, int $first = 50, ?string $after = null): array
    {
        return $this->issueQueryClient->searchIssues($term, $first, $after);
    }

    /**
     * @return list<array{key: string, name: string}>
     */
    public function listTeams(): array
    {
        return $this->workspaceClient->listTeams();
    }

    public function ping(): void
    {
        $this->getViewerId();
    }

    public function getViewerId(): string
    {
        return $this->workspaceClient->getViewerId();
    }

    public function assignIssue(string $issueKey, ?string $assigneeId = null): void
    {
        $issueId = $this->resolveIssueId($issueKey);
        $this->issueUpdate($issueId, [
            LinearIssueMutationKeys::ASSIGNEE_ID => $assigneeId ?? $this->getViewerId(),
        ]);
    }

    /**
     * @param list<string> $labelNames
     *
     * @return list<string>
     */
    public function resolveLabelIds(string $teamKey, array $labelNames, ?string $typeGroupId = null): array
    {
        return $this->teamMetadataClient->resolveLabelIds($teamKey, $labelNames, $typeGroupId);
    }

    /**
     * @param array{teamId: string, title: string, description: ?string, labelIds: list<string>, priority: ?int, parentId: ?string} $input
     *
     * @return array{identifier: string, url: string}
     */
    public function issueCreate(array $input): array
    {
        return $this->mutationClient->issueCreate($input);
    }

    /**
     * @param array{title?: string, description?: ?string, labelIds?: list<string>, priority?: ?int, stateId?: string, assigneeId?: string} $input
     */
    public function issueUpdate(string $issueId, array $input): void
    {
        $this->mutationClient->issueUpdate($issueId, $input);
    }

    /**
     * @return array{uploadUrl: string, assetUrl: string, headers: list<array{key: string, value: string}>}
     */
    public function fileUpload(string $filename, string $contentType, int $size): array
    {
        return $this->mutationClient->fileUpload($filename, $contentType, $size);
    }

    public function attachmentCreate(string $issueId, string $title, string $url): void
    {
        $this->mutationClient->attachmentCreate($issueId, $title, $url);
    }
}
