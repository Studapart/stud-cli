<?php

declare(strict_types=1);

namespace App\Service;

use App\Contract\WorkflowEntryRecorder;
use App\Migrations\MigrationInterface;

/**
 * Discovers and executes global migrations using presentation-layer logger for migration instances.
 */
class GlobalMigrationService
{
    public function __construct(
        private readonly FileSystem $fileSystem,
        private readonly mixed $translator,
        private readonly Logger $migrationLogger,
    ) {
    }

    /**
     * @return array<MigrationInterface>
     */
    public function discoverPrerequisiteMigrations(string $currentVersion): array
    {
        $registry = new MigrationRegistry($this->migrationLogger, $this->translator, $this->fileSystem);
        $globalMigrations = $registry->discoverGlobalMigrations();
        $prerequisiteMigrations = array_filter(
            $globalMigrations,
            fn (MigrationInterface $migration): bool => $migration->isPrerequisite()
        );

        return $registry->getPendingMigrations($prerequisiteMigrations, $currentVersion);
    }

    /**
     * @param array<MigrationInterface> $pendingMigrations
     * @param array<string, mixed> $config
     */
    public function executePendingMigrations(
        array $pendingMigrations,
        array $config,
        string $configPath,
        WorkflowEntryRecorder $recorder,
    ): void {
        $executor = new MigrationExecutor($recorder, $this->fileSystem, $this->translator);
        $executor->executeMigrations($pendingMigrations, $config, $configPath);
    }
}
