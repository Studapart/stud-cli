<?php

declare(strict_types=1);

namespace App\Responder;

use App\DTO\WorkItem;

/**
 * Serializes tracked issues for agent-mode list, search, and filter discovery responses.
 */
class IssueListJsonSerializer
{
    /**
     * @param array<int, WorkItem> $issues
     * @param list<string>         $issueProviders
     * @return list<array{key: string, status: string, title: string, url: string, provider?: string, priority?: string}>
     */
    public function serializeList(
        array $issues,
        string $projectManagementBaseUrl,
        bool $includePriority = false,
        array $issueProviders = [],
        bool $includeProvider = false,
    ): array {
        $serialized = [];
        foreach (array_values($issues) as $index => $item) {
            $provider = $issueProviders[$index] ?? null;
            $serialized[] = $this->serializeSummary(
                $item,
                $projectManagementBaseUrl,
                $includePriority,
                $includeProvider ? $provider : null,
            );
        }

        return $serialized;
    }

    /**
     * @return array{key: string, status: string, title: string, url: string, provider?: string, priority?: string}
     */
    public function serializeSummary(
        WorkItem $item,
        string $projectManagementBaseUrl,
        bool $includePriority = false,
        ?string $provider = null,
    ): array {
        $summary = [
            'key' => $item->key,
            'status' => $item->status,
            'title' => $item->title,
            'url' => $this->resolveIssueUrl($item, $projectManagementBaseUrl),
        ];

        if ($provider !== null && $provider !== '') {
            $summary['provider'] = $provider;
        }

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
