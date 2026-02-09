<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Filter;
use App\Response\FilterListResponse;
use App\Service\JiraService;
use App\Service\TranslationService;

class FilterListHandler
{
    public function __construct(
        private readonly JiraService $jiraService,
        private readonly TranslationService $translator
    ) {
    }

    public function handle(): FilterListResponse
    {
        try {
            $filters = $this->jiraService->getFilters();
        } catch (\Exception $e) {
            return FilterListResponse::error(
                $this->translator->trans('filter.list.error_fetch', ['error' => $e->getMessage()])
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
