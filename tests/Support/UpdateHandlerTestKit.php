<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Handler\UpdateHandler;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\GlobalMigrationService;
use App\Service\Logger;
use App\Service\OutputVerbosityGate;
use App\Service\Prompt\PromptInterface;
use App\Service\TestEnvironmentDetector;
use App\Service\UpdateChangelogPresenter;
use App\Service\UpdateFileService;
use App\Service\UpdateInstaller;
use App\Service\UpdatePrerequisiteMigrationRunner;
use App\Service\UpdatePrerequisites;
use App\Service\UpdateReleaseFetcher;
use App\Service\UpdateReleaseFlow;
use App\Service\UpdateRepositoryContext;
use App\Service\UpdateTarget;
use App\Service\WorkflowChannelColorizer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UpdateHandlerTestKit
{
    /**
     * Builds the handler collaborators with test defaults; any override replaces the default.
     *
     * @return array{0: UpdateTarget, 1: UpdateReleaseFlow, 2: UpdateInstaller, 3: UpdatePrerequisites}
     */
    public static function parts(
        string $repoOwner,
        string $repoName,
        string $currentVersion,
        string $binaryPath,
        mixed $translator,
        ChangelogParser $changelogParser,
        UpdateFileService $updateFileService,
        PromptInterface $prompt,
        FileSystem $fileSystem,
        ?string $gitToken = null,
        ?HttpClientInterface $httpClient = null,
        ?GlobalMigrationService $globalMigrationService = null,
        ?TestEnvironmentDetector $testEnvironmentDetector = null,
        ?UpdateReleaseFetcher $releaseFetcher = null,
        ?UpdateChangelogPresenter $changelogPresenter = null,
        ?UpdatePrerequisiteMigrationRunner $migrationRunner = null,
    ): array {
        $repository = new UpdateRepositoryContext($repoOwner, $repoName, $gitToken);

        return [
            new UpdateTarget($repository, $currentVersion, $binaryPath),
            new UpdateReleaseFlow(
                $releaseFetcher ?? new UpdateReleaseFetcher($repository, $currentVersion, $fileSystem, $httpClient),
                $changelogPresenter ?? new UpdateChangelogPresenter($changelogParser, $currentVersion),
            ),
            new UpdateInstaller($updateFileService, $prompt, $fileSystem, $httpClient),
            new UpdatePrerequisites(
                $migrationRunner ?? new UpdatePrerequisiteMigrationRunner($fileSystem),
                $testEnvironmentDetector ?? new TestEnvironmentDetector(),
                $globalMigrationService ?? self::defaultGlobalMigrationService($fileSystem, $translator),
            ),
        ];
    }

    public static function create(
        string $repoOwner,
        string $repoName,
        string $currentVersion,
        string $binaryPath,
        mixed $translator,
        ChangelogParser $changelogParser,
        UpdateFileService $updateFileService,
        PromptInterface $prompt,
        FileSystem $fileSystem,
        ?string $gitToken = null,
        ?HttpClientInterface $httpClient = null,
        ?GlobalMigrationService $globalMigrationService = null,
        ?TestEnvironmentDetector $testEnvironmentDetector = null,
        ?UpdateReleaseFetcher $releaseFetcher = null,
        ?UpdateChangelogPresenter $changelogPresenter = null,
        ?UpdatePrerequisiteMigrationRunner $migrationRunner = null,
    ): UpdateHandler {
        return new UpdateHandler(...self::parts(
            $repoOwner,
            $repoName,
            $currentVersion,
            $binaryPath,
            $translator,
            $changelogParser,
            $updateFileService,
            $prompt,
            $fileSystem,
            $gitToken,
            $httpClient,
            $globalMigrationService,
            $testEnvironmentDetector,
            $releaseFetcher,
            $changelogPresenter,
            $migrationRunner,
        ));
    }

    private static function defaultGlobalMigrationService(FileSystem $fileSystem, mixed $translator): GlobalMigrationService
    {
        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());

        return new GlobalMigrationService(
            $fileSystem,
            $translator,
            new Logger($io, new WorkflowChannelColorizer([]), new OutputVerbosityGate($io, true)),
        );
    }
}
