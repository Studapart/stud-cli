<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\FilterShowResponse;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerProvidersQueryResolver;

class FilterShowHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
        private readonly IssueTrackerProvidersQueryResolver $queryResolver = new IssueTrackerProvidersQueryResolver(),
    ) {
    }

    public function handle(string $filterName, ?string $providerOverride = null): FilterShowResponse
    {
        $providers = $this->queryResolver->resolve($this->globalConfig, $this->projectConfig, $providerOverride);
        if ($providers === []) {
            $resolution = $this->portSupplier->resolve($this->globalConfig, $this->projectConfig);
            if (! $resolution['ok']) {
                return FilterShowResponse::error($resolution['error']);
            }

            $providers = [$resolution['provider']];
        }

        $issues = [];
        $issueProviders = [];
        $matchedProviders = [];
        $warnings = [];

        foreach ($providers as $providerSlug) {
            $resolution = $this->portSupplier->resolveForProvider($providerSlug, $this->globalConfig);
            if (! $resolution['ok']) {
                if (count($providers) === 1) {
                    return FilterShowResponse::error($resolution['error']);
                }
                $warnings[] = ResponseMessage::warning($resolution['error']);

                continue;
            }

            try {
                $fetched = $resolution['port']->runFilterOrView($filterName);
            } catch (\Exception $e) {
                if (count($providers) === 1) {
                    return FilterShowResponse::error(
                        MessageRef::key('filter.show.error_fetch', ['error' => $e->getMessage()])
                    );
                }

                continue;
            }

            if ($fetched !== []) {
                $matchedProviders[] = $providerSlug;
            }

            foreach ($fetched as $issue) {
                $issues[] = $issue;
                $issueProviders[] = $providerSlug;
            }
        }

        if ($issues === []) {
            return FilterShowResponse::error(
                MessageRef::key('filter.show.error_not_found', ['filterName' => $filterName]),
            );
        }

        $multiProvider = count(array_unique($issueProviders)) > 1;

        return FilterShowResponse::success($issues, $filterName, $issueProviders, $multiProvider, $warnings);
    }
}
