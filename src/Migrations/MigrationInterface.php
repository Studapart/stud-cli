<?php

declare(strict_types=1);

namespace App\Migrations;

/**
 * Interface for configuration migrations.
 * Each migration represents a single change to the configuration format.
 */
interface MigrationInterface
{
    /**
     * Returns the unique migration ID in format YYYYMMDDHHIISS001.
     */
    public function getId(): string;

    /**
     * Returns a human-readable description of what this migration does.
     */
    public function getDescription(): string;

    /**
     * Returns the scope of this migration (global or project).
     */
    public function getScope(): MigrationScope;

    /**
     * Returns whether this migration is a prerequisite that must run during stud update.
     * Prerequisite migrations that fail will prevent update completion.
     */
    public function isPrerequisite(): bool;

    /**
     * Executes the migration, transforming the config from old format to new format.
     *
     * @param array<string, mixed> $config The current configuration
     * @return array<string, mixed> The migrated configuration
     */
    public function up(array $config): array;

    /**
     * Reverts the migration, transforming the config back to old format.
     * Optional - not all migrations need to be reversible.
     *
     * @param array<string, mixed> $config The current configuration
     * @return array<string, mixed> The reverted configuration
     */
    public function down(array $config): array;
}
