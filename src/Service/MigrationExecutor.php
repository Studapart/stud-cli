<?php

declare(strict_types=1);

namespace App\Service;

use App\Migrations\MigrationInterface;

/**
 * Service that executes migrations in order and updates the migration version.
 * Handles errors gracefully based on migration type (prerequisite vs non-prerequisite).
 */
class MigrationExecutor
{
    public function __construct(
        private readonly Logger $logger,
        private readonly FileSystem $fileSystem,
        private readonly TranslationService $translator
    ) {
    }

    /**
     * Gets a formatted error message for migration failures with fallback.
     *
     * Attempts to translate the error message. If translation fails (returns the key
     * or throws an exception), returns a fallback English message.
     *
     * @param string $migrationId The migration ID
     * @param string $errorMessage The original error message
     * @return string The formatted error message
     */
    private function getErrorMessage(string $migrationId, string $errorMessage): string
    {
        try {
            $translated = $this->translator->trans('migration.error', [
                'id' => $migrationId,
                'error' => $errorMessage,
            ]);

            // If translation returns the key itself, it means translation failed
            if ($translated === 'migration.error') {
                return "Migration {$migrationId} failed: {$errorMessage}";
            }

            return $translated;
        } catch (\Throwable $e) {
            // If translation throws an exception, use fallback
            return "Migration {$migrationId} failed: {$errorMessage}";
        }
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
                $this->logger->text(
                    Logger::VERBOSITY_NORMAL,
                    $this->translator->trans('migration.running', [
                        'id' => $migration->getId(),
                        'description' => $migration->getDescription(),
                    ])
                );

                // Execute migration (execute() is defined in AbstractMigration)
                if ($migration instanceof \App\Migrations\AbstractMigration) {
                    $migratedConfig = $migration->execute($migratedConfig);
                } else {
                    // Fallback for migrations that don't extend AbstractMigration
                    $migratedConfig = $migration->up($migratedConfig);
                }

                // Update migration version to this migration's ID
                $migratedConfig['migration_version'] = $migration->getId();

                // Save config after each migration
                $this->fileSystem->dumpFile($configPath, $migratedConfig);

                $this->logger->text(
                    Logger::VERBOSITY_NORMAL,
                    $this->translator->trans('migration.version_updated', [
                        'version' => $migration->getId(),
                    ])
                );
            } catch (\Throwable $e) {
                $errorMessage = $this->getErrorMessage($migration->getId(), $e->getMessage());

                // Handle errors based on migration type
                if ($migration->isPrerequisite()) {
                    // Prerequisite migrations must succeed
                    $this->logger->error(
                        Logger::VERBOSITY_NORMAL,
                        explode("\n", $errorMessage)
                    );

                    throw new \RuntimeException(
                        "Prerequisite migration {$migration->getId()} failed: {$e->getMessage()}",
                        0,
                        $e
                    );
                }

                // Non-prerequisite migrations: log error but continue
                $this->logger->warning(
                    Logger::VERBOSITY_NORMAL,
                    explode("\n", $errorMessage)
                );
            }
        }

        return $migratedConfig;
    }
}
