<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;

/**
 * Contract for work-item (PM) provider implementations (Jira, Linear).
 *
 * Extends the read contract with the methods that change remote work items. Handlers that
 * change work items depend on this interface; lookup handlers depend on {@see IssueTrackerReadPort}.
 * Adapters map stud-cli concepts (StateChange, WorkItem) to provider APIs internally.
 */
interface IssueTrackerPort extends IssueTrackerReadPort
{
    /**
     * @param array<string, mixed> $input Stud-neutral field bag ({@see StudIssueKeys})
     * @return array{key: string, self: string}
     * @throws ApiException
     */
    public function create(array $input): array;

    /**
     * @param array<string, mixed> $input Stud-neutral field bag ({@see StudIssueKeys})
     * @throws ApiException
     */
    public function update(string $key, array $input): void;

    /** @throws ApiException */
    public function applyStateChange(string $itemKey, string $changeId): void;

    /** @throws ApiException */
    public function assign(string $key, ?string $user = null): void;

    /** @throws ApiException */
    public function uploadAttachment(string $key, string $localPath): void;
}
