<?php

declare(strict_types=1);

namespace App\Tests\Migrations\ProjectMigrations;

use App\Config\ProjectStudConfigKeys;
use App\Migrations\MigrationScope;
use App\Migrations\ProjectMigrations\Migration20260703120000000_IssueTrackerConfigKeys;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\TestCase;

class Migration20260703120000000_IssueTrackerConfigKeysTest extends TestCase
{
    private Migration20260703120000000_IssueTrackerConfigKeys $migration;

    protected function setUp(): void
    {
        parent::setUp();

        $logger = $this->createMock(Logger::class);
        $translator = $this->createMock(TranslationService::class);
        $this->migration = new Migration20260703120000000_IssueTrackerConfigKeys($logger, $translator);
    }

    public function testGetIdAndScope(): void
    {
        $this->assertSame('20260703120000000', $this->migration->getId());
        $this->assertSame(MigrationScope::PROJECT, $this->migration->getScope());
    }

    public function testGetDescription(): void
    {
        $this->assertSame(
            'Rename workItemProvider to issueTrackerProvider',
            $this->migration->getDescription(),
        );
    }

    public function testIsPrerequisite(): void
    {
        $this->assertFalse($this->migration->isPrerequisite());
    }

    public function testUpRenamesLegacyWorkItemProviderKey(): void
    {
        $config = [
            ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER => 'linear',
        ];

        $result = $this->migration->up($config);

        $this->assertSame('linear', $result[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER]);
        $this->assertArrayNotHasKey(ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER, $result);
    }

    public function testUpLeavesCanonicalKeyWhenBothPresent(): void
    {
        $config = [
            ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER => 'jira',
            ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER => 'linear',
        ];

        $result = $this->migration->up($config);

        $this->assertSame('linear', $result[ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER]);
        $this->assertArrayNotHasKey(ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER, $result);
    }

    public function testDownRestoresLegacyKey(): void
    {
        $config = [
            ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER => 'auto',
        ];

        $result = $this->migration->down($config);

        $this->assertSame('auto', $result[ProjectStudConfigKeys::LEGACY_ISSUE_TRACKER_PROVIDER]);
        $this->assertArrayNotHasKey(ProjectStudConfigKeys::ISSUE_TRACKER_PROVIDER, $result);
    }
}
