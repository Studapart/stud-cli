<?php

declare(strict_types=1);

namespace App\Handler;

use App\Guard\Capability\WorkItemJiraAware;
use App\Response\ItemListResponse;
use App\Service\WorkItemProviderInterface;

class ItemListHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly WorkItemProviderInterface $provider,
    ) {
    }

    public function handle(bool $all, ?string $project, ?string $sort = null): ItemListResponse
    {
        try {
            $issues = $this->provider->listAssignedActive($project, ! $all);

            if ($sort !== null) {
                $issues = $this->sortIssues($issues, $sort);
            }

            return ItemListResponse::success($issues, $all, $project);
        } catch (\Exception $e) {
            return ItemListResponse::error($e->getMessage());
        }
    }

    /**
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
