<?php

declare(strict_types=1);

namespace App\Service;

use App\DTO\MessageRef;
use App\Migrations\MigrationInterface;

/**
 * Service that executes migrations in order and updates the migration version.
 * Handles errors gracefully based on migration type (prerequisite vs non-prerequisite).
 */
class MigrationExecutor
{
    public function __construct(
        private readonly WorkflowOutput $logger,
        private readonly FileSystem $fileSystem,
        mixed $translator
    ) {
        unset($translator);
    }

    /**
     * Gets a formatted error message for migration failures with fallback.
     *
     * Attempts to translate the error message. If translation fails (returns the key
     * or throws an exception), returns a fallback English message.
     *
     * @param string $migrationId The migration ID
     * @param string $errorMessage The original error message
     */
    private function getErrorMessage(string $migrationId, string $errorMessage): MessageRef
    {
        return MessageRef::key(
            'migration.error',
            ['id' => $migrationId, 'error' => $errorMessage],
            "Migration {$migrationId} failed: {$errorMessage}",
        );
    }

    /**
     * Executes a list of migrations in order and updates the config file.
     * Saves the config after each successful migration and updates the migration_version.
     *
     * @param array<MigrationInterface> $migrations The migrations to execute
     * @param array<string, mixed> $config The current configuration
     * @param string $configPath The path to the config file
     * @return array<string, mixed> The migrated configuration
     * @throws \RuntimeException If a prerequisite migration fails
     */
    public function executeMigrations(array $migrations, array $config, string $configPath): array
    {
        $migratedConfig = $config;

        foreach ($migrations as $migration) {
            try {
                $migratedConfig = $this->runSingleMigration($migration, $migratedConfig);
                $this->fileSystem->dumpFile($configPath, $migratedConfig);
            } catch (\Throwable $e) {
                $this->handleMigrationFailure($migration, $e);
            }
        }

        return $migratedConfig;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function runSingleMigration(MigrationInterface $migration, array $config): array
    {
        $this->logger->addText(
            WorkflowOutput::VERBOSITY_NORMAL,
            MessageRef::key('migration.running', [
                'id' => $migration->getId(),
                'description' => $migration->getDescription(),
            ])
        );

        if ($migration instanceof \App\Migrations\AbstractMigration) {
            $config = $migration->execute($config);
        } else {
            $config = $migration->up($config);
        }

        $config['migration_version'] = $migration->getId();

        $this->logger->addText(
            WorkflowOutput::VERBOSITY_NORMAL,
            MessageRef::key('migration.version_updated', [
                'version' => $migration->getId(),
            ])
        );

        return $config;
    }

    /**
     * @throws \RuntimeException If the migration is a prerequisite
     */
    private function handleMigrationFailure(MigrationInterface $migration, \Throwable $e): void
    {
        $errorMessage = $this->getErrorMessage($migration->getId(), $e->getMessage());

        if ($migration->isPrerequisite()) {
            $this->logger->addError(
                WorkflowOutput::VERBOSITY_NORMAL,
                $errorMessage
            );

            throw new \RuntimeException(
                "Prerequisite migration {$migration->getId()} failed: {$e->getMessage()}",
                0,
                $e
            );
        }

        $this->logger->addWarning(
            WorkflowOutput::VERBOSITY_NORMAL,
            $errorMessage
        );
    }
}
