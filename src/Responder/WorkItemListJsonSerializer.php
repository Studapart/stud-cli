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
    public function serializeList(array $issues, string $jiraBaseUrl, bool $includePriority = false): array
    {
        return array_values(array_map(
            fn (WorkItem $item): array => $this->serializeSummary($item, $jiraBaseUrl, $includePriority),
            $issues,
        ));
    }

    /**
     * @return array{key: string, status: string, title: string, url: string, priority?: string}
     */
    public function serializeSummary(WorkItem $item, string $jiraBaseUrl, bool $includePriority = false): array
    {
        $summary = [
            'key' => $item->key,
            'status' => $item->status,
            'title' => $item->title,
            'url' => rtrim($jiraBaseUrl, '/') . '/browse/' . $item->key,
        ];

        if ($includePriority) {
            $summary['priority'] = $item->priority ?? '';
        }

        return $summary;
    }
}
