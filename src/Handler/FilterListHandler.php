<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Filter;
use App\DTO\MessageRef;
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\FilterListResponse;
use App\Service\WorkItemProviderInterface;

class FilterListHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly WorkItemProviderInterface $provider,
        mixed $_translator,
    ) {
        unset($_translator);
    }

    public function handle(): FilterListResponse
    {
        try {
            $filters = $this->provider->listFiltersOrViews();
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
