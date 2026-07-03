<?php

declare(strict_types=1);

namespace App\Migrations\GlobalMigrations;

use App\Config\GlobalStudConfigKeys;
use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;

/**
 * Rename WORK_ITEM_PROVIDERS to ISSUE_TRACKER_PROVIDERS (SCI-185 naming).
 */
class Migration20260703120000000_IssueTrackerConfigKeys extends AbstractMigration
{
    public function getId(): string
    {
        return '20260703120000000';
    }

    public function getDescription(): string
    {
        return 'Rename WORK_ITEM_PROVIDERS to ISSUE_TRACKER_PROVIDERS';
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
        if (isset($config[GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS])
            && ! isset($config[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS])) {
            $config[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS] = $config[GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS];
        }

        unset($config[GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS]);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function down(array $config): array
    {
        if (isset($config[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS])
            && ! isset($config[GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS])) {
            $config[GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS] = $config[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS];
        }

        unset($config[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS]);

        return $config;
    }
}
