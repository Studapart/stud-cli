<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\ConfigValidateHandler;
use App\Response\ConfigValidateResponse;
use App\Service\GitProviderInterface;
use App\Service\WorkItemProviderInterface;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ConfigValidateHandlerTest extends CommandTestCase
{
    private GitProviderInterface&MockObject $gitProvider;

    /**
     * @param WorkItemProviderInterface|MockObject|null|false $workItemProvider false uses setUp mock; null means not configured
     * @param GitProviderInterface|MockObject|null|false $gitProvider false uses setUp mock; null means not configured
     */
    private function createHandler(
        WorkItemProviderInterface|MockObject|null|false $workItemProvider = false,
        GitProviderInterface|MockObject|null|false $gitProvider = false,
        bool $skipJira = false,
        bool $skipGit = false,
        bool $skipLinear = false,
        bool $validateJira = true,
        bool $validateGit = true,
        bool $validateLinear = true,
    ): ConfigValidateHandler {
        return new ConfigValidateHandler(
            $workItemProvider === false ? $this->workItemProvider : $workItemProvider,
            $gitProvider === false ? $this->gitProvider : $gitProvider,
            $skipJira,
            $skipGit,
            $skipLinear,
            $validateJira,
            $validateGit,
            $validateLinear,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitProvider = $this->createMock(GitProviderInterface::class);
    }

    public function testHandleReturnsAllOkWhenBothServicesSucceed(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler();
        $response = $handler->handle();

        $this->assertInstanceOf(ConfigValidateResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->linearStatus);
        $this->assertNull($response->jiraMessage);
        $this->assertNull($response->gitMessage);
    }

    public function testHandleReturnsJiraFailWhenGetProjectsThrows(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RuntimeException('Jira API error'));
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler();
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertSame('Jira API error', $response->jiraMessage);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testHandleReturnsGitFailWhenGetLabelsThrows(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willThrowException(new \RuntimeException('Git provider error'));

        $handler = $this->createHandler();
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
        $this->assertSame('Git provider error', $response->gitMessage);
    }

    public function testHandleReturnsBothSkippedWhenSkipFlagsTrue(): void
    {
        $this->workItemProvider->expects($this->never())
            ->method('ping');
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = $this->createHandler(null, null, true, true);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->linearStatus);
    }

    public function testHandleSkipsJiraWhenValidateJiraFalse(): void
    {
        $this->workItemProvider->expects($this->never())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler(
            $this->workItemProvider,
            $this->gitProvider,
            false,
            false,
            false,
            false,
            true,
            false,
        );
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testHandleSkipsGitWhenValidateGitFalse(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = new ConfigValidateHandler(
            $this->workItemProvider,
            $this->gitProvider,
            false,
            false,
            false,
            true,
            false,
            false,
        );
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
    }

    public function testHandleSkipsLinearWhenValidateLinearTrueUntilClientExists(): void
    {
        $handler = new ConfigValidateHandler(null, null, true, true, false, false, false, true);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->linearStatus);
    }

    public function testHandleSkipsLinearWhenSkipLinearTrue(): void
    {
        $handler = new ConfigValidateHandler(null, null, true, true, true, false, false, true);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->linearStatus);
    }

    public function testHandleSkipsJiraOnlyWhenSkipJiraTrue(): void
    {
        $this->workItemProvider->expects($this->never())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler(null, $this->gitProvider, true, false);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testHandleSkipsGitOnlyWhenSkipGitTrue(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = $this->createHandler($this->workItemProvider, null, false, true);
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

        $handler = $this->createHandler(null, $this->gitProvider);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertSame('Jira not configured', $response->jiraMessage);
    }

    public function testHandleReturnsGitFailWhenGitProviderNullAndNotSkipped(): void
    {
        $this->workItemProvider->expects($this->once())
            ->method('ping');

        $handler = $this->createHandler($this->workItemProvider, null);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
        $this->assertSame('Git provider not configured', $response->gitMessage);
    }

    public function testShortReasonTruncatesLongMessages(): void
    {
        $longMessage = str_repeat('x', 150);
        $this->workItemProvider->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RuntimeException($longMessage));
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler();
        $response = $handler->handle();

        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertLessThanOrEqual(120, strlen($response->jiraMessage ?? ''));
        $this->assertStringEndsWith('...', $response->jiraMessage ?? '');
    }
}
