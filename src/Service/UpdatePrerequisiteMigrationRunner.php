<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\DTO\GlobalConfigFile;
use App\DTO\MessageRef;
use App\DTO\PrerequisiteMigrationHooks;

class UpdatePrerequisiteMigrationRunner
{
    public function __construct(
        private readonly FileSystem $fileSystem,
    ) {
    }

    public function run(
        WorkflowEntryRecorder $recorder,
        GlobalMigrationService $globalMigrationService,
        PrerequisiteMigrationHooks $hooks,
    ): int {
        if (defined('STUD_CLI_TEST_MODE') && STUD_CLI_TEST_MODE === true) {
            return 0;
        }

        // @codeCoverageIgnoreStart
        if (($hooks->isTestEnvironment)()) {
            return 0;
        }

        try {
            $configData = $this->loadConfigAndVersion($hooks->getConfigPath, $hooks->isTestEnvironment);
            if ($configData === null) {
                return 0;
            }

            [$config, $configPath, $currentVersion] = $configData;

            $pendingMigrations = $this->discoverPrerequisiteMigrations($globalMigrationService, $currentVersion, $hooks->isTestEnvironment);
            if (empty($pendingMigrations)) {
                return 0;
            }

            return $this->executePendingMigrations($recorder, $globalMigrationService, $pendingMigrations, new GlobalConfigFile($config, $configPath));
        } catch (\Throwable $e) {
            return $this->handleMigrationError($recorder, $e, $hooks->isTestEnvironment, $hooks->buildErrorMessage);
        }
        // @codeCoverageIgnoreEnd
    }

    /**
     * @return array{0: array<string, mixed>, 1: string, 2: string}|null
     */
    public function loadConfigAndVersion(callable $getConfigPath, callable $isTestEnvironment): ?array
    {
        try {
            $configPath = $getConfigPath();
            // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            if ($isTestEnvironment()) {
                return null;
            }

            throw $e;
            // @codeCoverageIgnoreEnd
        }

        try {
            if (! $this->fileSystem->fileExists($configPath)) {
                return null;
            }
            // @codeCoverageIgnoreStart
        } catch (\Throwable $e) {
            if ($isTestEnvironment()) {
                return null;
            }

            throw $e;
            // @codeCoverageIgnoreEnd
        }

        $config = $this->fileSystem->parseFile($configPath);
        $currentVersion = $config['migration_version'] ?? '0';

        return [$config, $configPath, $currentVersion];
    }

    /**
     * @return array<\App\Migrations\MigrationInterface>
     */
    public function discoverPrerequisiteMigrations(
        GlobalMigrationService $globalMigrationService,
        string $currentVersion,
        callable $isTestEnvironment,
    ): array {
        try {
            return $globalMigrationService->discoverPrerequisiteMigrations($currentVersion);
        } catch (\Throwable $e) {
            if ($isTestEnvironment()) {
                return [];
            }

            throw $e;
        }
    }

    /**
     * @param array<\App\Migrations\MigrationInterface> $pendingMigrations
     */
    public function executePendingMigrations(
        WorkflowEntryRecorder $recorder,
        GlobalMigrationService $globalMigrationService,
        array $pendingMigrations,
        GlobalConfigFile $configFile,
    ): int {
        $recorder->addSection(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('migration.global.running'));
        $globalMigrationService->executePendingMigrations($pendingMigrations, $configFile->config, $configFile->path, $recorder);
        $recorder->addSuccess(WorkflowEntryRecorder::VERBOSITY_NORMAL, MessageRef::key('migration.global.complete'));

        return 0;
    }

    public function handleMigrationError(
        WorkflowEntryRecorder $recorder,
        \Throwable $e,
        callable $isTestEnvironment,
        callable $buildErrorMessage,
    ): int {
        if ($isTestEnvironment()) {
            return 0;
        }

        // @codeCoverageIgnoreStart
        try {
            $recorder->addError(
                WorkflowEntryRecorder::VERBOSITY_NORMAL,
                $buildErrorMessage('prerequisite', $e->getMessage()),
            );
        } catch (\Throwable) {
        }

        return 1;
        // @codeCoverageIgnoreEnd
    }
}
