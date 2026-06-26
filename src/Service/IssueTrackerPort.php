<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\Filter;
use App\DTO\IssueAttachment;
use App\DTO\Project;
use App\DTO\StateChange;
use App\DTO\WorkItem;
use App\Exception\ApiException;

/**
 * Contract for work-item (PM) provider implementations (Jira, Linear).
 *
 * Handlers depend on this interface only; adapters map stud-cli concepts
 * (StateChange, WorkItem) to provider APIs internally.
 */
interface IssueTrackerPort
{
    /** @throws ApiException */
    public function getIssue(string $key, bool $renderFields = false): WorkItem;

    /**
     * @return list<WorkItem>
     * @throws ApiException
     */
    public function search(string $query): array;

    /**
     * @param bool $onlyMine When true, limit to issues assigned to the current user (Jira: assignee = currentUser())
     * @return list<WorkItem>
     * @throws ApiException
     */
    public function listAssignedActive(?string $projectKey = null, bool $onlyMine = true): array;

    /**
     * @param array<string, mixed> $input
     * @return array{key: string, self: string}
     * @throws ApiException
     */
    public function create(array $input): array;

    /**
     * @param array<string, mixed> $input
     * @throws ApiException
     */
    public function update(string $key, array $input): void;

    /**
     * @return array<string, array{required: bool, name: string}>
     * @throws ApiException
     */
    public function getCreateMetaFields(string $projectKey, string $issueTypeId): array;

    /**
     * @return array<string, array{required: bool, name: string}>
     * @throws ApiException
     */
    public function getEditMetaFields(string $key): array;

    /**
     * @return array{type: string, version: int, content: array<int, mixed>}
     */
    public function formatDescription(string $text, string $format = 'plain'): array;

    /**
     * @return list<StateChange>
     * @throws ApiException
     */
    public function listProjectStateChanges(string $projectKey): array;

    /**
     * @return list<StateChange>
     * @throws ApiException
     */
    public function listItemStateChanges(string $itemKey): array;

    /** @throws ApiException */
    public function applyStateChange(string $itemKey, string $changeId): void;

    /** @throws ApiException */
    public function assign(string $key, ?string $user = null): void;

    /**
     * @return list<Project>
     * @throws ApiException
     */
    public function listTeams(): array;

    /**
     * @return list<Filter>
     * @throws ApiException
     */
    public function listFiltersOrViews(): array;

    /**
     * @return list<WorkItem>
     * @throws ApiException
     */
    public function runFilterOrView(string $name): array;

    /**
     * @return array<string, mixed>
     * @throws ApiException
     */
    public function listWorkflowMetadata(?string $projectKey = null): array;

    /**
     * @return list<string>
     * @throws ApiException
     */
    public function listTypeLabels(?string $projectKey = null): array;

    /** @throws ApiException */
    public function ping(): void;

    /**
     * @return list<IssueAttachment>
     * @throws ApiException
     */
    public function listAttachments(string $key): array;

    /** @throws ApiException */
    public function uploadAttachment(string $key, string $localPath): void;

    /** @throws ApiException */
    public function downloadAttachment(string $url, string $destPath): void;
}
