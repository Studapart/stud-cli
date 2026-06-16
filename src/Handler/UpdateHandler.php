<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Response\WorkflowResponse;
use App\Service\ChangelogParser;
use App\Service\FileSystem;
use App\Service\GithubProvider;
use App\Service\GlobalMigrationService;
use App\Service\PortableUpdateService;
use App\Service\Prompt\PromptInterface;
use App\Service\TestEnvironmentDetector;
use App\Service\UpdateChangelogPresenter;
use App\Service\UpdateFileService;
use App\Service\UpdateInstallContext;
use App\Service\UpdateInstallDetector;
use App\Service\UpdatePrerequisiteMigrationRunner;
use App\Service\UpdateReleaseFetcher;
use App\Service\UpdateRepositoryContext;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class UpdateHandler
{
    private ?WorkflowEntryRecorder $recorder = null;

    public function __construct(
        protected readonly string $repoOwner,
        protected readonly string $repoName,
        protected readonly string $currentVersion,
        protected readonly string $binaryPath,
        protected readonly mixed $translator,
        protected readonly ChangelogParser $changelogParser,
        protected readonly UpdateFileService $updateFileService,
        protected readonly PromptInterface $prompt,
        protected readonly FileSystem $fileSystem,
        protected readonly TestEnvironmentDetector $testEnvironmentDetector,
        protected readonly UpdateReleaseFetcher $releaseFetcher,
        protected readonly UpdateChangelogPresenter $changelogPresenter,
        protected readonly UpdatePrerequisiteMigrationRunner $migrationRunner,
        protected ?string $gitToken = null,
        protected ?HttpClientInterface $httpClient = null,
        protected ?GlobalMigrationService $globalMigrationService = null,
    ) {
    }

    private function globalMigrationService(): GlobalMigrationService
    {
        if ($this->globalMigrationService !== null) {
            return $this->globalMigrationService;
        }

        $io = new \Symfony\Component\Console\Style\SymfonyStyle(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\BufferedOutput(),
        );

        return new GlobalMigrationService(
            $this->fileSystem,
            $this->translator,
            new \App\Service\Logger($io, [], true),
        );
    }

    private function recorder(): WorkflowEntryRecorder
    {
        return $this->recorder ??= new WorkflowRecorder();
    }

    public function handle(bool $info = false, bool $quiet = false): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $this->recorder()->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.section'));

        $binaryPath = $this->updateFileService->getBinaryPath($this->binaryPath);
        $this->logVerbose('Binary path', $binaryPath);
        $this->logVerbose('Repository', "{$this->repoOwner}/{$this->repoName}");
        $this->logVerbose('Current version', $this->currentVersion);

        $installContext = (new UpdateInstallDetector())->detect($binaryPath, $this->currentVersion);
        $githubProvider = $this->createGithubProvider($this->repoOwner, $this->repoName);
        $release = $this->getReleaseOrExitCode($githubProvider);
        if (is_int($release)) {
            return $this->recorder()->toResponse($release);
        }
        $this->displayChangelog($githubProvider, $release);

        if ($info) {
            return $this->recorder()->toResponse(0);
        }

        if ($installContext->mode === UpdateInstallContext::MODE_PORTABLE) {
            return $this->recorder()->toResponse($this->handlePortableUpdate($installContext, $release, $quiet));
        }

        $pharAsset = $this->findPharAsset($release);
        if (! $pharAsset) {
            return $this->recorder()->toResponse(1);
        }

        $tempFile = $this->downloadPhar($pharAsset, $this->repoOwner, $this->repoName);
        if ($tempFile === null) {
            return $this->recorder()->toResponse(1);
        }

        $verificationResult = $this->updateFileService->verifyHash($this->recorder, $tempFile, $pharAsset, $quiet);
        if ($verificationResult === false) {
            return $this->recorder()->toResponse($this->cleanupAndReturn($tempFile, 1));
        }

        $migrationResult = $this->runPrerequisiteMigrations();
        // @codeCoverageIgnoreStart
        if ($migrationResult !== 0) {
            return $this->recorder()->toResponse($this->cleanupAndReturn($tempFile, $migrationResult));
        }
        // @codeCoverageIgnoreEnd

        return $this->recorder()->toResponse($this->updateFileService->replaceBinary($this->recorder, $tempFile, $binaryPath, $this->currentVersion, $release['tag_name'] ?? 'unknown'));
    }

    /**
     * @param array<string, mixed> $release
     */
    protected function handlePortableUpdate(
        UpdateInstallContext $installContext,
        array $release,
        bool $quiet,
    ): int {
        if ($installContext->legacyPortableLayout) {
            $this->recorder()->addError(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.portable_legacy_layout'));

            return 1;
        }

        $migrationResult = $this->runPrerequisiteMigrations();
        // @codeCoverageIgnoreStart
        if ($migrationResult !== 0) {
            return $migrationResult;
        }
        // @codeCoverageIgnoreEnd

        return $this->createPortableUpdateService()->update($installContext, $release, $quiet, $this->recorder);
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createPortableUpdateService(): PortableUpdateService
    {
        return new PortableUpdateService(
            new UpdateRepositoryContext($this->repoOwner, $this->repoName, $this->gitToken),
            $this->translator,
            $this->prompt,
            $this->httpClient
        );
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createGithubProvider(string $repoOwner, string $repoName): GithubProvider
    {
        return $this->releaseFetcher->createGithubProvider();
    }

    /**
     * @return array{release: array<string, mixed>|null, is404: bool}
     *
     * @codeCoverageIgnore
     */
    protected function fetchLatestRelease(GithubProvider $githubProvider): array
    {
        return $this->releaseFetcher->fetchLatestRelease($githubProvider, $this->recorder());
    }

    /**
     * @return int|array<string, mixed>
     */
    protected function getReleaseOrExitCode(GithubProvider $githubProvider): int|array
    {
        return $this->releaseFetcher->getReleaseOrExitCode($githubProvider, $this->recorder());
    }

    /**
     * @param array<string, mixed> $release
     *
     * @codeCoverageIgnore
     */
    protected function isAlreadyLatestVersion(array $release): bool
    {
        return $this->releaseFetcher->isAlreadyLatestVersion($release, $this->recorder());
    }

    /**
     * @param array<string, mixed> $release
     *
     * @return array<string, mixed>|null
     *
     * @codeCoverageIgnore
     */
    protected function findPharAsset(array $release): ?array
    {
        return $this->releaseFetcher->findPharAsset($release, $this->recorder());
    }

    /**
     * @param array<string, mixed> $pharAsset
     *
     * @codeCoverageIgnore
     */
    protected function downloadPhar(array $pharAsset, string $repoOwner, string $repoName): ?string
    {
        return $this->releaseFetcher->downloadPhar($pharAsset, $this->recorder(), $this->logVerbose(...));
    }

    /**
     * @codeCoverageIgnore
     */
    protected function logVerbose(string $label, string $value): void
    {
        $this->recorder()->addLine(WorkflowEntryRecorder::VERBOSITY_VERBOSE, "  <fg=gray>{$label}: {$value}</>");
    }

    protected function runPrerequisiteMigrations(): int
    {
        return $this->migrationRunner->run(
            $this->recorder(),
            $this->globalMigrationService(),
            fn (): string => $this->getConfigPath(),
            fn (): bool => $this->isTestEnvironment(),
            fn (string $id, string $msg): MessageRef => $this->getErrorMessage($id, $msg),
        );
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string}|null
     */
    protected function loadConfigAndVersion(): ?array
    {
        return $this->migrationRunner->loadConfigAndVersion(
            fn (): string => $this->getConfigPath(),
            fn (): bool => $this->isTestEnvironment(),
        );
    }

    /**
     * @return array<\App\Migrations\MigrationInterface>
     */
    protected function discoverPrerequisiteMigrations(string $currentVersion): array
    {
        return $this->migrationRunner->discoverPrerequisiteMigrations(
            $this->globalMigrationService(),
            $currentVersion,
            fn (): bool => $this->isTestEnvironment(),
        );
    }

    /**
     * @param array<\App\Migrations\MigrationInterface> $pendingMigrations
     * @param array<string, mixed> $config
     */
    protected function executePendingMigrations(array $pendingMigrations, array $config, string $configPath): int
    {
        return $this->migrationRunner->executePendingMigrations(
            $this->recorder(),
            $this->globalMigrationService(),
            $pendingMigrations,
            $config,
            $configPath,
        );
    }

    protected function handleMigrationError(\Throwable $e): int
    {
        return $this->migrationRunner->handleMigrationError(
            $this->recorder(),
            $e,
            fn (): bool => $this->isTestEnvironment(),
            fn (string $id, string $msg): MessageRef => $this->getErrorMessage($id, $msg),
        );
    }

    /**
     * @codeCoverageIgnore
     */
    protected function getConfigPath(): string
    {
        if ($this->isTestEnvironment()) {
            return '/test/.config/stud/config.yml';
        }

        // @codeCoverageIgnoreStart
        $home = $_SERVER['HOME'] ?? throw new \RuntimeException('Could not determine home directory.');

        return rtrim($home, '/') . '/.config/stud/config.yml';
        // @codeCoverageIgnoreEnd
    }

    protected function isTestEnvironment(): bool
    {
        if ($this->isTestEnvironmentByConstant()) {
            return true;
        }
        // @codeCoverageIgnoreStart
        if ($this->isTestEnvironmentByBacktrace()) {
            return true;
        }
        // @codeCoverageIgnoreEnd
        if ($this->isTestEnvironmentByClassOrEnv()) {
            return true;
        }

        return false;
    }

    protected function isTestEnvironmentByConstant(): bool
    {
        return $this->testEnvironmentDetector->isTestEnvironmentByConstant();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function isTestEnvironmentByBacktrace(): bool
    {
        return $this->testEnvironmentDetector->isTestEnvironmentByBacktrace();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function isTestEnvironmentByClassOrEnv(): bool
    {
        return $this->testEnvironmentDetector->isTestEnvironmentByClassOrEnv();
    }

    /**
     * @param array<string, mixed> $release
     *
     * @codeCoverageIgnore
     */
    protected function displayChangelog(GithubProvider $githubProvider, array $release): void
    {
        $this->changelogPresenter->display($this->recorder(), $githubProvider, $release, $this->logVerbose(...));
    }

    private function cleanupAndReturn(string $tempFile, int $exitCode): int
    {
        try {
            $this->fileSystem->delete($tempFile);
        } catch (\RuntimeException) {
        }

        return $exitCode;
    }

    protected function getErrorMessage(string $migrationId, string $errorMessage): MessageRef
    {
        return MessageRef::key(
            'migration.error',
            ['id' => $migrationId, 'error' => $errorMessage],
            "Migration {$migrationId} failed: {$errorMessage}",
        );
    }
}
