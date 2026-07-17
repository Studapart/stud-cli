<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\SearchResponse;
use App\Service\IssueTrackerPort;

class SearchHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerPort $provider,
    ) {
    }

    public function handle(string $jql): SearchResponse
    {
        try {
            $issues = $this->provider->search($jql);

            return SearchResponse::success($issues, $jql);
        } catch (\Exception $e) {
            return SearchResponse::error(
                MessageRef::key('search.error_search', ['error' => $e->getMessage()])
            );
        }
    }
}
