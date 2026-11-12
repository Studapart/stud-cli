<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Handler\SubmitHandler;
use App\Service\BranchRenamePrCoordinator;
use App\Service\GitHostingPort;
use App\Service\GitRepository;
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
            $this->githubProvider,
            $this->createStub(SubmitHandler::class),
            $this->createStub(PromptInterface::class),
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

    public function testCreatePullRequestAfterRenameKeepsSubmitOutputInTheSequence(): void
    {
        $submitResponse = $this->submitResponse(0, 'submit created the pull request');
        $recorder = new WorkflowRecorder();

        $this->coordinatorWithSubmitResponse($submitResponse)
            ->createPullRequestAfterRename($recorder, ['number' => 7], 'old', 'new');

        $response = $recorder->toResponse(0);
        $this->assertTrue(in_array($submitResponse->entries[0], $response->entries, true));
        $this->assertSame(41, $response->pullNumber);
    }

    public function testCreatePullRequestAfterRenameKeepsFailedSubmitOutputBeforeTheWarning(): void
    {
        $submitResponse = $this->submitResponse(1, 'submit could not create the pull request');
        $recorder = new WorkflowRecorder();

        $this->coordinatorWithSubmitResponse($submitResponse)
            ->createPullRequestAfterRename($recorder, ['number' => 7], 'old', 'new');

        $entries = $recorder->toResponse(0)->entries;
        $submitIndex = array_search($submitResponse->entries[0], $entries, true);
        $this->assertIsInt($submitIndex);
        $warning = $entries[$submitIndex + 1];
        $this->assertSame('warning', $warning->type);
        $this->assertInstanceOf(\App\DTO\MessageRef::class, $warning->message);
        $this->assertSame('branch.rename.pr_creation_failed', $warning->message->key);
    }

    private function submitResponse(int $exitCode, string $text): \App\Response\WorkflowResponse
    {
        $submitRecorder = new WorkflowRecorder();
        $submitRecorder->addText(\App\Contract\WorkflowEntryRecorder::VERBOSITY_NORMAL, $text);
        if ($exitCode === 0) {
            $submitRecorder->setPullNumber(41);
        }

        return $submitRecorder->toResponse($exitCode);
    }

    private function coordinatorWithSubmitResponse(\App\Response\WorkflowResponse $response): BranchRenamePrCoordinator
    {
        $submitHandler = $this->createStub(SubmitHandler::class);
        $submitHandler->method('handle')->willReturn($response);

        return new BranchRenamePrCoordinator(
            $this->gitRepository,
            $this->githubProvider,
            $submitHandler,
            $this->createStub(PromptInterface::class),
        );
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
