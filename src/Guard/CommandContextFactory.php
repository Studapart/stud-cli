<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\Resolver\ConfigResolver;
use App\Guard\Resolver\EffectiveProviderResolver;
use App\Guard\Resolver\EnvironmentResolver;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * Orchestrates resolvers into an immutable CommandContext snapshot.
 */
class CommandContextFactory
{
    public function __construct(
        private readonly ConfigResolver $configResolver = new ConfigResolver(),
        private readonly EffectiveProviderResolver $effectiveProviderResolver = new EffectiveProviderResolver(),
    ) {
    }

    /**
     * @param array<string, mixed> $globalConfig
     * @param array<string, mixed>|null $projectConfig
     */
    public function create(
        ConsoleCommandEvent $event,
        array $globalConfig,
        ?array $projectConfig,
        bool $hasGitRepository,
        ?string $resolvedGitProvider = null,
    ): CommandContext {
        $configData = $this->configResolver->resolve($globalConfig, $projectConfig);
        $environment = EnvironmentResolver::fromEvent($event, $hasGitRepository);
        $flags = $environment->resolveFlags($event->getInput());
        $effectiveGitProviders = $this->effectiveProviderResolver->resolveGitProviders(
            $configData['global'],
            $configData['project'],
            $environment->hasGitRepository(),
            $resolvedGitProvider,
        );
        $workItemResolution = $this->effectiveProviderResolver->resolveWorkItemProviders(
            $configData['global'],
            $configData['project'],
        );

        return new CommandContext(
            globalConfig: $configData['global'],
            projectConfig: $configData['project'],
            hasGitRepository: $environment->hasGitRepository(),
            workItemProviders: $workItemResolution['providers'],
            gitProviders: $effectiveGitProviders,
            isInteractive: $flags['interactive'],
            isQuiet: $flags['quiet'],
            isAgent: $flags['agent'],
            workItemProviderAmbiguous: $workItemResolution['ambiguous'],
        );
    }
}
