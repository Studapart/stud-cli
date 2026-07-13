<?php

declare(strict_types=1);

namespace App\Handler;

use App\DTO\Filter;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\FilterListResponse;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerProvidersQueryResolver;

class FilterListHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
        mixed $_translator,
        private readonly IssueTrackerProvidersQueryResolver $queryResolver = new IssueTrackerProvidersQueryResolver(),
    ) {
        unset($_translator);
    }

    public function handle(?string $providerOverride = null): FilterListResponse
    {
        $providers = $this->queryResolver->resolve($this->globalConfig, $this->projectConfig, $providerOverride);
        if ($providers === []) {
            $resolution = $this->portSupplier->resolve($this->globalConfig, $this->projectConfig);
            if (! $resolution['ok']) {
                return FilterListResponse::error($resolution['error']);
            }

            $providers = [$resolution['provider']];
        }

        $filters = [];
        $filterProviders = [];
        $warnings = [];

        foreach ($providers as $providerSlug) {
            $resolution = $this->portSupplier->resolveForProvider($providerSlug, $this->globalConfig);
            if (! $resolution['ok']) {
                if (count($providers) === 1) {
                    return FilterListResponse::error($resolution['error']);
                }
                $warnings[] = ResponseMessage::warning($resolution['error']);

                continue;
            }

            try {
                $fetched = $resolution['port']->listFiltersOrViews();
            } catch (\Exception $e) {
                if (count($providers) === 1) {
                    return FilterListResponse::error(
                        MessageRef::key('filter.list.error_fetch', ['error' => $e->getMessage()])
                    );
                }
                $warnings[] = ResponseMessage::warning(
                    MessageRef::key('filter.list.error_fetch', ['error' => $e->getMessage()])
                );

                continue;
            }

            foreach ($fetched as $filter) {
                $filters[] = new Filter($filter->name, $filter->description, $providerSlug);
                $filterProviders[] = $providerSlug;
            }
        }

        $this->sortFiltersByName($filters);
        $multiProvider = count(array_unique($filterProviders)) > 1;

        return FilterListResponse::success($filters, $filterProviders, $multiProvider, $warnings);
    }

    /**
     * @param Filter[] $filters
     */
    protected function sortFiltersByName(array &$filters): void
    {
        usort($filters, fn (Filter $a, Filter $b) => strcasecmp($a->name, $b->name));
    }
}
