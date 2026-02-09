<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\PullRequestComment;
use App\Handler\PrCommentsHandler;
use App\Response\PrCommentsResponse;
use App\Service\GitProviderInterface;
use App\Tests\CommandTestCase;
use PHPUnit\Framework\MockObject\MockObject;

class PrCommentsHandlerTest extends CommandTestCase
{
    /** @var GitProviderInterface&MockObject */
    private $gitProvider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gitProvider = $this->createMock(GitProviderInterface::class);
    }

    public function testHandleReturnsSuccessWithComments(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/SCI-55');
        $this->gitRepository->method('getRepositoryOwner')->with('origin')->willReturn('studapart');

        $issueComment = new PullRequestComment('alice', new \DateTimeImmutable(), 'Issue comment body', null, null);
        $reviewComment = new PullRequestComment('bob', new \DateTimeImmutable(), 'Review body', 'src/File.php', 10);

        $this->gitProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/SCI-55')
            ->willReturn(['number' => 123]);

        $this->gitProvider->expects($this->once())
            ->method('getPullRequestComments')
            ->with(123)
            ->willReturn([$issueComment]);

        $this->gitProvider->expects($this->once())
            ->method('getPullRequestReviewComments')
            ->with(123)
            ->willReturn([$reviewComment]);

        $this->gitProvider->expects($this->once())
            ->method('getPullRequestReviews')
            ->with(123)
            ->willReturn([]);

        $handler = new PrCommentsHandler($this->gitRepository, $this->gitProvider, $this->translationService);
        $response = $handler->handle();

        $this->assertInstanceOf(PrCommentsResponse::class, $response);
        $this->assertTrue($response->isSuccess());
        $this->assertSame(123, $response->pullNumber);
        $this->assertCount(1, $response->issueComments);
        $this->assertCount(1, $response->reviewComments);
        $this->assertSame([], $response->reviews);
        $this->assertSame($issueComment, $response->issueComments[0]);
        $this->assertSame($reviewComment, $response->reviewComments[0]);
    }

    public function testHandleReturnsErrorWhenNoProvider(): void
    {
        $handler = new PrCommentsHandler($this->gitRepository, null, $this->translationService);
        $response = $handler->handle();

        $this->assertInstanceOf(PrCommentsResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertNotNull($response->getError());
        $this->assertStringContainsString('pr.comments.error_no_provider', $response->getError());
    }

    public function testHandleReturnsErrorWhenNoPrFound(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/SCI-55');
        $this->gitRepository->method('getRepositoryOwner')->with('origin')->willReturn('studapart');

        $this->gitProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/SCI-55')
            ->willReturn(null);

        $handler = new PrCommentsHandler($this->gitRepository, $this->gitProvider, $this->translationService);
        $response = $handler->handle();

        $this->assertInstanceOf(PrCommentsResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertNotNull($response->getError());
        $this->assertStringContainsString('pr.comments.error_no_pr', $response->getError());
    }

    public function testHandleReturnsErrorWhenFetchThrows(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/SCI-55');
        $this->gitRepository->method('getRepositoryOwner')->with('origin')->willReturn('studapart');

        $this->gitProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/SCI-55')
            ->willReturn(['number' => 123]);

        $this->gitProvider->expects($this->once())
            ->method('getPullRequestComments')
            ->with(123)
            ->willThrowException(new \RuntimeException('API error'));

        $handler = new PrCommentsHandler($this->gitRepository, $this->gitProvider, $this->translationService);
        $response = $handler->handle();

        $this->assertInstanceOf(PrCommentsResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertNotNull($response->getError());
        $this->assertStringContainsString('pr.comments.error_fetch', $response->getError());
    }

    public function testHandleReturnsErrorWhenPrNumberInvalid(): void
    {
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/SCI-55');
        $this->gitRepository->method('getRepositoryOwner')->with('origin')->willReturn('studapart');

        $this->gitProvider->expects($this->once())
            ->method('findPullRequestByBranch')
            ->with('studapart:feat/SCI-55')
            ->willReturn(['number' => 0]);

        $handler = new PrCommentsHandler($this->gitRepository, $this->gitProvider, $this->translationService);
        $response = $handler->handle();

        $this->assertInstanceOf(PrCommentsResponse::class, $response);
        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('pr.comments.error_no_pr', $response->getError());
    }
}
