<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Response\FilterShowResponse;
use App\Service\IssueTrackerPort;

class FilterShowHandler implements JiraAware
{
    public function __construct(
        private readonly IssueTrackerPort $provider,
    ) {
    }

    public function handle(string $filterName): FilterShowResponse
    {
        try {
            $issues = $this->provider->runFilterOrView($filterName);

            return FilterShowResponse::success($issues, $filterName);
        } catch (\Exception $e) {
            return FilterShowResponse::error(
                MessageRef::key('filter.show.error_fetch', ['error' => $e->getMessage()])
            );
        }
    }
}
