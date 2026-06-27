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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ItemCreateProjectResolverTest extends TestCase
{
    private GitRepository&MockObject $gitRepository;

    private JiraApiClient&MockObject $jiraApiClient;

    private PromptInterface&MockObject $prompt;

    private LinearApiClient&MockObject $linearApiClient;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->jiraApiClient = $this->createMock(JiraApiClient::class);
        $this->prompt = $this->createMock(PromptInterface::class);
        $this->linearApiClient = $this->createMock(LinearApiClient::class);
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

    public function testEnsureProjectExistsFallsBackToLinearTeamWhenJiraProjectMissing(): void
    {
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
