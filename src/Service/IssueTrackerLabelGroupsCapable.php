<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;

/**
 * Optional issue-tracker capability: Linear LabelGroups + child labels discovery.
 *
 * Not part of {@see IssueTrackerPort} — only adapters that support this metadata implement it.
 */
interface IssueTrackerLabelGroupsCapable
{
    /**
     * @return list<array{id: string, name: string, labels: list<array{id: string, name: string, color?: string}>}>
     * @throws ApiException
     */
    public function listLabelGroups(string $projectKey, bool $groupsOnly = false): array;
}
