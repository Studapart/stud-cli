<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\Project;
use App\Exception\ApiException;
use App\Service\GitRepository;
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
            $linearApiClient,
            $logger,
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

        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame('ENG', $project->key);
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

        $this->assertInstanceOf(Project::class, $project);
        $this->assertSame('SCI', $project->key);
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
}
