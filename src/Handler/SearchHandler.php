<?php

declare(strict_types=1);

namespace App\Handler;

use App\Guard\Capability\WorkItemJiraAware;
use App\Response\SearchResponse;
use App\Service\WorkItemProviderInterface;

class SearchHandler implements WorkItemJiraAware
{
    public function __construct(
        private readonly WorkItemProviderInterface $provider,
    ) {
    }

    public function handle(string $jql): SearchResponse
    {
        try {
            $issues = $this->provider->search($jql);

            return SearchResponse::success($issues, $jql);
        } catch (\Exception $e) {
            return SearchResponse::error($e->getMessage());
        }
    }
}
