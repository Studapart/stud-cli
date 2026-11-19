<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Filter;
use App\DTO\MessageRef;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\FilterListResponse;
use App\Service\IssueTrackerProviderFanOut;
use App\Service\IssueTrackerReadPort;

class FilterListHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerProviderFanOut $fanOut,
    ) {
    }

    public function handle(?string $providerOverride = null): FilterListResponse
    {
        $result = $this->fanOut->collect(
            $providerOverride,
            fn (IssueTrackerReadPort $port): array => $port->listFiltersOrViews(),
            'filter.list.error_fetch',
        );
        if ($result instanceof MessageRef) {
            return FilterListResponse::error($result);
        }

        $filters = array_map(
            fn (Filter $filter, string $provider): Filter => new Filter($filter->name, $filter->description, $provider),
            $result->items,
            $result->providers,
        );
        $this->sortFiltersByName($filters);

        return FilterListResponse::success($filters, $result->providers, $result->isMultiProvider(), $result->warnings);
    }

    /**
     * @param Filter[] $filters
     */
    protected function sortFiltersByName(array &$filters): void
    {
        usort($filters, fn (Filter $a, Filter $b) => strcasecmp($a->name, $b->name));
    }
}
