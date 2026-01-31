<?php

declare(strict_types=1);

namespace App\Tests\Migrations\GlobalMigrations;

use App\Migrations\GlobalMigrations\Migration202501150000001_GitTokenFormat;
use App\Migrations\MigrationScope;
use App\Service\Logger;
use App\Service\TranslationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class Migration202501150000001_GitTokenFormatTest extends TestCase
{
    private Migration202501150000001_GitTokenFormat $migration;
    private Logger&MockObject $logger;
    private TranslationService&MockObject $translator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->migration = new Migration202501150000001_GitTokenFormat($this->logger, $this->translator);
    }

    public function testGetId(): void
    {
        $this->assertSame('202501150000001', $this->migration->getId());
    }

    public function testGetDescription(): void
    {
        $description = $this->migration->getDescription();
        $this->assertIsString($description);
        $this->assertNotEmpty($description);
    }

    public function testGetScope(): void
    {
        $this->assertSame(MigrationScope::GLOBAL, $this->migration->getScope());
    }

    public function testIsPrerequisite(): void
    {
        $this->assertFalse($this->migration->isPrerequisite());
    }

    public function testUpWithNoOldToken(): void
    {
        $config = ['SOME_OTHER_KEY' => 'value'];

        $result = $this->migration->up($config);

        $this->assertSame($config, $result);
    }

    public function testUpWithTokenAndProviderGithub(): void
    {
        $config = [
            'GIT_TOKEN' => 'old_token',
            'GIT_PROVIDER' => 'github',
        ];

        $result = $this->migration->up($config);

        $this->assertSame('old_token', $result['GITHUB_TOKEN']);
        $this->assertArrayNotHasKey('GIT_TOKEN', $result);
        $this->assertArrayNotHasKey('GIT_PROVIDER', $result);
    }

    public function testUpWithTokenAndProviderGitlab(): void
    {
        $config = [
            'GIT_TOKEN' => 'old_token',
            'GIT_PROVIDER' => 'gitlab',
        ];

        $result = $this->migration->up($config);

        $this->assertSame('old_token', $result['GITLAB_TOKEN']);
        $this->assertArrayNotHasKey('GIT_TOKEN', $result);
        $this->assertArrayNotHasKey('GIT_PROVIDER', $result);
    }

    public function testUpWithTokenAndProviderButNewTokenExists(): void
    {
        $config = [
            'GIT_TOKEN' => 'old_token',
            'GIT_PROVIDER' => 'github',
            'GITHUB_TOKEN' => 'existing_token',
        ];

        $result = $this->migration->up($config);

        $this->assertSame('existing_token', $result['GITHUB_TOKEN']);
        $this->assertArrayNotHasKey('GIT_TOKEN', $result);
        $this->assertArrayNotHasKey('GIT_PROVIDER', $result);
    }

    public function testUpWithTokenAndProviderButNewTokenExistsEmpty(): void
    {
        $config = [
            'GIT_TOKEN' => 'old_token',
            'GIT_PROVIDER' => 'github',
            'GITHUB_TOKEN' => '   ', // Empty whitespace
        ];

        $result = $this->migration->up($config);

        // Should migrate because existing token is empty
        $this->assertSame('old_token', $result['GITHUB_TOKEN']);
        $this->assertArrayNotHasKey('GIT_TOKEN', $result);
        $this->assertArrayNotHasKey('GIT_PROVIDER', $result);
    }

    public function testUpWithTokenButNoProvider(): void
    {
        $config = [
            'GIT_TOKEN' => 'old_token',
        ];

        $result = $this->migration->up($config);

        // Should preserve old token when provider is missing
        $this->assertSame('old_token', $result['GIT_TOKEN']);
    }

    public function testUpWithEmptyToken(): void
    {
        $config = [
            'GIT_TOKEN' => '   ',
            'GIT_PROVIDER' => 'github',
        ];

        $result = $this->migration->up($config);

        $this->assertSame($config, $result);
    }

    public function testDownWithGithubToken(): void
    {
        $config = [
            'GITHUB_TOKEN' => 'github_token',
        ];

        $result = $this->migration->down($config);

        $this->assertSame('github_token', $result['GIT_TOKEN']);
        $this->assertSame('github', $result['GIT_PROVIDER']);
        $this->assertArrayNotHasKey('GITHUB_TOKEN', $result);
    }

    public function testDownWithGitlabToken(): void
    {
        $config = [
            'GITLAB_TOKEN' => 'gitlab_token',
        ];

        $result = $this->migration->down($config);

        $this->assertSame('gitlab_token', $result['GIT_TOKEN']);
        $this->assertSame('gitlab', $result['GIT_PROVIDER']);
        $this->assertArrayNotHasKey('GITLAB_TOKEN', $result);
    }

    public function testDownWithExistingGitToken(): void
    {
        $config = [
            'GIT_TOKEN' => 'existing_token',
            'GITHUB_TOKEN' => 'github_token',
        ];

        $result = $this->migration->down($config);

        // Should preserve existing GIT_TOKEN and not revert
        $this->assertSame('existing_token', $result['GIT_TOKEN']);
        $this->assertArrayNotHasKey('GIT_PROVIDER', $result);
        $this->assertArrayHasKey('GITHUB_TOKEN', $result);
    }

    public function testDownWithNoTokens(): void
    {
        $config = ['SOME_OTHER_KEY' => 'value'];

        $result = $this->migration->down($config);

        $this->assertSame($config, $result);
    }
}
