<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Filter;
use App\DTO\MessageRef;
use App\Response\FilterListResponse;
use App\Service\JiraService;

class FilterListHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        mixed $_translator
    ) {
        unset($_translator);
    }

    public function handle(): FilterListResponse
    {
        try {
            $filters = $this->jiraService->getFilters();
        } catch (\Exception $e) {
            return FilterListResponse::error(
                MessageRef::key('filter.list.error_fetch', ['error' => $e->getMessage()])
            );
        }

        $this->sortFiltersByName($filters);

        return FilterListResponse::success($filters);
    }

    /**
     * @param Filter[] $filters
     */
    protected function sortFiltersByName(array &$filters): void
    {
        usort($filters, fn (Filter $a, Filter $b) => strcasecmp($a->name, $b->name));
    }
}
