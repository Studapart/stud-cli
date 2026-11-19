<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\ItemListAggregate;
use App\DTO\ItemListConfigs;
use App\DTO\MessageRef;
use App\Enum\IssueTrackerProvider;

/**
 * Fetches the active work items of one issue-tracker provider for stud items:list.
 */
class ItemListProviderFetcher
{
    public function __construct(
        private readonly IssueTrackerPortSupplier $portSupplier,
        private readonly ItemListConfigs $configs,
        private readonly ProjectScopeKeyResolver $scopeKeyResolver,
    ) {
    }

    /**
     * @return MessageRef|string|null the provider failure, or null when its items were added
     */
    public function fetchInto(ItemListAggregate $aggregate, string $providerSlug, ?string $project, bool $onlyMine): MessageRef|string|null
    {
        $resolution = $this->portSupplier->resolveForProvider($providerSlug, $this->configs->global, $this->configs->project);
        if (! $resolution['ok']) {
            return $resolution['error'];
        }

        try {
            $fetched = $resolution['port']->listAssignedActive($this->projectKeyForProvider($providerSlug, $project), $onlyMine);
        } catch (\Throwable $e) {
            return MessageRef::key('item.list.error_fetch', ['error' => $e->getMessage()]);
        }
        $aggregate->addIssues($providerSlug, $fetched);

        return null;
    }

    private function projectKeyForProvider(string $provider, ?string $project): ?string
    {
        if ($project !== null && trim($project) !== '') {
            return strtoupper(trim($project));
        }

        if ($provider === IssueTrackerProvider::Jira->value) {
            return $this->scopeKeyResolver->resolveJiraProjectKey($this->configs->project);
        }

        return $this->scopeKeyResolver->resolveLinearTeamKey($this->configs->project);
    }
}
