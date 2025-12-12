<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\ItemListResponse;
use App\Service\JiraService;

class ItemListHandler
{
    public function __construct(
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(bool $all, ?string $project, ?string $sort = null): ItemListResponse
    {
        $jqlParts = [];
        if (! $all) {
            $jqlParts[] = 'assignee = currentUser()';
        }
        $jqlParts[] = "statusCategory in ('To Do', 'In Progress')";
        if ($project) {
            $jqlParts[] = 'project = ' . strtoupper($project);
        }

        $jql = implode(' AND ', $jqlParts) . ' ORDER BY updated DESC';

        try {
            $issues = $this->jiraService->searchIssues($jql);

            if ($sort !== null) {
                $issues = $this->sortIssues($issues, $sort);
            }

            return ItemListResponse::success($issues, $all, $project);
        } catch (\Exception $e) {
            return ItemListResponse::error($e->getMessage());
        }
    }

    /**
     * Sorts issues by the specified field.
     *
     * @param \App\DTO\WorkItem[] $issues
     * @return \App\DTO\WorkItem[]
     */
    protected function sortIssues(array $issues, string $sort): array
    {
        $normalizedSort = ucfirst(strtolower($sort));
        if ($normalizedSort === 'Key') {
            usort($issues, fn (\App\DTO\WorkItem $a, \App\DTO\WorkItem $b) => strcmp($a->key, $b->key));
        } elseif ($normalizedSort === 'Status') {
            usort($issues, fn (\App\DTO\WorkItem $a, \App\DTO\WorkItem $b) => strcmp($a->status, $b->status));
        }

        return $issues;
    }
}
