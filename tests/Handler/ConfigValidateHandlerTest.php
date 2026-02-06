<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\ConfigValidateHandler;
use App\Response\ConfigValidateResponse;
use App\Service\GitProviderInterface;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ConfigValidateHandlerTest extends CommandTestCase
{
    private GitProviderInterface&MockObject $gitProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitProvider = $this->createMock(GitProviderInterface::class);
    }

    public function testHandleReturnsAllOkWhenBothServicesSucceed(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([]);
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler($this->jiraService, $this->gitProvider, false, false);
        $response = $handler->handle();

        $this->assertInstanceOf(ConfigValidateResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
        $this->assertNull($response->jiraMessage);
        $this->assertNull($response->gitMessage);
    }

    public function testHandleReturnsJiraFailWhenGetProjectsThrows(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willThrowException(new \RuntimeException('Jira API error'));
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler($this->jiraService, $this->gitProvider, false, false);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertSame('Jira API error', $response->jiraMessage);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testHandleReturnsGitFailWhenGetLabelsThrows(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([]);
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willThrowException(new \RuntimeException('Git provider error'));

        $handler = new ConfigValidateHandler($this->jiraService, $this->gitProvider, false, false);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
        $this->assertSame('Git provider error', $response->gitMessage);
    }

    public function testHandleReturnsBothSkippedWhenSkipFlagsTrue(): void
    {
        $this->jiraService->expects($this->never())
            ->method('getProjects');
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = new ConfigValidateHandler(null, null, true, true);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
    }

    public function testHandleSkipsJiraOnlyWhenSkipJiraTrue(): void
    {
        $this->jiraService->expects($this->never())
            ->method('getProjects');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler(null, $this->gitProvider, true, false);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testHandleSkipsGitOnlyWhenSkipGitTrue(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([]);
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = new ConfigValidateHandler($this->jiraService, null, false, true);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
    }

    public function testHandleReturnsJiraFailWhenJiraServiceNullAndNotSkipped(): void
    {
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler(null, $this->gitProvider, false, false);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertSame('Jira not configured', $response->jiraMessage);
    }

    public function testHandleReturnsGitFailWhenGitProviderNullAndNotSkipped(): void
    {
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willReturn([]);

        $handler = new ConfigValidateHandler($this->jiraService, null, false, false);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
        $this->assertSame('Git provider not configured', $response->gitMessage);
    }

    public function testShortReasonTruncatesLongMessages(): void
    {
        $longMessage = str_repeat('x', 150);
        $this->jiraService->expects($this->once())
            ->method('getProjects')
            ->willThrowException(new \RuntimeException($longMessage));
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler($this->jiraService, $this->gitProvider, false, false);
        $response = $handler->handle();

        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertLessThanOrEqual(120, strlen($response->jiraMessage ?? ''));
        $this->assertStringEndsWith('...', $response->jiraMessage ?? '');
    }
}
