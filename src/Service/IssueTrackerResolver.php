<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\Enum\WorkItemProvider;
use App\Exception\IssueTrackerException;

/**
 * Resolves the active work-item provider for project-scoped commands
 * using global WORK_ITEM_PROVIDERS and optional project workItemProvider.
 */
final class IssueTrackerResolver
{
    private readonly IssueTrackerFactory $factory;

    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
        ?IssueTrackerFactory $factory = null,
    ) {
        $this->factory = $factory ?? new IssueTrackerFactory($this->globalResolver);
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     *
     * @return array{ok: true, provider: 'jira'|'linear'}|array{ok: false, error: MessageRef}
     */
    public function resolveActiveProvider(array $globalConfig, array $projectConfig): array
    {
        try {
            $provider = WorkItemProvider::tryFrom($this->factory->resolveType(null, $globalConfig, $projectConfig));
            if ($provider === null) {
                return ['ok' => false, 'error' => IssueTrackerException::notConfigured()->messageRef];
            }

            return ['ok' => true, 'provider' => $provider->value];
        } catch (IssueTrackerException $e) {
            return ['ok' => false, 'error' => $e->messageRef];
        }
    }
}
