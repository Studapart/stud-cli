<?php

declare(strict_types=1);

namespace App\Handler;

use App\Response\FilterShowResponse;
use App\Service\JiraService;

class FilterShowHandler
{
    public function __construct(
        private readonly JiraService $jiraService
    ) {
    }

    public function handle(string $filterName): FilterShowResponse
    {
        $jql = 'filter = "' . $filterName . '"';

        try {
            $issues = $this->jiraService->searchIssues($jql);

            return FilterShowResponse::success($issues, $filterName);
        } catch (\Exception $e) {
            return FilterShowResponse::error($e->getMessage());
        }
    }
}
