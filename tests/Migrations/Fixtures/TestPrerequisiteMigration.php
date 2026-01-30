<?php

declare(strict_types=1);

namespace App\Tests\Migrations\Fixtures;

use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;

/**
 * Test prerequisite migration for testing purposes.
 */
class TestPrerequisiteMigration extends AbstractMigration
{
    public function getId(): string
    {
        return '202501160000003';
    }

    public function getDescription(): string
    {
        return 'Test prerequisite migration';
    }

    public function getScope(): MigrationScope
    {
        return MigrationScope::GLOBAL;
    }

    public function isPrerequisite(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function up(array $config): array
    {
        $config['test_prerequisite_migrated'] = true;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function down(array $config): array
    {
        unset($config['test_prerequisite_migrated']);

        return $config;
    }
}
