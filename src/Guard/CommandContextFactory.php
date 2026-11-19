<?php

declare(strict_types=1);

namespace App\Guard;

use App\DTO\CommandContextInput;
use App\Guard\Resolver\CommandInputResolver;
use App\Guard\Resolver\ConfigResolver;
use App\Guard\Resolver\EffectiveProviderResolver;
use App\Guard\Resolver\EnvironmentResolver;
use App\Guard\Resolver\IssueTrackerProviderSelector;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * Orchestrates resolvers into an immutable CommandContext snapshot.
 */
class CommandContextFactory
{
    public function __construct(
        private readonly ConfigResolver $configResolver = new ConfigResolver(),
        private readonly EffectiveProviderResolver $effectiveProviderResolver = new EffectiveProviderResolver(),
        private readonly IssueTrackerProviderSelector $issueTrackerProviderSelector = new IssueTrackerProviderSelector(),
        private readonly ?CommandInputResolver $commandInputResolver = null,
    ) {
    }

    public function create(ConsoleCommandEvent $event, CommandContextInput $input): CommandContext
    {
        $configData = $this->configResolver->resolve($input->globalConfig, $input->projectConfig);
        $environment = EnvironmentResolver::fromEvent($event, $input->hasGitRepository);
        $flags = $environment->resolveFlags($event->getInput());
        $inputResolver = $this->commandInputResolver ?? new CommandInputResolver(gitRepository: $input->gitRepository);
        $invocation = $inputResolver->resolveInvocationContext($event->getInput(), $input->commandName);

        $effectiveGitProviders = $this->effectiveProviderResolver->resolveGitProviders(
            $configData['global'],
            $configData['project'],
            $environment->hasGitRepository(),
            $input->resolvedGitProvider,
        );
        $issueTracker = $this->issueTrackerProviderSelector->select(
            $input->commandName,
            $configData['global'],
            $configData['project'],
            $invocation,
        );

        return new CommandContext(
            globalConfig: $configData['global'],
            projectConfig: $configData['project'],
            hasGitRepository: $environment->hasGitRepository(),
            issueTrackerProviders: $issueTracker['providers'],
            gitProviders: $effectiveGitProviders,
            isInteractive: $flags['interactive'],
            isQuiet: $flags['quiet'],
            isAgent: $flags['agent'],
            issueTrackerProviderAmbiguous: $issueTracker['ambiguous'],
            issueTrackerProviderOverride: $invocation->providerOverride,
            providerOverrideError: $invocation->providerOverrideError,
            providerResolutionBlock: $issueTracker['block'],
            invocationContext: $invocation,
            providerResolution: $issueTracker['resolution'],
        );
    }
}
