<?php

declare(strict_types=1);

namespace App\Handler;

use App\Guard\Capability\WorkItemJiraAware;
use App\Response\FilterShowResponse;
use App\Service\WorkItemProviderInterface;

class FilterShowHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly WorkItemProviderInterface $provider,
    ) {
    }

    public function handle(string $filterName): FilterShowResponse
    {
        try {
            $issues = $this->provider->runFilterOrView($filterName);

            return FilterShowResponse::success($issues, $filterName);
        } catch (\Exception $e) {
            return FilterShowResponse::error($e->getMessage());
        }
    }
}
