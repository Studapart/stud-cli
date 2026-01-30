<?php

declare(strict_types=1);

namespace App\Tests\Migrations\Fixtures;

use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;

/**
 * Test migration for testing purposes.
 */
class TestGlobalMigration extends AbstractMigration
{
    public function getId(): string
    {
        return '202501160000001';
    }

    public function getDescription(): string
    {
        return 'Test global migration';
    }

    public function getScope(): MigrationScope
    {
        return MigrationScope::GLOBAL;
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
        $config['test_migrated'] = true;

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function down(array $config): array
    {
        unset($config['test_migrated']);

        return $config;
    }
}
