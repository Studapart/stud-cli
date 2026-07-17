<?php

declare(strict_types=1);

namespace App\Tests\Migrations\GlobalMigrations;

use App\Migrations\GlobalMigrations\Migration20260619102828000_ProviderLists;
use App\Migrations\MigrationScope;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class Migration20260619102828000_ProviderListsTest extends TestCase
{
    private Migration20260619102828000_ProviderLists $migration;
    private Logger&MockObject $logger;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->migration = new Migration20260619102828000_ProviderLists($this->logger, $this->translator);
    }

    public function testGetId(): void
    {
        $this->assertSame('20260619102828000', $this->migration->getId());
    }

    public function testGetScope(): void
    {
        $this->assertSame(MigrationScope::GLOBAL, $this->migration->getScope());
    }

    public function testGetDescription(): void
    {
        $this->assertSame(
            'Backfill GIT_PROVIDERS and WORK_ITEM_PROVIDERS from stored credential keys',
            $this->migration->getDescription(),
        );
    }

    public function testIsPrerequisite(): void
    {
        $this->assertFalse($this->migration->isPrerequisite());
    }

    public function testUpBackfillsWhenProviderListContainsOnlyWhitespaceEntries(): void
    {
        $config = [
            'GIT_PROVIDERS' => ['', '   '],
            'WORK_ITEM_PROVIDERS' => [null],
            'GITHUB_TOKEN' => 'gh-token',
            'JIRA_URL' => 'https://jira.example.com',
        ];

        $result = $this->migration->up($config);

        $this->assertSame(['github'], $result['GIT_PROVIDERS']);
        $this->assertSame(['jira'], $result['WORK_ITEM_PROVIDERS']);
    }

    public function testUpBackfillsGitAndWorkItemProvidersFromLegacyCredentials(): void
    {
        $config = [
            'JIRA_URL' => 'https://jira.example.com',
            'GITHUB_TOKEN' => 'gh-token',
            'GITLAB_TOKEN' => 'gl-token',
            'LINEAR_API_KEY' => 'lin-key',
        ];

        $result = $this->migration->up($config);

        $this->assertSame(['github', 'gitlab'], $result['GIT_PROVIDERS']);
        $this->assertSame(['jira', 'linear'], $result['WORK_ITEM_PROVIDERS']);
        $this->assertSame('gh-token', $result['GITHUB_TOKEN']);
        $this->assertSame('lin-key', $result['LINEAR_API_KEY']);
    }

    public function testUpInfersJiraOnlyFromJiraUrl(): void
    {
        $config = [
            'JIRA_URL' => 'https://jira.example.com',
            'JIRA_EMAIL' => 'user@example.com',
            'JIRA_API_TOKEN' => 'token',
            'GITHUB_TOKEN' => 'gh-token',
        ];

        $result = $this->migration->up($config);

        $this->assertSame(['github'], $result['GIT_PROVIDERS']);
        $this->assertSame(['jira'], $result['WORK_ITEM_PROVIDERS']);
    }

    public function testUpPreservesExistingProviderLists(): void
    {
        $config = [
            'GIT_PROVIDERS' => ['gitlab'],
            'WORK_ITEM_PROVIDERS' => ['linear'],
            'GITHUB_TOKEN' => 'gh-token',
            'JIRA_URL' => 'https://jira.example.com',
        ];

        $result = $this->migration->up($config);

        $this->assertSame(['gitlab'], $result['GIT_PROVIDERS']);
        $this->assertSame(['linear'], $result['WORK_ITEM_PROVIDERS']);
    }

    public function testUpInfersGitProviderFromLegacyTokenAndProvider(): void
    {
        $config = [
            'GIT_TOKEN' => 'legacy-token',
            'GIT_PROVIDER' => 'gitlab',
        ];

        $result = $this->migration->up($config);

        $this->assertSame(['gitlab'], $result['GIT_PROVIDERS']);
    }

    public function testDownRemovesProviderLists(): void
    {
        $config = [
            'GIT_PROVIDERS' => ['github'],
            'WORK_ITEM_PROVIDERS' => ['jira'],
            'GITHUB_TOKEN' => 'gh-token',
        ];

        $result = $this->migration->down($config);

        $this->assertArrayNotHasKey('GIT_PROVIDERS', $result);
        $this->assertArrayNotHasKey('WORK_ITEM_PROVIDERS', $result);
        $this->assertSame('gh-token', $result['GITHUB_TOKEN']);
    }
}
