<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\Service\GlobalConfigProviderResolver;

/**
 * Resolves effective work-item and git provider lists from global config.
 */
class ProviderContextResolver
{
    public function __construct(
        private readonly GlobalConfigProviderResolver $providerResolver = new GlobalConfigProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @return array{workItem: list<string>, git: list<string>}
     */
    public function resolve(array $globalConfig): array
    {
        return [
            'workItem' => $this->providerResolver->resolveWorkItemProviders($globalConfig),
            'git' => $this->providerResolver->resolveGitProviders($globalConfig),
        ];
    }

    public function providerResolver(): GlobalConfigProviderResolver
    {
        return $this->providerResolver;
    }
}
