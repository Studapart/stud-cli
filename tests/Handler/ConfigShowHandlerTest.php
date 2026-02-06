<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Config\SecretKeyPolicy;
use App\Handler\ConfigShowHandler;
use App\Response\ConfigShowResponse;
use App\Service\FileSystem;
use App\Tests\CommandTestCase;

class ConfigShowHandlerTest extends CommandTestCase
{
    private FileSystem $fileSystem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = $this->createMock(FileSystem::class);
    }

    public function testHandleReturnsErrorWhenGlobalConfigDoesNotExist(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/path/to/config.yml')
            ->willReturn(false);

        $handler = new ConfigShowHandler($this->fileSystem, '/path/to/config.yml', null);
        $response = $handler->handle();

        $this->assertInstanceOf(ConfigShowResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertSame('config.show.no_config_found', $response->getError());
        $this->assertEmpty($response->globalConfig);
        $this->assertNull($response->projectConfig);
    }

    public function testHandleReturnsSuccessWithRedactedGlobalConfigWhenNotInRepo(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->with('/path/to/config.yml')
            ->willReturn(true);
        $this->fileSystem->expects($this->once())
            ->method('parseFile')
            ->with('/path/to/config.yml')
            ->willReturn([
                'LANGUAGE' => 'en',
                'JIRA_URL' => 'https://jira.example.com',
                'JIRA_API_TOKEN' => 'secret-token',
            ]);

        $handler = new ConfigShowHandler($this->fileSystem, '/path/to/config.yml', null);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->projectConfig);
        $this->assertSame('en', $response->globalConfig['LANGUAGE']);
        $this->assertSame('https://jira.example.com', $response->globalConfig['JIRA_URL']);
        $this->assertSame(SecretKeyPolicy::REDACTED_PLACEHOLDER, $response->globalConfig['JIRA_API_TOKEN']);
    }

    public function testHandleReturnsSuccessWithProjectConfigWhenInRepo(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);
        $this->fileSystem->expects($this->once())
            ->method('parseFile')
            ->with('/path/to/config.yml')
            ->willReturn(['LANGUAGE' => 'en']);

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn(['projectKey' => 'PROJ', 'githubToken' => 'gh-secret']);

        $handler = new ConfigShowHandler($this->fileSystem, '/path/to/config.yml', $this->gitRepository);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame('en', $response->globalConfig['LANGUAGE']);
        $this->assertNotNull($response->projectConfig);
        $this->assertSame('PROJ', $response->projectConfig['projectKey']);
        $this->assertSame(SecretKeyPolicy::REDACTED_PLACEHOLDER, $response->projectConfig['githubToken']);
    }

    public function testHandleReturnsNullProjectConfigWhenRepoThrows(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);
        $this->fileSystem->expects($this->once())
            ->method('parseFile')
            ->willReturn(['LANGUAGE' => 'en']);

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willThrowException(new \RuntimeException('Not in a git repository.'));

        $handler = new ConfigShowHandler($this->fileSystem, '/path/to/config.yml', $this->gitRepository);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->projectConfig);
    }

    public function testHandleTreatsEmptyProjectConfigAsNull(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);
        $this->fileSystem->expects($this->once())
            ->method('parseFile')
            ->willReturn(['LANGUAGE' => 'en']);

        $this->gitRepository->expects($this->once())
            ->method('readProjectConfig')
            ->willReturn([]);

        $handler = new ConfigShowHandler($this->fileSystem, '/path/to/config.yml', $this->gitRepository);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->projectConfig);
    }

    public function testHandleReturnsEmptyGlobalConfigWhenParseFileThrows(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('fileExists')
            ->willReturn(true);
        $this->fileSystem->expects($this->once())
            ->method('parseFile')
            ->willThrowException(new \RuntimeException('Parse error'));

        $handler = new ConfigShowHandler($this->fileSystem, '/path/to/config.yml', null);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertEmpty($response->globalConfig);
    }
}
