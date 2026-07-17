<?php

declare(strict_types=1);

namespace App\Tests\Migrations\GlobalMigrations;

use App\Config\GlobalStudConfigKeys;
use App\Migrations\GlobalMigrations\Migration20260703120000000_IssueTrackerConfigKeys;
use App\Migrations\MigrationScope;
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
        $this->assertSame(MigrationScope::GLOBAL, $this->migration->getScope());
    }

    public function testGetDescription(): void
    {
        $this->assertSame(
            'Rename WORK_ITEM_PROVIDERS to ISSUE_TRACKER_PROVIDERS',
            $this->migration->getDescription(),
        );
    }

    public function testIsPrerequisite(): void
    {
        $this->assertFalse($this->migration->isPrerequisite());
    }

    public function testUpRenamesLegacyWorkItemProvidersKey(): void
    {
        $config = [
            GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS => ['jira', 'linear'],
        ];

        $result = $this->migration->up($config);

        $this->assertSame(['jira', 'linear'], $result[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS]);
        $this->assertArrayNotHasKey(GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS, $result);
    }

    public function testUpLeavesCanonicalKeyWhenBothPresent(): void
    {
        $config = [
            GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS => ['jira'],
            GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS => ['linear'],
        ];

        $result = $this->migration->up($config);

        $this->assertSame(['linear'], $result[GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS]);
        $this->assertArrayNotHasKey(GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS, $result);
    }

    public function testDownRestoresLegacyKey(): void
    {
        $config = [
            GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS => ['linear'],
        ];

        $result = $this->migration->down($config);

        $this->assertSame(['linear'], $result[GlobalStudConfigKeys::LEGACY_WORK_ITEM_PROVIDERS]);
        $this->assertArrayNotHasKey(GlobalStudConfigKeys::ISSUE_TRACKER_PROVIDERS, $result);
    }
}
