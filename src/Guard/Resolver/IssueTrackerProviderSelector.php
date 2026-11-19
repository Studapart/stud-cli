<?php

declare(strict_types=1);

namespace App\Guard\Resolver;

use App\DTO\MessageRef;
use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Guard\CommandHandlerRegistry;
use App\Guard\DTO\CommandInvocationContext;
use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\DTO\ProviderResolutionResult;
use App\Guard\WorkItemCommandProfileRegistry;

/**
 * Selects the effective issue-tracker providers for a command: unified resolution for
 * work-item profiles, capability-based effective providers for every other command.
 */
class IssueTrackerProviderSelector
{
    public function __construct(
        private readonly IssueTrackerProviderResolver $issueTrackerProviderResolver = new IssueTrackerProviderResolver(),
        private readonly EffectiveProviderResolver $effectiveProviderResolver = new EffectiveProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @return array{providers: list<string>, ambiguous: bool, block: ?MessageRef, resolution: ?ProviderResolutionResult}
     */
    public function select(
        string $commandName,
        array $globalConfig,
        ?array $projectConfig,
        CommandInvocationContext $invocation,
    ): array {
        $profile = WorkItemCommandProfileRegistry::forCommand($commandName);
        if ($profile === null) {
            return $this->selectFromCapabilities($commandName, $globalConfig, $projectConfig, $invocation->providerOverride);
        }

        $resolution = $this->issueTrackerProviderResolver->resolve(
            new ProviderResolutionRequest(
                commandName: $commandName,
                commandProfile: $profile,
                globalConfig: $globalConfig,
                projectConfig: $projectConfig,
                cliOverride: $invocation->providerOverride,
                issueKey: $invocation->issueKey,
                scopeKey: $invocation->scopeKey,
                filterName: $invocation->filterName,
                branchIssueKey: $invocation->branchIssueKey,
                attachmentUrl: $invocation->attachmentUrl,
            ),
        );

        if ($resolution->isBlocked()) {
            return ['providers' => [], 'ambiguous' => true, 'block' => $resolution->block, 'resolution' => $resolution];
        }

        return ['providers' => $resolution->providers, 'ambiguous' => false, 'block' => null, 'resolution' => $resolution];
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     * @return array{providers: list<string>, ambiguous: bool, block: null, resolution: null}
     */
    protected function selectFromCapabilities(
        string $commandName,
        array $globalConfig,
        ?array $projectConfig,
        ?string $providerOverride,
    ): array {
        $capabilities = CommandHandlerRegistry::resolveCapabilities($commandName);
        $resolution = $this->effectiveProviderResolver->resolveIssueTrackerProviders(
            $globalConfig,
            $projectConfig,
            $providerOverride,
            $capabilities->has(JiraAware::class) && $capabilities->has(LinearAware::class),
        );

        return [
            'providers' => $resolution['providers'],
            'ambiguous' => $resolution['ambiguous'],
            'block' => null,
            'resolution' => null,
        ];
    }
}
