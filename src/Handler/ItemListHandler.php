<?php

declare(strict_types=1);

namespace App\Handler;

use App\Config\GlobalStudConfigKeys;
use App\Config\ProjectStudConfigKeys;
use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\DTO\WorkItem;
use App\Enum\IssueTrackerProvider;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Response\ItemListResponse;
use App\Service\GlobalConfigProviderResolver;
use App\Service\IssueTrackerPortSupplier;
use App\Service\ProjectScopeKeyResolver;

class ItemListHandler implements JiraAware, LinearAware
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        /** @var array<string, mixed> */
        private readonly array $globalConfig,
        /** @var array<string, mixed> */
        private readonly array $projectConfig,
        private readonly ProjectScopeKeyResolver $scopeKeyResolver = new ProjectScopeKeyResolver(),
        private readonly GlobalConfigProviderResolver $globalProviderResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    public function handle(bool $all, ?string $project, ?string $sort, ?string $providerOverride = null): ItemListResponse
    {
        $onlyMine = ! $all;
        $providers = $this->resolveProvidersToQuery($project, $providerOverride);
        if ($providers === []) {
            $resolution = $this->portSupplier->resolve($this->globalConfig, $this->projectConfig);
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
        $issues = [];
        $issueProviders = [];
        $warnings = [];
        $seenKeys = [];

        foreach ($providers as $providerSlug) {
            $resolution = $this->portSupplier->resolveForProvider($providerSlug, $this->globalConfig);
            if (! $resolution['ok']) {
                if (count($providers) === 1) {
                    return ItemListResponse::error($resolution['error']);
                }
                $warnings[] = ResponseMessage::warning($resolution['error']);

                continue;
            }

            $scopeKey = $this->projectKeyForProvider($providerSlug, $project);

            try {
                $fetched = $resolution['port']->listAssignedActive($scopeKey, $onlyMine);
            } catch (\Exception $e) {
                if (count($providers) === 1) {
                    return ItemListResponse::error(
                        MessageRef::key('item.list.error_fetch', ['error' => $e->getMessage()])
                    );
                }
                $warnings[] = ResponseMessage::warning(
                    MessageRef::key('item.list.error_fetch', ['error' => $e->getMessage()])
                );

                continue;
            }

            foreach ($fetched as $issue) {
                if (isset($seenKeys[$issue->key])) {
                    continue;
                }
                $seenKeys[$issue->key] = true;
                $issues[] = $issue;
                $issueProviders[] = $providerSlug;
            }
        }

        if ($issues === [] && $warnings !== []) {
            return ItemListResponse::error(
                MessageRef::key('item.list.error_fetch', ['error' => 'all providers failed']),
                $warnings,
            );
        }

        if ($sort !== null) {
            $providerByKey = [];
            foreach ($issues as $index => $issue) {
                $providerByKey[$issue->key] = $issueProviders[$index] ?? IssueTrackerProvider::Jira->value;
            }
            $issues = $this->sortIssues($issues, $sort);
            $issueProviders = array_map(
                static fn (WorkItem $issue): string => $providerByKey[$issue->key],
                $issues,
            );
        }

        $multiProvider = count(array_unique($issueProviders)) > 1;

        return ItemListResponse::success(
            $issues,
            ! $onlyMine,
            $project,
            $issueProviders,
            $multiProvider,
            $warnings,
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

        $explicit = ProjectStudConfigKeys::readIssueTrackerProvider($this->projectConfig);
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
        $matchJira = $this->scopeKeyResolver->scopeMatchesJira($scope, $this->projectConfig);
        $matchLinear = $this->scopeKeyResolver->scopeMatchesLinear($scope, $this->projectConfig);

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
        $stored = ProjectStudConfigKeys::readIssueTrackerProvider($this->projectConfig);
        $isAuto = $stored === null || $stored === IssueTrackerProvider::Auto->value;
        if (! $isAuto) {
            return false;
        }

        $providers = $this->globalProviderResolver->resolveIssueTrackerProviders($this->globalConfig);
        if (! $this->globalProviderResolver->collectsJira($providers)
            || ! $this->globalProviderResolver->collectsLinear($providers)) {
            return false;
        }

        return GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Jira, $this->globalConfig)
            && GlobalStudConfigKeys::hasCredentialsFor(IssueTrackerProvider::Linear, $this->globalConfig);
    }

    private function projectKeyForProvider(string $provider, ?string $project): ?string
    {
        if ($project !== null && trim($project) !== '') {
            return strtoupper(trim($project));
        }

        if ($provider === IssueTrackerProvider::Jira->value) {
            return $this->scopeKeyResolver->resolveJiraProjectKey($this->projectConfig);
        }

        return $this->scopeKeyResolver->resolveLinearTeamKey($this->projectConfig);
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
