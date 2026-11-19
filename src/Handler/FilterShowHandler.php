<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\FilterShowResponse;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerProviderFanOut;

class FilterShowHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerProviderFanOut $fanOut,
    ) {
    }

    public function handle(string $filterName, ?string $providerOverride = null): FilterShowResponse
    {
        $result = $this->fanOut->collect(
            $providerOverride,
            fn (IssueTrackerPort $port): array => $port->runFilterOrView($filterName),
            'filter.show.error_fetch',
            false,
        );
        if ($result instanceof MessageRef) {
            return FilterShowResponse::error($result);
        }

        if ($result->items === []) {
            if ($result->fetchFailures !== []) {
                $furtherFailures = [];
                foreach (array_slice($result->fetchFailures, 1) as $failure) {
                    $furtherFailures[] = ResponseMessage::warning($failure);
                }

                return FilterShowResponse::error(
                    $result->fetchFailures[0],
                    array_merge($furtherFailures, $result->warnings),
                );
            }

            if ($result->warnings !== []) {
                return FilterShowResponse::error($result->warnings[0]->message, array_slice($result->warnings, 1));
            }

            return FilterShowResponse::error(
                MessageRef::key('filter.show.error_not_found', ['filterName' => $filterName]),
            );
        }

        return FilterShowResponse::success($result->items, $filterName, $result->providers, $result->isMultiProvider(), $result->warnings);
    }
}
