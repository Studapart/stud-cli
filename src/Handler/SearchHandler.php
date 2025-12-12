<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\SearchResponse;
use App\Service\JiraService;

class SearchHandler
{
    public function __construct(
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(string $jql): SearchResponse
    {
        try {
            $issues = $this->jiraService->searchIssues($jql);

            return SearchResponse::success($issues, $jql);
        } catch (\Exception $e) {
            return SearchResponse::error($e->getMessage());
        }
    }
}
