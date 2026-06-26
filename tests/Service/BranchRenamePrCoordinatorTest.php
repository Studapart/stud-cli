<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Service\BranchRenamePrCoordinator;
use App\Service\CanConvertToMarkdownInterface;
use App\Service\GitHostingPort;
use App\Service\GitRepository;
use App\Service\IssueTrackerPort;
use App\Service\Prompt\PromptInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class BranchRenamePrCoordinatorTest extends TestCase
{
    private GitRepository&MockObject $gitRepository;
    private GitHostingPort&MockObject $githubProvider;
    private BranchRenamePrCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);
        $this->githubProvider = $this->createMock(GitHostingPort::class);
        $this->coordinator = new BranchRenamePrCoordinator(
            $this->gitRepository,
            $this->createMock(IssueTrackerPort::class),
            $this->githubProvider,
            ['JIRA_URL' => 'https://jira.example.com'],
            'origin/develop',
            $this->createMock(\App\Service\TranslationService::class),
            $this->createMock(PromptInterface::class),
            $this->createMock(CanConvertToMarkdownInterface::class),
        );
    }

    public function testFindAssociatedPullRequestReturnsNullWhenLookupFails(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/x');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('org');
        $this->githubProvider->method('findPullRequestByBranch')->willThrowException(new \RuntimeException('api down'));

        $recorder = new WorkflowRecorder();
        $result = $this->coordinator->findAssociatedPullRequest($recorder);

        $this->assertNull($result);
        $this->assertNotEmpty($recorder->toResponse(0)->entries);
    }

    public function testCommentOnNewPullRequestLogsRecoverableFailure(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/x');
        $this->gitRepository->method('getRepositoryOwner')->willReturn('org');
        $this->githubProvider->method('findPullRequestByBranch')->willThrowException(new \RuntimeException('api down'));

        $recorder = new WorkflowRecorder();
        $this->coordinator->commentOnNewPullRequest($recorder, 'old', 'new');

        $this->assertNotEmpty($recorder->toResponse(0)->entries);
    }
}
