<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\Exception\ApiException;
use App\Guard\Capability\WorkItemJiraAware;
use App\Response\ItemShowResponse;
use App\Service\IssueTrackerPort;

class ItemShowHandler implements WorkItemJiraAware
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
        } catch (ApiException) {
            return ItemShowResponse::error(
                MessageRef::key('item.show.error_not_found', ['key' => $key])
            );
        } catch (\Exception $e) {
            return ItemShowResponse::error(
                MessageRef::key('item.show.error_fetch', ['error' => $e->getMessage()])
            );
        }
    }
}
