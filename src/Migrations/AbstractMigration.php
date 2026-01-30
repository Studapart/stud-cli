<?php

declare(strict_types=1);

namespace App\Migrations;

use App\Service\Logger;
use App\Service\TranslationService;

/**
 * Abstract base class for migrations implementing the Template Method pattern.
 * Provides common execution flow and hook methods for subclasses.
 */
abstract class AbstractMigration implements MigrationInterface
{
    public function __construct(
        protected readonly Logger $logger,
        protected readonly TranslationService $translator
    ) {
    }

    /**
     * Template method that defines the common execution flow.
     * Subclasses implement up() and optionally down() methods.
     *
     * @param array<string, mixed> $config The current configuration
     * @return array<string, mixed> The migrated configuration
     */
    public function execute(array $config): array
    {
        $this->beforeUp($config);
        $migratedConfig = $this->up($config);
        $this->afterUp($migratedConfig);

        return $migratedConfig;
    }

    /**
     * Hook method called before up() is executed.
     * Override in subclasses if needed.
     *
     * @param array<string, mixed> $config The current configuration
     */
    protected function beforeUp(array $config): void
    {
        // Default implementation does nothing
    }

    /**
     * Hook method called after up() is executed.
     * Override in subclasses if needed.
     *
     * @param array<string, mixed> $config The migrated configuration
     */
    protected function afterUp(array $config): void
    {
        // Default implementation does nothing
    }
}
