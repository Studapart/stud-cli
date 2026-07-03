<?php

declare(strict_types=1);

namespace App\Migrations\ProjectMigrations;

use App\Config\ProjectStudConfigKeys;
use App\Migrations\AbstractMigration;
use App\Migrations\MigrationScope;

/**
 * Rename workItemProvider to issueTrackerProvider in .git/stud.config (SCI-185 naming).
 */
class Migration20260703120000000_IssueTrackerConfigKeys extends AbstractMigration
{
    public function getId(): string
    {
        return '20260703120000000';
    }

    public function getDescription(): string
    {
        return 'Rename workItemProvider to issueTrackerProvider';
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
        if (isset($config[ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER])
            && ! isset($config[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER])) {
            $config[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER] = $config[ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER];
        }

        unset($config[ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER]);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    public function down(array $config): array
    {
        if (isset($config[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER])
            && ! isset($config[ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER])) {
            $config[ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER] = $config[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER];
        }

        unset($config[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER]);

        return $config;
    }
}
