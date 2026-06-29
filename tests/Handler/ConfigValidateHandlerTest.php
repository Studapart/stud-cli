<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Exception\ApiException;
use App\Handler\ConfigValidateHandler;
use App\Response\ConfigValidateResponse;
use App\Service\GitHostingPort;
use App\Service\IssueTrackerPort;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class ConfigValidateHandlerTest extends CommandTestCase
{
    private GitHostingPort&MockObject $gitProvider;

    /**
     * @param IssueTrackerPort|MockObject|null|false $workItemProvider false uses setUp mock; null means not configured
     * @param GitHostingPort|MockObject|null|false $gitProvider false uses setUp mock; null means not configured
     */
    private function createHandler(
        IssueTrackerPort|MockObject|null|false $workItemProvider = false,
        GitHostingPort|MockObject|null|false $gitProvider = false,
        bool $skipJira = false,
        bool $skipGit = false,
        bool $skipLinear = false,
        bool $validateJira = true,
        bool $validateGit = true,
        bool $validateLinear = false,
        IssueTrackerPort|MockObject|null|false $linearWorkItemProvider = false,
    ): ConfigValidateHandler {
        return new ConfigValidateHandler(
            $workItemProvider === false ? $this->issueTracker : $workItemProvider,
            $gitProvider === false ? $this->gitProvider : $gitProvider,
            $skipJira,
            $skipGit,
            $skipLinear,
            $validateJira,
            $validateGit,
            $validateLinear,
            $linearWorkItemProvider === false ? null : $linearWorkItemProvider,
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitProvider = $this->createMock(GitHostingPort::class);
    }

    public function testHandleReturnsAllOkWhenBothServicesSucceed(): void
    {
        $this->issueTracker->expects($this->once())
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
        $this->issueTracker->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RuntimeException('Jira API error'));
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler();
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertMessageRef($response->jiraMessage, 'config.validate.error_jira_ping', ['error' => 'Jira API error']);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testHandleReturnsJiraFailWhenPingThrowsApiException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('ping')
            ->willThrowException(new ApiException('Jira unavailable', 'GET /myself failed'));
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $response = $this->createHandler()->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->jiraMessage, 'config.validate.error_jira_ping', ['error' => 'Jira unavailable']);
    }

    public function testHandleReturnsGitFailWhenGetLabelsThrows(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willThrowException(new \RuntimeException('Git provider error'));

        $handler = $this->createHandler();
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
        $this->assertMessageRef($response->gitMessage, 'config.validate.error_git_ping', ['error' => 'Git provider error']);
    }

    public function testHandleReturnsGitFailWhenGetLabelsThrowsApiException(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willThrowException(new ApiException('Git unauthorized', 'GET /labels 401'));

        $response = $this->createHandler()->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->gitMessage, 'config.validate.error_git_ping', ['error' => 'Git unauthorized']);
    }

    public function testHandleReturnsBothSkippedWhenSkipFlagsTrue(): void
    {
        $this->issueTracker->expects($this->never())
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
        $this->issueTracker->expects($this->never())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler(
            $this->issueTracker,
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
        $this->issueTracker->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = new ConfigValidateHandler(
            $this->issueTracker,
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

    public function testHandleReturnsLinearFailWhenLinearProviderMissing(): void
    {
        $handler = $this->createHandler(null, null, true, true, false, false, false, true);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->linearStatus);
        $this->assertMessageRef($response->linearMessage, 'config.validate.error_linear_not_configured');
    }

    public function testHandleReturnsLinearOkWhenPingSucceeds(): void
    {
        $linearProvider = $this->createMock(IssueTrackerPort::class);
        $linearProvider->expects($this->once())->method('ping');

        $response = $this->createHandler(null, null, true, true, false, false, false, true, $linearProvider)->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->linearStatus);
    }

    public function testHandleReturnsLinearFailWhenPingThrowsIssueTrackerException(): void
    {
        $linearProvider = $this->createMock(IssueTrackerPort::class);
        $linearProvider->expects($this->once())
            ->method('ping')
            ->willThrowException(\App\Exception\IssueTrackerException::missingLinearApiKey());

        $response = $this->createHandler(null, null, true, true, false, false, false, true, $linearProvider)->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->linearStatus);
        $this->assertMessageRef($response->linearMessage, 'work_item_provider.missing_linear_api_key');
    }

    public function testHandleReturnsLinearFailWhenPingThrows(): void
    {
        $linearProvider = $this->createMock(IssueTrackerPort::class);
        $linearProvider->expects($this->once())
            ->method('ping')
            ->willThrowException(new ApiException('Linear down', 'viewer missing'));

        $response = $this->createHandler(null, null, true, true, false, false, false, true, $linearProvider)->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->linearStatus);
        $this->assertMessageRef($response->linearMessage, 'config.validate.error_linear_ping', ['error' => 'Linear down']);
    }

    public function testHandleReturnsLinearFailWhenPingThrowsRuntimeException(): void
    {
        $linearProvider = $this->createMock(IssueTrackerPort::class);
        $linearProvider->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RuntimeException('Linear runtime error'));

        $response = $this->createHandler(null, null, true, true, false, false, false, true, $linearProvider)->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->linearStatus);
        $this->assertMessageRef($response->linearMessage, 'config.validate.error_linear_ping', ['error' => 'Linear runtime error']);
    }

    public function testHandleSkipsLinearWhenValidateLinearFalse(): void
    {
        $handler = new ConfigValidateHandler(null, null, true, true, false, false, false, false);
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
        $this->issueTracker->expects($this->never())
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
        $this->issueTracker->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = $this->createHandler($this->issueTracker, null, false, true);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
    }

    public function testHandleReturnsJiraFailWhenJiraApiClientNullAndNotSkipped(): void
    {
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler(null, $this->gitProvider);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertMessageRef($response->jiraMessage, 'config.validate.error_jira_not_configured');
    }

    public function testHandleReturnsGitFailWhenGitProviderNullAndNotSkipped(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('ping');

        $handler = $this->createHandler($this->issueTracker, null);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->gitStatus);
        $this->assertMessageRef($response->gitMessage, 'config.validate.error_git_not_configured');
    }

    public function testShortReasonTruncatesLongMessages(): void
    {
        $longMessage = str_repeat('x', 150);
        $this->issueTracker->expects($this->once())
            ->method('ping')
            ->willThrowException(new \RuntimeException($longMessage));
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler();
        $response = $handler->handle();

        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $message = $this->assertMessageRef($response->jiraMessage, 'config.validate.error_jira_ping');
        $this->assertLessThanOrEqual(120, strlen((string) ($message->parameters['error'] ?? '')));
        $this->assertStringEndsWith('...', (string) ($message->parameters['error'] ?? ''));
    }
}
