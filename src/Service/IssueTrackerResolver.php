<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;

/**
 * Resolves the active work-item provider for project-scoped commands
 * using global WORK_ITEM_PROVIDERS and optional project workItemProvider.
 */
final class IssueTrackerResolver
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed> $projectConfig
     * @return array{ok: true, provider: 'jira'|'linear'}|array{ok: false, error: MessageRef}
     */
    public function resolveActiveProvider(array $globalConfig, array $projectConfig): array
    {
        $globalProviders = $this->globalResolver->resolveWorkItemProviders($globalConfig);
        $hasJira = $this->globalResolver->collectsJira($globalProviders);
        $hasLinear = $this->globalResolver->collectsLinear($globalProviders);

        if ($hasJira && $hasLinear) {
            $stored = isset($projectConfig['workItemProvider']) && is_string($projectConfig['workItemProvider'])
                ? strtolower(trim($projectConfig['workItemProvider']))
                : 'auto';

            if ($stored === 'jira' || $stored === 'linear') {
                return ['ok' => true, 'provider' => $stored];
            }

            return [
                'ok' => false,
                'error' => MessageRef::key('project.workflow.error_ambiguous_provider'),
            ];
        }

        if ($hasJira) {
            return ['ok' => true, 'provider' => 'jira'];
        }

        if ($hasLinear) {
            return ['ok' => true, 'provider' => 'linear'];
        }

        return [
            'ok' => false,
            'error' => MessageRef::key('project.workflow.error_no_provider'),
        ];
    }
}
