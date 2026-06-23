<?php

declare(strict_types=1);

namespace App\Handler;

use App\Guard\Capability\WorkItemJiraAware;
use App\Response\ItemShowResponse;
use App\Service\JiraService;

class ItemShowHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(string $key): ItemShowResponse
    {
        $key = strtoupper($key);

        try {
            $issue = $this->jiraService->getIssue($key, true);

            return ItemShowResponse::success($issue);
        } catch (\Exception $e) {
            return ItemShowResponse::error($e->getMessage());
        }
    }
}
