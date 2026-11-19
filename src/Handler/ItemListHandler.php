<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\ProjectStudConfigKeys;
use App\DTO\ItemListAggregate;
use App\DTO\ItemListConfigs;
use App\DTO\MessageRef;
use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\ItemListResponse;
use App\Service\DualTrackerPolicy;
use App\Service\IssueTrackerPortSupplier;
use App\Service\ItemListProviderFetcher;
use App\Service\ProjectScopeKeyResolver;

class ItemListHandler implements JiraAware, LinearAware
{
    private readonly ItemListProviderFetcher $fetcher;

    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        private readonly ItemListConfigs $configs,
        private readonly ProjectScopeKeyResolver $scopeKeyResolver = new ProjectScopeKeyResolver(),
        private readonly DualTrackerPolicy $dualTracker = new DualTrackerPolicy(),
    ) {
        $this->fetcher = new ItemListProviderFetcher($portSupplier, $configs, $scopeKeyResolver);
    }

    public function handle(bool $all, ?string $project, ?string $sort, ?string $providerOverride = null): ItemListResponse
    {
        $onlyMine = ! $all;
        $providers = $this->resolveProvidersToQuery($project, $providerOverride);
        if ($providers === []) {
            $resolution = $this->portSupplier->resolve($this->configs->global, $this->configs->project);
            if (! $resolution['ok']) {
                return ItemListResponse::error($resolution['error']);
            }

            $providers = [$resolution['provider']];
        }

        return $this->fetchFromProviders($providers, $project, $onlyMine, $sort);
    }

    /**
     * @param list<string> $providers
     */
    private function fetchFromProviders(array $providers, ?string $project, bool $onlyMine, ?string $sort): ItemListResponse
    {
        $aggregate = new ItemListAggregate();
        foreach ($providers as $providerSlug) {
            $failure = $this->fetcher->fetchInto($aggregate, $providerSlug, $project, $onlyMine);
            if ($failure === null) {
                continue;
            }
            if (count($providers) === 1) {
                return ItemListResponse::error($failure);
            }
            $aggregate->addWarning($failure);
        }

        if ($aggregate->allProvidersFailed()) {
            return ItemListResponse::error(
                MessageRef::key('item.list.error_fetch', ['error' => 'all providers failed']),
                $aggregate->warnings(),
            );
        }

        if ($sort !== null) {
            $aggregate->reorder($this->sortIssues($aggregate->issues(), $sort));
        }

        return ItemListResponse::success(
            $aggregate->issues(),
            ! $onlyMine,
            $project,
            $aggregate->providers(),
            $aggregate->isMultiProvider(),
            $aggregate->warnings(),
        );
    }

    /**
     * @return list<string>
     */
    private function resolveProvidersToQuery(?string $project, ?string $providerOverride): array
    {
        if ($providerOverride !== null) {
            return [$providerOverride];
        }

        $explicit = ProjectStudConfigKeys::readIssueTrackerProvider($this->configs->project);
        if ($explicit !== null && $explicit !== IssueTrackerProvider::Auto->value) {
            return [$explicit];
        }

        if ($project !== null && trim($project) !== '') {
            return $this->resolveProvidersForProjectScope(trim($project));
        }

        if ($this->shouldDualAutoAggregate()) {
            return [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function resolveProvidersForProjectScope(string $project): array
    {
        $scope = strtoupper($project);
        $matchJira = $this->scopeKeyResolver->scopeMatchesJira($scope, $this->configs->project);
        $matchLinear = $this->scopeKeyResolver->scopeMatchesLinear($scope, $this->configs->project);

        if ($matchJira && ! $matchLinear) {
            return [IssueTrackerProvider::Jira->value];
        }

        if ($matchLinear && ! $matchJira) {
            return [IssueTrackerProvider::Linear->value];
        }

        if ($this->shouldDualAutoAggregate()) {
            return [IssueTrackerProvider::Jira->value, IssueTrackerProvider::Linear->value];
        }

        return [];
    }

    private function shouldDualAutoAggregate(): bool
    {
        return $this->dualTracker->isDualAuto($this->configs->global, $this->configs->project);
    }

    /**
     * @param list<WorkItem> $issues
     * @return list<WorkItem>
     */
    protected function sortIssues(array $issues, string $sort): array
    {
        $normalizedSort = ucfirst(strtolower($sort));
        if ($normalizedSort === 'Key') {
            usort($issues, fn (WorkItem $a, WorkItem $b) => strcmp($a->key, $b->key));
        } elseif ($normalizedSort === 'Status') {
            usort($issues, fn (WorkItem $a, WorkItem $b) => strcmp($a->status, $b->status));
        }

        return $issues;
    }
}
