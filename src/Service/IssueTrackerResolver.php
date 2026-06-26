<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
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
    ) {
        $this->factory = new IssueTrackerFactory($this->globalResolver);
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
            $provider = $this->factory->resolveType(null, $globalConfig, $projectConfig);

            return match ($provider) {
                'jira' => ['ok' => true, 'provider' => 'jira'],
                'linear' => ['ok' => true, 'provider' => 'linear'],
                default => ['ok' => false, 'error' => IssueTrackerException::notConfigured()->messageRef],
            };
        } catch (IssueTrackerException $e) {
            return ['ok' => false, 'error' => $e->messageRef];
        }
    }
}
