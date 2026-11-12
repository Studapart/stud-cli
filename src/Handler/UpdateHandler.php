<?php

declare(strict_types=1);

namespace App\Handler;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\MessageRef;
use App\DTO\WorkflowRecorder;
use App\Response\WorkflowResponse;
use App\Service\GithubGitHostingAdapter;
use App\Service\PortableUpdateService;
use App\Service\UpdateInstallContext;
use App\Service\UpdateInstallDetector;
use App\Service\UpdateInstaller;
use App\Service\UpdatePrerequisites;
use App\Service\UpdateReleaseFlow;
use App\Service\UpdateTarget;

class UpdateHandler
{
    private ?WorkflowEntryRecorder $recorder = null;

    public function __construct(
        protected readonly UpdateTarget $target,
        protected readonly UpdateReleaseFlow $releaseFlow,
        protected readonly UpdateInstaller $installer,
        protected readonly UpdatePrerequisites $prerequisites,
    ) {
    }

    private function recorder(): WorkflowEntryRecorder
    {
        return $this->recorder ??= new WorkflowRecorder();
    }

    public function handle(bool $info = false, bool $quiet = false): WorkflowResponse
    {
        $this->recorder = new WorkflowRecorder();
        $this->recorder()->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('update.section'));

        $binaryPath = $this->installer->resolveBinaryPath($this->target->binaryPath);
        $this->logVerbose('Binary path', $binaryPath);
        $this->logVerbose('Repository', "{$this->target->repository->owner}/{$this->target->repository->name}");
        $this->logVerbose('Current version', $this->target->currentVersion);

        $installContext = (new UpdateInstallDetector())->detect($binaryPath, $this->target->currentVersion);
        $githubProvider = $this->createGithubGitHostingAdapter();
        $release = $this->releaseFlow->fetcher->getReleaseOrExitCode($githubProvider, $this->recorder());
        if (is_int($release)) {
            return $this->recorder()->toResponse($release);
        }
        $this->releaseFlow->changelogPresenter->display($this->recorder(), $githubProvider, $release, $this->logVerbose(...));

        if ($info) {
            return $this->recorder()->toResponse(0);
        }

        if ($installContext->mode === UpdateInstallContext::MODE_PORTABLE) {
            return $this->recorder()->toResponse($this->handlePortableUpdate($installContext, $release, $quiet));
        }

        $pharAsset = $this->releaseFlow->fetcher->findPharAsset($release, $this->recorder());
        if (! $pharAsset) {
            return $this->recorder()->toResponse(1);
        }

        $tempFile = $this->downloadPhar($pharAsset);
        if ($tempFile === null) {
            return $this->recorder()->toResponse(1);
        }

        $verificationResult = $this->installer->verifyHash($this->recorder(), $tempFile, $pharAsset, $quiet);
        if ($verificationResult === false) {
            return $this->recorder()->toResponse($this->cleanupAndReturn($tempFile, 1));
        }

        $migrationResult = $this->runPrerequisiteMigrations();
        // @codeCoverageIgnoreStart
        if ($migrationResult !== 0) {
            return $this->recorder()->toResponse($this->cleanupAndReturn($tempFile, $migrationResult));
        }
        // @codeCoverageIgnoreEnd

        return $this->recorder()->toResponse($this->installer->replaceBinary($this->recorder(), $tempFile, $this->target));
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

        return $this->createPortableUpdateService()->update($installContext, $release, $quiet, $this->recorder());
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createGithubGitHostingAdapter(): GithubGitHostingAdapter
    {
        return $this->releaseFlow->fetcher->createGithubGitHostingAdapter();
    }

    /**
     * @codeCoverageIgnore
     */
    protected function createPortableUpdateService(): PortableUpdateService
    {
        return $this->installer->portableService($this->target->repository);
    }

    /**
     * @param array<string, mixed> $pharAsset
     *
     * @codeCoverageIgnore
     */
    protected function downloadPhar(array $pharAsset): ?string
    {
        return $this->releaseFlow->fetcher->downloadPhar($pharAsset, $this->recorder(), $this->logVerbose(...));
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
        return $this->prerequisites->run(
            $this->recorder(),
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
        return $this->prerequisites->loadConfigAndVersion(
            fn (): string => $this->getConfigPath(),
            fn (): bool => $this->isTestEnvironment(),
        );
    }

    /**
     * @return array<\App\Migrations\MigrationInterface>
     */
    protected function discoverPrerequisiteMigrations(string $currentVersion): array
    {
        return $this->prerequisites->discoverPrerequisiteMigrations(
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
        return $this->prerequisites->executePendingMigrations($this->recorder(), $pendingMigrations, $config, $configPath);
    }

    protected function handleMigrationError(\Throwable $e): int
    {
        return $this->prerequisites->handleMigrationError(
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
        return $this->prerequisites->isTestEnvironment();
    }

    private function cleanupAndReturn(string $tempFile, int $exitCode): int
    {
        $this->installer->deleteTempFile($tempFile);

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
