<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\ItemShowResponse;
use App\Service\JiraService;

class ItemShowHandler
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
