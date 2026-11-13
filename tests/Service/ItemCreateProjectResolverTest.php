<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\ItemCreateMatch;
use App\DTO\MessageRef;
use App\DTO\Project;
use App\Exception\ApiException;
use App\Exception\IssueTrackerException;
use App\Service\GitRepository;
use App\Service\ItemCreateLinearLookup;
use App\Service\ItemCreateProjectResolver;
use App\Service\JiraApiClient;
use App\Service\LinearApiClient;
use App\Service\Logger;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class ItemCreateProjectResolverTest extends TestCase
{
    private GitRepository&Stub $gitRepository;

    private JiraApiClient $jiraApiClient;

    private PromptInterface&\PHPUnit\Framework\MockObject\Stub $prompt;

    private LinearApiClient $linearApiClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createStub(GitRepository::class);
        $this->jiraApiClient = $this->createStub(JiraApiClient::class);
        $this->prompt = $this->createStub(PromptInterface::class);
        $this->linearApiClient = $this->createStub(LinearApiClient::class);
    }

    private function createResolver(?LinearApiClient $linearApiClient = null, ?Logger $logger = null): ItemCreateProjectResolver
    {
        return new ItemCreateProjectResolver(
            $this->gitRepository,
            $this->jiraApiClient,
            $this->prompt,
            new ItemCreateLinearLookup($linearApiClient, $logger),
        );
    }

    public function testEnsureProjectExistsUsesLinearTeamKeyFromConfig(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);

        $this->gitRepository->method('readProjectConfig')->willReturn([
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ]);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('SCI')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->linearApiClient->expects($this->once())
            ->method('getTeamByKey')
            ->with('ENG')
            ->willReturn(new Project('ENG', 'Engineering'));

        $project = $this->createResolver($this->linearApiClient)->ensureProjectExists(false, 'SCI');

        $this->assertInstanceOf(ItemCreateMatch::class, $project);
        $this->assertSame('ENG', $project->key);
        $this->assertSame('linear', $project->provider);
    }

    public function testResolveLinearScopeKeyPrefersConfiguredLinearTeamKey(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn([
            'projectKey' => 'SCI',
            'linearTeamKey' => 'ENG',
        ]);

        $this->assertSame('ENG', $this->createResolver()->resolveLinearScopeKey('SCI'));
    }

    public function testResolveProjectKeyReturnsCliValueWhenProvided(): void
    {
        $this->assertSame('cli', $this->createResolver()->resolveProjectKey(false, ' cli '));
    }

    public function testResolveProjectKeyReturnsDefaultFromConfig(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn(['JIRA_DEFAULT_PROJECT' => 'DEF']);

        $this->assertSame('DEF', $this->createResolver()->resolveProjectKey(false, null));
    }

    public function testResolveLinearScopeKeyFallsBackToCliProjectKey(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $this->assertSame('CLI', $this->createResolver()->resolveLinearScopeKey('cli'));
    }

    public function testResolveLinearScopeKeyReturnsNullWhenNoKeyAvailable(): void
    {
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $this->assertNull($this->createResolver()->resolveLinearScopeKey(null));
    }

    public function testEnsureProjectExistsFallsBackToLinearTeamWhenJiraProjectMissing(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);

        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('SCI')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->linearApiClient->expects($this->once())
            ->method('getTeamByKey')
            ->with('SCI')
            ->willReturn(new Project('SCI', 'Stud CLI'));

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())
            ->method('writeln')
            ->with(
                Logger::VERBOSITY_VERBOSE,
                'Jira project "SCI" not found; trying Linear team lookup.',
            );

        $project = $this->createResolver($this->linearApiClient, $logger)->ensureProjectExists(false, 'SCI');

        $this->assertInstanceOf(ItemCreateMatch::class, $project);
        $this->assertSame('SCI', $project->key);
        $this->assertSame('linear', $project->provider);
    }

    public function testEnsureProjectExistsTagsJiraProjectWithJiraProvider(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('SCI')
            ->willReturn(new Project('SCI', 'Stud CLI'));

        $project = $this->createResolver()->ensureProjectExists(false, 'SCI');

        $this->assertSame('jira', $project?->provider);
    }

    public function testEnsureProjectExistsLooksUpARePromptedKeyInJiraThenLinear(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $this->jiraApiClient->expects($this->exactly(2))
            ->method('getProject')
            ->willReturnCallback(function (string $key): Project {
                throw new ApiException("Project \"{$key}\" not found.", 'details', 404);
            });
        $this->linearApiClient->expects($this->exactly(2))
            ->method('getTeamByKey')
            ->willReturnCallback(function (string $key): ?Project {
                return $key === 'ENG' ? new Project('ENG', 'Engineering') : null;
            });
        $this->prompt->expects($this->once())
            ->method('ask')
            ->willReturn('ENG');

        $project = $this->createResolver($this->linearApiClient)->ensureProjectExists(true, 'BAD');

        $this->assertInstanceOf(ItemCreateMatch::class, $project);
        $this->assertSame('ENG', $project->key);
        $this->assertSame('linear', $project->provider);
    }

    public function testEnsureProjectExistsUsesTheTypedKeyWhenConfigAlreadyNamesALinearTeam(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->gitRepository->method('readProjectConfig')->willReturn(['linearTeamKey' => 'ENG']);

        $this->jiraApiClient->expects($this->exactly(2))
            ->method('getProject')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->linearApiClient->expects($this->exactly(2))
            ->method('getTeamByKey')
            ->willReturnCallback(function (string $key): ?Project {
                return $key === 'OPS' ? new Project('OPS', 'Operations') : null;
            });
        $this->prompt->expects($this->once())->method('ask')->willReturn('OPS');

        $project = $this->createResolver($this->linearApiClient)->ensureProjectExists(true, 'BAD');

        $this->assertSame('OPS', $project?->key);
        $this->assertSame('linear', $project?->provider);
    }

    public function testEnsureProjectExistsReturnsNullWhenNeitherJiraNorLinearResolve(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);

        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->with('MISSING')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->linearApiClient->expects($this->once())
            ->method('getTeamByKey')
            ->with('MISSING')
            ->willReturn(null);

        $this->assertNull($this->createResolver($this->linearApiClient)->ensureProjectExists(false, 'MISSING'));
    }

    public function testEnsureProjectExistsSkipsJiraWhenItIsNotConfigured(): void
    {
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->linearApiClient->expects($this->once())
            ->method('getTeamByKey')
            ->with('OPS')
            ->willReturn(new Project('OPS', 'Operations'));

        $resolver = new ItemCreateProjectResolver(
            $this->gitRepository,
            null,
            $this->prompt,
            new ItemCreateLinearLookup($this->linearApiClient),
        );
        $this->gitRepository->method('readProjectConfig')->willReturn([]);

        $match = $resolver->ensureProjectExists(false, 'OPS');

        $this->assertInstanceOf(ItemCreateMatch::class, $match);
        $this->assertSame('OPS', $match->key);
        $this->assertSame('linear', $match->provider);
    }

    public function testEnsureProjectExistsStopsOnJiraAuthenticationFailure(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->willThrowException(new ApiException('Unauthorized', 'details', 401));
        $this->linearApiClient->expects($this->never())->method('getTeamByKey');
        $this->prompt->expects($this->never())->method('ask');

        $result = $this->createResolver($this->linearApiClient)->ensureProjectExists(true, 'SCI');

        $this->assertInstanceOf(MessageRef::class, $result);
        $this->assertSame('issue_tracker_provider.missing_jira_configuration', $result->key);
    }

    public function testEnsureProjectExistsDoesNotTryLinearWhenJiraFailsForAnotherReason(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->willThrowException(new ApiException('Unavailable', 'details', 500));
        $this->linearApiClient->expects($this->never())->method('getTeamByKey');

        $result = $this->createResolver($this->linearApiClient)->ensureProjectExists(false, 'SCI');

        $this->assertInstanceOf(MessageRef::class, $result);
        $this->assertSame('item.create.error_project_not_found', $result->key);
        $this->assertSame(['key' => 'SCI'], $result->parameters);
    }

    public function testEnsureProjectExistsReturnsTheLinearCredentialMessageWhenLinearRejectsAuth(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->linearApiClient->expects($this->once())
            ->method('getTeamByKey')
            ->willThrowException(IssueTrackerException::missingLinearApiKey());

        $result = $this->createResolver($this->linearApiClient)->ensureProjectExists(false, 'SCI');

        $this->assertInstanceOf(MessageRef::class, $result);
        $this->assertSame('issue_tracker_provider.missing_linear_api_key', $result->key);
    }

    public function testEnsureProjectExistsReturnsTheLinearCredentialMessageWhenLinearReturnsUnauthorized(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->linearApiClient->expects($this->once())
            ->method('getTeamByKey')
            ->willThrowException(new ApiException('Unauthorized', 'details', 401));

        $result = $this->createResolver($this->linearApiClient)->ensureProjectExists(false, 'SCI');

        $this->assertInstanceOf(MessageRef::class, $result);
        $this->assertSame('issue_tracker_provider.missing_linear_api_key', $result->key);
    }

    public function testEnsureProjectExistsReturnsNotFoundWhenTheLinearLookupFails(): void
    {
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
        $this->jiraApiClient->expects($this->once())
            ->method('getProject')
            ->willThrowException(new ApiException('Not found', 'details', 404));
        $this->linearApiClient->expects($this->once())
            ->method('getTeamByKey')
            ->willThrowException(new ApiException('Linear GraphQL request failed.', 'details', 500));

        $result = $this->createResolver($this->linearApiClient)->ensureProjectExists(true, 'SCI');

        $this->assertInstanceOf(MessageRef::class, $result);
        $this->assertSame('item.create.error_project_not_found', $result->key);
    }
}
