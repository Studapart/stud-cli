<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\WorkItem;

/**
 * Serializes work items for agent-mode list, search, and filter discovery responses.
 */
class WorkItemListJsonSerializer
{
    /**
     * @param array<int, WorkItem> $issues
     * @return list<array{key: string, status: string, title: string, url: string, priority?: string}>
     */
    public function serializeList(array $issues, string $projectManagementBaseUrl, bool $includePriority = false): array
    {
        return array_values(array_map(
            fn (WorkItem $item): array => $this->serializeSummary($item, $projectManagementBaseUrl, $includePriority),
            $issues,
        ));
    }

    /**
     * @return array{key: string, status: string, title: string, url: string, priority?: string}
     */
    public function serializeSummary(WorkItem $item, string $projectManagementBaseUrl, bool $includePriority = false): array
    {
        $summary = [
            'key' => $item->key,
            'status' => $item->status,
            'title' => $item->title,
            'url' => $this->resolveIssueUrl($item, $projectManagementBaseUrl),
        ];

        if ($includePriority) {
            $summary['priority'] = $item->priority ?? '';
        }

        return $summary;
    }

    protected function resolveIssueUrl(WorkItem $item, string $projectManagementBaseUrl): string
    {
        if ($item->url !== null && trim($item->url) !== '') {
            return $item->url;
        }

        return rtrim($projectManagementBaseUrl, '/') . '/browse/' . $item->key;
    }
}
