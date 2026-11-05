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
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

class BranchRenamePrCoordinatorTest extends TestCase
{
    private GitRepository&Stub $gitRepository;
    private GitHostingPort&Stub $githubProvider;
    private BranchRenamePrCoordinator $coordinator;

    protected function setUp(): void
    {
        $this->gitRepository = $this->createStub(GitRepository::class);
        $this->githubProvider = $this->createStub(GitHostingPort::class);
        $this->coordinator = new BranchRenamePrCoordinator(
            $this->gitRepository,
            $this->createStub(IssueTrackerPort::class),
            $this->githubProvider,
            ['JIRA_URL' => 'https://jira.example.com'],
            'origin/develop',
            $this->createStub(\App\Service\TranslationService::class),
            $this->createStub(PromptInterface::class),
            $this->createStub(CanConvertToMarkdownInterface::class),
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
