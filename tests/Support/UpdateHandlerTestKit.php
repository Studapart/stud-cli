<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Handler\UpdateHandler;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\GlobalMigrationService;
use App\Service\Prompt\PromptInterface;
use App\Service\TestEnvironmentDetector;
use App\Service\UpdateChangelogPresenter;
use App\Service\UpdateFileService;
use App\Service\UpdatePrerequisiteMigrationRunner;
use App\Service\UpdateReleaseFetcher;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class UpdateHandlerTestKit
{
    public static function services(
        string $repoOwner,
        string $repoName,
        string $currentVersion,
        FileSystem $fileSystem,
        ChangelogParser $changelogParser,
        ?string $gitToken = null,
        ?HttpClientInterface $httpClient = null,
        ?TestEnvironmentDetector $testEnvironmentDetector = null,
        ?UpdateReleaseFetcher $releaseFetcher = null,
        ?UpdateChangelogPresenter $changelogPresenter = null,
        ?UpdatePrerequisiteMigrationRunner $migrationRunner = null,
    ): array {
        return [
            $testEnvironmentDetector ?? new TestEnvironmentDetector(),
            $releaseFetcher ?? new UpdateReleaseFetcher($repoOwner, $repoName, $currentVersion, $fileSystem, $gitToken, $httpClient),
            $changelogPresenter ?? new UpdateChangelogPresenter($changelogParser, $currentVersion),
            $migrationRunner ?? new UpdatePrerequisiteMigrationRunner($fileSystem),
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
        [$detector, $fetcher, $presenter, $runner] = self::services(
            $repoOwner,
            $repoName,
            $currentVersion,
            $fileSystem,
            $changelogParser,
            $gitToken,
            $httpClient,
            $testEnvironmentDetector,
            $releaseFetcher,
            $changelogPresenter,
            $migrationRunner,
        );

        return new UpdateHandler(
            $repoOwner,
            $repoName,
            $currentVersion,
            $binaryPath,
            $translator,
            $changelogParser,
            $updateFileService,
            $prompt,
            $fileSystem,
            $detector,
            $fetcher,
            $presenter,
            $runner,
            $gitToken,
            $httpClient,
            $globalMigrationService,
        );
    }
}
