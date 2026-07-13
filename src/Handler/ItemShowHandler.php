<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\ItemShowResponse;
use App\Service\IssueTrackerPort;

class ItemShowHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerPort $provider,
    ) {
    }

    public function handle(string $key): ItemShowResponse
    {
        $key = strtoupper($key);

        try {
            $issue = $this->provider->getIssue($key, true);

            return ItemShowResponse::success($issue);
        } catch (\App\Exception\ApiException) {
            return ItemShowResponse::error(
                MessageRef::key('item.show.error_work_item_not_found', ['key' => $key])
            );
        } catch (\Exception $e) {
            return ItemShowResponse::error(
                MessageRef::key('item.show.error_fetch', ['error' => $e->getMessage()])
            );
        }
    }
}
