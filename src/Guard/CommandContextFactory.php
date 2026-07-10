<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\Capability\IssueTracker\JiraAware;
use App\Guard\Capability\IssueTracker\LinearAware;
use App\Guard\Resolver\CommandInputResolver;
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
        private readonly CommandInputResolver $commandInputResolver = new CommandInputResolver(),
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
        ?string $resolvedGitProvider,
        string $commandName,
    ): CommandContext {
        $configData = $this->configResolver->resolve($globalConfig, $projectConfig);
        $environment = EnvironmentResolver::fromEvent($event, $hasGitRepository);
        $flags = $environment->resolveFlags($event->getInput());
        $overrideResolution = $this->commandInputResolver->resolveIssueTrackerProviderOverride(
            $event->getInput(),
            $commandName,
        );
        $capabilities = CommandHandlerRegistry::resolveCapabilities($commandName);
        $dualAutoAggregate = $capabilities->has(JiraAware::class) && $capabilities->has(LinearAware::class);
        $effectiveGitProviders = $this->effectiveProviderResolver->resolveGitProviders(
            $configData['global'],
            $configData['project'],
            $environment->hasGitRepository(),
            $resolvedGitProvider,
        );
        $issueTrackerResolution = $this->effectiveProviderResolver->resolveIssueTrackerProviders(
            $configData['global'],
            $configData['project'],
            $overrideResolution['override'],
            $dualAutoAggregate,
        );

        return new CommandContext(
            globalConfig: $configData['global'],
            projectConfig: $configData['project'],
            hasGitRepository: $environment->hasGitRepository(),
            issueTrackerProviders: $issueTrackerResolution['providers'],
            gitProviders: $effectiveGitProviders,
            isInteractive: $flags['interactive'],
            isQuiet: $flags['quiet'],
            isAgent: $flags['agent'],
            issueTrackerProviderAmbiguous: $issueTrackerResolution['ambiguous'],
            issueTrackerProviderOverride: $overrideResolution['override'],
            providerOverrideError: $overrideResolution['error'],
        );
    }
}
