<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\DTO\FetchHintContext;
use App\DTO\ItemListConfigs;
use App\DTO\ReleaseDocumentPaths;
use App\Handler\ItemCreateFieldFlow;
use App\Handler\ItemCreateHandler;
use App\Handler\ItemCreatePrompts;
use App\Handler\ItemListHandler;
use App\Handler\ReleaseHandler;
use App\Handler\SubmitHandler;
use App\Service\DualTrackerPolicy;
use App\Service\FetchHintIssueTrackerPort;
use App\Service\FileSystem;
use App\Service\GitHostingPort;
use App\Service\GitRepository;
use App\Service\IssueTrackerClients;
use App\Service\IssueTrackerFactory;
use App\Service\IssueTrackerFetchFailureHintBuilder;
use App\Service\IssueTrackerPort;
use App\Service\IssueTrackerPortSupplier;
use App\Service\IssueTrackerResolver;
use App\Service\IssueTrackerScopeResolvers;
use App\Service\ItemCreatePortSource;
use App\Service\ItemCreateProjectResolver;
use App\Service\ProjectScopeKeyResolver;
use App\Service\Prompt\PromptInterface;
use App\Service\SubmitAssembly;
use App\Service\SubmitGit;

final class ConstructorFixtures
{
    public static function releaseHandler(
        GitRepository $gitRepository,
        PromptInterface $prompt,
        FileSystem $fileSystem,
        ReleaseDocumentPaths $paths = new ReleaseDocumentPaths(),
    ): ReleaseHandler {
        return new ReleaseHandler($gitRepository, $prompt, $fileSystem, $paths);
    }

    public static function submitHandler(
        SubmitGit $git,
        IssueTrackerPort $provider,
        ?GitHostingPort $githubProvider,
        SubmitAssembly $assembly,
    ): SubmitHandler {
        return new SubmitHandler($git, $provider, $githubProvider, $assembly);
    }

    public static function itemListHandler(
        IssueTrackerPortSupplier $portSupplier,
        ItemListConfigs $configs,
        ?ProjectScopeKeyResolver $scopeKeyResolver = null,
        ?DualTrackerPolicy $dualTracker = null,
    ): ItemListHandler {
        return new ItemListHandler(
            $portSupplier,
            $configs,
            $scopeKeyResolver ?? new ProjectScopeKeyResolver(),
            $dualTracker ?? new DualTrackerPolicy(),
        );
    }

    public static function issueTrackerPortSupplier(
        IssueTrackerFactory $factory,
        IssueTrackerResolver $resolver,
        IssueTrackerClients $clients,
        ?IssueTrackerScopeResolvers $scopes = null,
    ): IssueTrackerPortSupplier {
        return new IssueTrackerPortSupplier(
            $factory,
            $resolver,
            $clients,
            $scopes ?? new IssueTrackerScopeResolvers(),
        );
    }

    public static function itemCreateHandler(
        ItemCreateProjectResolver $projectResolver,
        ItemCreatePrompts $prompts,
        IssueTrackerPort $provider,
        ItemCreateFieldFlow $fieldFlow,
    ): ItemCreateHandler {
        return new ItemCreateHandler($projectResolver, $prompts, ItemCreatePortSource::pinned($provider), $fieldFlow);
    }

    public static function fetchHintPort(
        IssueTrackerPort $inner,
        IssueTrackerFetchFailureHintBuilder $hintBuilder,
        FetchHintContext $context,
    ): FetchHintIssueTrackerPort {
        return new FetchHintIssueTrackerPort($inner, $hintBuilder, $context);
    }
}
