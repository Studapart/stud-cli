<?php

declare(strict_types=1);

namespace App\Service;

use App\Migrations\MigrationInterface;
use App\Migrations\MigrationScope;

/**
 * Service that discovers and manages available migrations.
 * Scans migration directories and filters migrations based on current version.
 */
class MigrationRegistry
{
    private const GLOBAL_MIGRATIONS_PATH = __DIR__ . '/../Migrations/GlobalMigrations';
    private const PROJECT_MIGRATIONS_PATH = __DIR__ . '/../Migrations/ProjectMigrations';

    public function __construct(
        private readonly Logger $logger,
        private readonly TranslationService $translator,
        private readonly ?FileSystem $fileSystem = null
    ) {
    }

    private function getFileSystem(): FileSystem
    {
        return $this->fileSystem ?? FileSystem::createLocal();
    }

    /**
     * Discovers all global migrations from the GlobalMigrations directory.
     *
     * @return array<MigrationInterface> Array of migration instances, sorted by ID
     */
    public function discoverGlobalMigrations(): array
    {
        return $this->discoverMigrations(self::GLOBAL_MIGRATIONS_PATH, MigrationScope::GLOBAL);
    }

    /**
     * Discovers all project migrations from the ProjectMigrations directory.
     *
     * @return array<MigrationInterface> Array of migration instances, sorted by ID
     */
    public function discoverProjectMigrations(): array
    {
        return $this->discoverMigrations(self::PROJECT_MIGRATIONS_PATH, MigrationScope::PROJECT);
    }

    /**
     * Gets pending migrations that haven't been executed yet.
     * Compares migration IDs with the current version to determine which migrations are pending.
     *
     * @param array<MigrationInterface> $availableMigrations All available migrations
     * @param string $currentVersion The current migration version (e.g., "202501150000001" or "0" for no migrations)
     * @return array<MigrationInterface> Array of pending migrations, sorted by ID
     */
    public function getPendingMigrations(array $availableMigrations, string $currentVersion): array
    {
        if ($currentVersion === '0' || $currentVersion === '') {
            // No migrations have run yet, return all migrations
            return $this->sortMigrations($availableMigrations);
        }

        $pending = [];
        foreach ($availableMigrations as $migration) {
            if ($this->compareMigrationId($migration->getId(), $currentVersion) > 0) {
                $pending[] = $migration;
            }
        }

        return $this->sortMigrations($pending);
    }

    /**
     * Discovers migrations from a directory.
     *
     * @param string $directoryPath The directory to scan
     * @param MigrationScope $expectedScope The expected scope for migrations in this directory
     * @return array<MigrationInterface> Array of migration instances, sorted by ID
     */
    protected function discoverMigrations(string $directoryPath, MigrationScope $expectedScope): array
    {
        $migrations = [];
        $fileSystem = $this->getFileSystem();

        // Resolve absolute paths to relative paths if they're within the current working directory
        $resolvedPath = $this->resolvePath($directoryPath);

        if (! $fileSystem->isDir($resolvedPath)) {
            // Directory doesn't exist yet (e.g., ProjectMigrations might not exist)
            return [];
        }

        $files = $fileSystem->listDirectory($resolvedPath);
        if (empty($files)) {
            return [];
        }

        foreach ($files as $filePath) {
            // Extract just the filename from the full path
            $file = basename($filePath);

            if (! str_ends_with($file, '.php')) {
                continue;
            }

            $className = $this->getClassNameFromFile($resolvedPath, $file);
            if ($className === null) {
                continue;
            }

            try {
                if (! class_exists($className)) {
                    continue;
                }

                $reflection = new \ReflectionClass($className);
                if (! $reflection->implementsInterface(MigrationInterface::class)) {
                    continue;
                }

                // Instantiate migration with required dependencies
                /** @var MigrationInterface $migration */
                $migration = new $className($this->logger, $this->translator);

                // Verify scope matches expected scope
                if ($migration->getScope() !== $expectedScope) {
                    continue;
                }

                $migrations[] = $migration;
            } catch (\Throwable $e) {
                // Skip migrations that can't be instantiated
                continue;
            }
        }

        return $this->sortMigrations($migrations);
    }

    /**
     * Extracts the fully qualified class name from a PHP file.
     * This is a simple parser that looks for namespace and class declarations.
     *
     * @param string $directoryPath The directory containing the file
     * @param string $fileName The PHP file name
     * @return string|null The fully qualified class name, or null if not found
     */
    /**
     * Resolves an absolute path to a relative path if it's within the current working directory.
     * This is needed because FileSystem::createLocal() uses getcwd() as the root.
     *
     * @param string $path The path to resolve
     * @return string The resolved path (relative to cwd if absolute, or original if already relative)
     */
    protected function resolvePath(string $path): string
    {
        $cwd = getcwd();
        if ($cwd === false) {
            return $path;
        }

        // If path is absolute and starts with cwd, make it relative
        if (str_starts_with($path, '/') && str_starts_with($path, $cwd)) {
            $relative = ltrim(str_replace($cwd, '', $path), '/');

            return $relative !== '' ? $relative : '.';
        }

        return $path;
    }

    protected function getClassNameFromFile(string $directoryPath, string $fileName): ?string
    {
        $filePath = $directoryPath . '/' . $fileName;
        $fileSystem = $this->getFileSystem();

        try {
            $content = $fileSystem->read($filePath);
        } catch (\RuntimeException $e) {
            return null;
        }

        // Extract namespace
        $namespace = null;
        if (preg_match('/namespace\s+([^;]+);/', $content, $matches)) {
            $namespace = trim($matches[1]);
        }

        // Extract class name
        $className = null;
        if (preg_match('/class\s+(\w+)/', $content, $matches)) {
            $className = $matches[1];
        }

        if ($className === null) {
            return null;
        }

        if ($namespace !== null) {
            return $namespace . '\\' . $className;
        }

        return $className;
    }

    /**
     * Sorts migrations by their ID in ascending order.
     *
     * @param array<MigrationInterface> $migrations
     * @return array<MigrationInterface>
     */
    protected function sortMigrations(array $migrations): array
    {
        usort($migrations, function (MigrationInterface $a, MigrationInterface $b): int {
            return $this->compareMigrationId($a->getId(), $b->getId());
        });

        return $migrations;
    }

    /**
     * Compares two migration IDs.
     * Returns -1 if $id1 < $id2, 0 if equal, 1 if $id1 > $id2.
     *
     * @param string $id1 First migration ID
     * @param string $id2 Second migration ID
     * @return int Comparison result
     */
    protected function compareMigrationId(string $id1, string $id2): int
    {
        // Migration IDs are in format YYYYMMDDHHIISS001 (numeric strings)
        // Simple string comparison works because they're zero-padded
        return strcmp($id1, $id2);
    }
}
