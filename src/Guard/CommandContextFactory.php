<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\Resolver\ConfigResolver;
use App\Guard\Resolver\EnvironmentResolver;
use App\Guard\Resolver\ProviderContextResolver;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * Orchestrates resolvers into an immutable CommandContext snapshot.
 */
class CommandContextFactory
{
    public function __construct(
        private readonly ConfigResolver $configResolver = new ConfigResolver(),
        private readonly ProviderContextResolver $providerContextResolver = new ProviderContextResolver(),
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
    ): CommandContext {
        $configData = $this->configResolver->resolve($globalConfig, $projectConfig);
        $providers = $this->providerContextResolver->resolve($configData['global']);
        $environment = EnvironmentResolver::fromEvent($event, $hasGitRepository);
        $flags = $environment->resolveFlags($event->getInput());

        return new CommandContext(
            globalConfig: $configData['global'],
            projectConfig: $configData['project'],
            hasGitRepository: $environment->hasGitRepository(),
            workItemProviders: $providers['workItem'],
            gitProviders: $providers['git'],
            isInteractive: $flags['interactive'],
            isQuiet: $flags['quiet'],
            isAgent: $flags['agent'],
        );
    }
}
