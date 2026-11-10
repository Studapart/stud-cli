<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;

final class UpdatePrerequisites
{
    public function __construct(
        private readonly UpdatePrerequisiteMigrationRunner $migrationRunner,
        private readonly TestEnvironmentDetector $testEnvironmentDetector,
        private readonly GlobalMigrationService $globalMigrationService,
    ) {
    }

    public function isTestEnvironment(): bool
    {
        if ($this->testEnvironmentDetector->isTestEnvironmentByConstant()) {
            return true;
        }
        // @codeCoverageIgnoreStart
        if ($this->testEnvironmentDetector->isTestEnvironmentByBacktrace()) {
            return true;
        }
        // @codeCoverageIgnoreEnd

        return $this->testEnvironmentDetector->isTestEnvironmentByClassOrEnv();
    }

    /**
     * @param callable(): string $getConfigPath
     * @param callable(): bool $isTestEnvironment
     * @param callable(string, string): \App\DTO\MessageRef $buildErrorMessage
     */
    public function run(WorkflowEntryRecorder $recorder, callable $getConfigPath, callable $isTestEnvironment, callable $buildErrorMessage): int
    {
        return $this->migrationRunner->run($recorder, $this->globalMigrationService, $getConfigPath, $isTestEnvironment, $buildErrorMessage);
    }

    /**
     * @param callable(): string $getConfigPath
     * @param callable(): bool $isTestEnvironment
     *
     * @return array{0: array<string, mixed>, 1: string, 2: string}|null
     */
    public function loadConfigAndVersion(callable $getConfigPath, callable $isTestEnvironment): ?array
    {
        return $this->migrationRunner->loadConfigAndVersion($getConfigPath, $isTestEnvironment);
    }

    /**
     * @param callable(): bool $isTestEnvironment
     *
     * @return array<\App\Migrations\MigrationInterface>
     */
    public function discoverPrerequisiteMigrations(string $currentVersion, callable $isTestEnvironment): array
    {
        return $this->migrationRunner->discoverPrerequisiteMigrations($this->globalMigrationService, $currentVersion, $isTestEnvironment);
    }

    /**
     * @param array<\App\Migrations\MigrationInterface> $pendingMigrations
     * @param array<string, mixed> $config
     */
    public function executePendingMigrations(WorkflowEntryRecorder $recorder, array $pendingMigrations, array $config, string $configPath): int
    {
        return $this->migrationRunner->executePendingMigrations($recorder, $this->globalMigrationService, $pendingMigrations, $config, $configPath);
    }

    /**
     * @param callable(): bool $isTestEnvironment
     * @param callable(string, string): \App\DTO\MessageRef $buildErrorMessage
     */
    public function handleMigrationError(WorkflowEntryRecorder $recorder, \Throwable $e, callable $isTestEnvironment, callable $buildErrorMessage): int
    {
        return $this->migrationRunner->handleMigrationError($recorder, $e, $isTestEnvironment, $buildErrorMessage);
    }
}
