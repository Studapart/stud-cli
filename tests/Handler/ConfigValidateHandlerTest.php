<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\ConfigValidatePingPorts;
use App\DTO\ConfigValidatePlan;
use App\DTO\MessageRef;
use App\Enum\GitProvider;
use App\Enum\IssueTrackerProvider;
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
     * @param IssueTrackerPort|MockObject|null|false $jiraIssueTracker false uses setUp mock; null means not configured
     * @param GitHostingPort|MockObject|null|false   $projectGitHosting false uses setUp mock; null means not configured
     */
    private function createHandler(
        IssueTrackerPort|MockObject|null|false $jiraIssueTracker = false,
        GitHostingPort|MockObject|null|false $projectGitHosting = false,
        bool $skipJira = false,
        bool $skipGit = false,
        bool $skipLinear = false,
        bool $validateJira = true,
        bool $validateGit = true,
        bool $validateLinear = false,
        IssueTrackerPort|MockObject|null|false $linearIssueTracker = false,
        bool $validateGithubGlobally = true,
        bool $validateGitlabGlobally = false,
        ?string $selectedTracker = 'jira',
        bool $inRepo = true,
        bool $hasProjectConfig = true,
        ?string $projectGitProvider = 'github',
        GitHostingPort|MockObject|null $githubSmokeHosting = null,
        GitHostingPort|MockObject|null $gitlabSmokeHosting = null,
        bool $selectionBlocked = false,
        ?MessageRef $linearSetupError = null,
    ): ConfigValidateHandler {
        return new ConfigValidateHandler(
            new ConfigValidatePingPorts(
                $jiraIssueTracker === false ? $this->issueTracker : $jiraIssueTracker,
                $linearIssueTracker === false ? null : $linearIssueTracker,
                $projectGitHosting === false ? $this->gitProvider : $projectGitHosting,
                $githubSmokeHosting,
                $gitlabSmokeHosting,
                linearSetupError: $linearSetupError,
            ),
            $this->plan(
                $skipJira,
                $skipGit,
                $skipLinear,
                $validateJira,
                $validateGit,
                $validateLinear,
                $validateGithubGlobally,
                $validateGitlabGlobally,
                $selectedTracker,
                $inRepo,
                $hasProjectConfig,
                $projectGitProvider,
                $selectionBlocked,
            ),
        );
    }

    private function plan(
        bool $skipJira,
        bool $skipGit,
        bool $skipLinear,
        bool $validateJira,
        bool $validateGit,
        bool $validateLinear,
        bool $validateGithubGlobally,
        bool $validateGitlabGlobally,
        ?string $selectedTracker,
        bool $inRepo,
        bool $hasProjectConfig,
        ?string $projectGitProvider,
        bool $selectionBlocked,
    ): ConfigValidatePlan {
        $selected = $selectionBlocked || ! $hasProjectConfig
            ? null
            : IssueTrackerProvider::tryFrom((string) $selectedTracker);

        return new ConfigValidatePlan(
            $inRepo,
            $hasProjectConfig,
            $validateJira && ! $skipJira,
            $validateLinear && ! $skipLinear,
            $validateGit && ! $skipGit,
            $validateGithubGlobally,
            $validateGitlabGlobally,
            $selected,
            $selectionBlocked ? MessageRef::key('guard.error.ambiguous_issue_tracker_provider') : null,
            GitProvider::tryFromProjectValue($projectGitProvider),
            $skipJira,
            $skipLinear,
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

    public function testHandleFailsProjectWhenSelectedJiraNotGloballyConfigured(): void
    {
        $this->issueTracker->expects($this->never())
            ->method('ping');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = $this->createHandler(
            jiraIssueTracker: $this->issueTracker,
            validateJira: false,
        );
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->gitStatus);
    }

    public function testHandleSkipsGitWhenValidateGitFalse(): void
    {
        $this->issueTracker->expects($this->once())
            ->method('ping');
        $this->gitProvider->expects($this->never())
            ->method('getLabels');

        $handler = $this->createHandler(validateGit: false);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->jiraStatus);
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->gitStatus);
    }

    public function testHandleReturnsLinearFailWhenLinearProviderMissing(): void
    {
        $handler = $this->createHandler(
            null,
            null,
            true,
            true,
            false,
            false,
            false,
            true,
            selectedTracker: 'linear',
        );
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

        $response = $this->createHandler(
            null,
            null,
            true,
            true,
            false,
            false,
            false,
            true,
            $linearProvider,
            selectedTracker: 'linear',
        )->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->linearStatus);
    }

    public function testHandleReturnsLinearFailWhenPingThrowsIssueTrackerException(): void
    {
        $linearProvider = $this->createMock(IssueTrackerPort::class);
        $linearProvider->expects($this->once())
            ->method('ping')
            ->willThrowException(\App\Exception\IssueTrackerException::missingLinearApiKey());

        $response = $this->createHandler(
            null,
            null,
            true,
            true,
            false,
            false,
            false,
            true,
            $linearProvider,
            selectedTracker: 'linear',
        )->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->linearStatus);
        $this->assertMessageRef($response->linearMessage, 'issue_tracker_provider.missing_linear_api_key');
    }

    public function testHandleReturnsLinearFailWhenPingThrows(): void
    {
        $linearProvider = $this->createMock(IssueTrackerPort::class);
        $linearProvider->expects($this->once())
            ->method('ping')
            ->willThrowException(new ApiException('Linear down', 'viewer missing'));

        $response = $this->createHandler(
            null,
            null,
            true,
            true,
            false,
            false,
            false,
            true,
            $linearProvider,
            selectedTracker: 'linear',
        )->handle();

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

        $response = $this->createHandler(
            null,
            null,
            true,
            true,
            false,
            false,
            false,
            true,
            $linearProvider,
            selectedTracker: 'linear',
        )->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->linearStatus);
        $this->assertMessageRef($response->linearMessage, 'config.validate.error_linear_ping', ['error' => 'Linear runtime error']);
    }

    public function testHandleSkipsLinearWhenValidateLinearFalse(): void
    {
        $handler = $this->createHandler(null, null, true, true, false, false, false, false);
        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->linearStatus);
    }

    public function testHandleSkipsLinearWhenSkipLinearTrue(): void
    {
        $handler = $this->createHandler(null, null, true, true, true, false, false, true);
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

    public function testHandleAttachesProjectSelectionNotice(): void
    {
        $handler = $this->createHandler(selectionBlocked: true);
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $notices = $response->getNotices();
        $this->assertCount(1, $notices);
        $this->assertMessageRef($notices[0]->message, 'guard.error.ambiguous_issue_tracker_provider');
    }

    public function testHandleReturnsLinearSetupErrorWhenPortSetupFailed(): void
    {
        $handler = $this->createHandler(
            jiraIssueTracker: null,
            linearIssueTracker: null,
            validateJira: false,
            validateLinear: true,
            selectedTracker: 'linear',
            linearSetupError: MessageRef::key('issue_tracker_provider.missing_linear_api_key'),
        );
        $response = $handler->handle();

        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->linearStatus);
        $this->assertMessageRef($response->linearMessage, 'issue_tracker_provider.missing_linear_api_key');
    }

    public function testHandleSkipsGitlabWhenGitProbeIsSkipped(): void
    {
        $handler = $this->createHandler(skipGit: true, validateGitlabGlobally: true);
        $response = $handler->handle();

        $this->assertSame(ConfigValidateResponse::STATUS_SKIPPED, $response->integrations['gitlab']->status);
    }

    public function testHandleReturnsJiraSetupErrorWhenPortSetupFailed(): void
    {
        $setupError = MessageRef::key('issue_tracker_provider.missing_jira_configuration');
        $this->gitProvider->expects($this->once())
            ->method('getLabels')
            ->willReturn([]);

        $handler = new ConfigValidateHandler(
            new ConfigValidatePingPorts(
                null,
                null,
                $this->gitProvider,
                null,
                null,
                $setupError,
            ),
            $this->plan(false, false, false, true, true, false, true, false, 'jira', true, true, 'github', false),
        );
        $response = $handler->handle();

        $this->assertFalse($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_FAIL, $response->jiraStatus);
        $this->assertMessageRef($response->jiraMessage, 'issue_tracker_provider.missing_jira_configuration');
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

    public function testHandleSmokeChecksNonProjectGitHostWithGetAuthenticatedUser(): void
    {
        $githubSmoke = $this->createMock(GitHostingPort::class);
        $githubSmoke->expects($this->once())->method('getAuthenticatedUser');

        $gitlabProject = $this->createMock(GitHostingPort::class);
        $gitlabProject->expects($this->once())->method('getLabels')->willReturn([]);

        $this->issueTracker->expects($this->once())->method('ping');

        $handler = $this->createHandler(
            projectGitHosting: $gitlabProject,
            skipJira: false,
            skipGit: false,
            skipLinear: false,
            validateLinear: true,
            validateGithubGlobally: true,
            validateGitlabGlobally: true,
            projectGitProvider: 'gitlab',
            githubSmokeHosting: $githubSmoke,
        );

        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->integrations['github']->status);
        $this->assertSame(ConfigValidateResponse::STATUS_OK, $response->integrations['gitlab']->status);
    }

    public function testHandleSuccessWhenGlobalPartialButProjectOk(): void
    {
        $linearProvider = $this->createMock(IssueTrackerPort::class);
        $linearProvider->expects($this->once())->method('ping')
            ->willThrowException(new ApiException('Linear down', 'x'));

        $this->issueTracker->expects($this->once())->method('ping');
        $this->gitProvider->expects($this->once())->method('getLabels')->willReturn([]);

        $handler = $this->createHandler(
            linearIssueTracker: $linearProvider,
            skipJira: false,
            skipGit: false,
            skipLinear: false,
            validateJira: true,
            validateGit: true,
            validateLinear: true,
            validateGithubGlobally: true,
            validateGitlabGlobally: true,
            selectedTracker: 'jira',
        );

        $response = $handler->handle();

        $this->assertTrue($response->isSuccess());
        $this->assertSame('partial', $response->globalRollup);
        $this->assertSame('ok', $response->projectRollup);
    }
}
