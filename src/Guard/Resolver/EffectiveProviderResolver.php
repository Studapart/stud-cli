<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\Service\GlobalConfigProviderResolver;
use App\Service\IssueTrackerResolver;

/**
 * Resolves git and work-item providers in the context of the current command run.
 *
 * Global GIT_PROVIDERS / WORK_ITEM_PROVIDERS describe what is configured globally;
 * project config and git remote detection narrow what this command actually needs.
 */
class EffectiveProviderResolver
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $globalResolver = new GlobalConfigProviderResolver(),
        private readonly IssueTrackerResolver $workItemResolver = new IssueTrackerResolver(),
    ) {
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @return list<string>
     */
    public function resolveGitProviders(
        array $globalConfig,
        ?array $projectConfig,
        bool $hasGitRepository,
        ?string $resolvedGitProvider,
    ): array {
        if ($resolvedGitProvider !== null && in_array($resolvedGitProvider, ['github', 'gitlab'], true)) {
            return [$resolvedGitProvider];
        }

        if ($hasGitRepository && $projectConfig !== null) {
            $stored = $projectConfig['gitProvider'] ?? null;
            if (is_string($stored) && in_array($stored, ['github', 'gitlab'], true)) {
                return [$stored];
            }
        }

        return $this->globalResolver->resolveGitProviders($globalConfig);
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @return array{providers: list<string>, ambiguous: bool}
     */
    public function resolveWorkItemProviders(array $globalConfig, ?array $projectConfig): array
    {
        if ($projectConfig === null) {
            return [
                'providers' => $this->globalResolver->resolveWorkItemProviders($globalConfig),
                'ambiguous' => false,
            ];
        }

        $active = $this->workItemResolver->resolveActiveProvider($globalConfig, $projectConfig);
        if ($active['ok']) {
            return ['providers' => [$active['provider']], 'ambiguous' => false];
        }

        return [
            'providers' => $this->globalResolver->resolveWorkItemProviders($globalConfig),
            'ambiguous' => true,
        ];
    }
}
