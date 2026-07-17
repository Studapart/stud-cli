<?php

declare(strict_types=1);

namespace App\Guard;

use App\Guard\DTO\ProviderResolutionRequest;
use App\Guard\Resolver\CommandInputResolver;
use App\Guard\Resolver\ConfigResolver;
use App\Guard\Resolver\EffectiveProviderResolver;
use App\Guard\Resolver\EnvironmentResolver;
use App\Guard\Resolver\IssueTrackerProviderResolver;
use App\Service\GitRepository;
use Symfony\Component\Console\Event\ConsoleCommandEvent;

/**
 * Orchestrates resolvers into an immutable CommandContext snapshot.
 */
class CommandContextFactory
{
    public function __construct(
        private readonly ConfigResolver $configResolver = new ConfigResolver(),
        private readonly EffectiveProviderResolver $effectiveProviderResolver = new EffectiveProviderResolver(),
        private readonly IssueTrackerProviderResolver $issueTrackerProviderResolver = new IssueTrackerProviderResolver(),
        private readonly ?CommandInputResolver $commandInputResolver = null,
    ) {
    }

    /**
     * @param array<string, mixed>      $globalConfig
     * @param array<string, mixed>|null $projectConfig
     */
    public function create(
        ConsoleCommandEvent $event,
        array $globalConfig,
        ?array $projectConfig,
        bool $hasGitRepository,
        ?string $resolvedGitProvider,
        string $commandName,
        ?GitRepository $gitRepository = null,
    ): CommandContext {
        $configData = $this->configResolver->resolve($globalConfig, $projectConfig);
        $environment = EnvironmentResolver::fromEvent($event, $hasGitRepository);
        $flags = $environment->resolveFlags($event->getInput());
        $inputResolver = $this->commandInputResolver ?? new CommandInputResolver(gitRepository: $gitRepository);
        $invocation = $inputResolver->resolveInvocationContext($event->getInput(), $commandName);
        $profile = WorkItemCommandProfileRegistry::forCommand($commandName);

        $effectiveGitProviders = $this->effectiveProviderResolver->resolveGitProviders(
            $configData['global'],
            $configData['project'],
            $environment->hasGitRepository(),
            $resolvedGitProvider,
        );

        $providerResolution = null;
        $issueTrackerProviders = [];
        $issueTrackerProviderAmbiguous = false;
        $providerResolutionBlock = null;

        if ($profile !== null) {
            $providerResolution = $this->issueTrackerProviderResolver->resolve(
                new ProviderResolutionRequest(
                    commandName: $commandName,
                    commandProfile: $profile,
                    globalConfig: $configData['global'],
                    projectConfig: $configData['project'],
                    cliOverride: $invocation->providerOverride,
                    issueKey: $invocation->issueKey,
                    scopeKey: $invocation->scopeKey,
                    filterName: $invocation->filterName,
                    branchIssueKey: $invocation->branchIssueKey,
                    attachmentUrl: $invocation->attachmentUrl,
                ),
            );
            if ($providerResolution->isBlocked()) {
                $providerResolutionBlock = $providerResolution->block;
                $issueTrackerProviderAmbiguous = true;
            } else {
                $issueTrackerProviders = $providerResolution->providers;
            }
        } else {
            $capabilities = CommandHandlerRegistry::resolveCapabilities($commandName);
            $dualAutoAggregate = $capabilities->has(\App\Guard\Capability\IssueTracker\JiraAware::class)
                && $capabilities->has(\App\Guard\Capability\IssueTracker\LinearAware::class);
            $issueTrackerResolution = $this->effectiveProviderResolver->resolveIssueTrackerProviders(
                $configData['global'],
                $configData['project'],
                $invocation->providerOverride,
                $dualAutoAggregate,
            );
            $issueTrackerProviders = $issueTrackerResolution['providers'];
            $issueTrackerProviderAmbiguous = $issueTrackerResolution['ambiguous'];
        }

        return new CommandContext(
            globalConfig: $configData['global'],
            projectConfig: $configData['project'],
            hasGitRepository: $environment->hasGitRepository(),
            issueTrackerProviders: $issueTrackerProviders,
            gitProviders: $effectiveGitProviders,
            isInteractive: $flags['interactive'],
            isQuiet: $flags['quiet'],
            isAgent: $flags['agent'],
            issueTrackerProviderAmbiguous: $issueTrackerProviderAmbiguous,
            issueTrackerProviderOverride: $invocation->providerOverride,
            providerOverrideError: $invocation->providerOverrideError,
            providerResolutionBlock: $providerResolutionBlock,
            invocationContext: $invocation,
            providerResolution: $providerResolution,
        );
    }
}
