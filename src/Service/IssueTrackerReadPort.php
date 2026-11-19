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
 * Work-item (PM) provider methods that read remote data or write only a local file.
 *
 * Lookup handlers depend on this interface so they cannot change a remote work item.
 * {@see IssueTrackerPort} extends it and adds the methods that change remote data.
 */
interface IssueTrackerReadPort
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

    /** @throws ApiException */
    public function ping(): void;

    /**
     * @return list<IssueAttachment>
     * @throws ApiException
     */
    public function listAttachments(string $key): array;

    /** @throws ApiException */
    public function downloadAttachment(string $url, string $destPath): void;
}
