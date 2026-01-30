<?php

declare(strict_types=1);

namespace App\Tests\Migrations\Fixtures;

use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;

/**
 * Test project migration for testing purposes.
 */
class TestProjectMigration extends AbstractMigration
{
    public function getId(): string
    {
        return '202501160000002';
    }

    public function getDescription(): string
    {
        return 'Test project migration';
    }

    public function getScope(): MigrationScope
    {
        return MigrationScope::PROJECT;
    }

    public function isPrerequisite(): bool
    {
        return false;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function up(array $config): array
    {
        $config['test_project_migrated'] = true;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function down(array $config): array
    {
        unset($config['test_project_migrated']);

        return $config;
    }
}
