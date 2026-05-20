<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\DTO\PullRequestComment;
use App\DTO\PullRequestFeedbackActions;
use App\DTO\PullRequestFeedbackConversation;
use App\DTO\PullRequestFeedbackIds;
use App\DTO\PullRequestFeedbackState;
use App\Response\PrCommentsResponse;
use PHPUnit\Framework\TestCase;

class PrCommentsResponseTest extends TestCase
{
    public function testSuccessFactoryMethodCreatesSuccessfulResponse(): void
    {
        $date = new \DateTimeImmutable('2025-01-15 10:00:00');
        $comment = new PullRequestComment('alice', $date, 'Body', null, null);
        $issueComments = [$comment];
        $reviewComments = [];
        $reviews = [];
        $pullNumber = 42;

        $response = PrCommentsResponse::success($issueComments, $reviewComments, $reviews, $pullNumber);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame($issueComments, $response->issueComments);
        $this->assertSame($reviewComments, $response->reviewComments);
        $this->assertSame($reviews, $response->reviews);
        $this->assertSame($pullNumber, $response->pullNumber);
        $this->assertSame([], $response->conversations);
        $this->assertFalse($response->threaded);
    }

    public function testSuccessFactoryMethodCarriesThreadedConversations(): void
    {
        $conversation = new PullRequestFeedbackConversation(
            new PullRequestFeedbackIds('gitlab', 'review_thread', discussionId: 'abc'),
            'review_thread',
            new PullRequestFeedbackState(resolved: false),
            [],
            new PullRequestFeedbackActions(canReply: true),
        );

        $response = PrCommentsResponse::success([], [], [], 42, [$conversation], true);

        $this->assertTrue($response->isSuccess());
        $this->assertTrue($response->threaded);
        $this->assertSame([$conversation], $response->conversations);
    }

    public function testErrorFactoryMethodCreatesErrorResponse(): void
    {
        $errorMessage = 'No PR/MR found for the current branch.';

        $response = PrCommentsResponse::error($errorMessage);

        $this->assertFalse($response->isSuccess());
        $this->assertSame($errorMessage, $response->getError());
        $this->assertEmpty($response->issueComments);
        $this->assertEmpty($response->reviewComments);
        $this->assertEmpty($response->reviews);
        $this->assertEmpty($response->conversations);
        $this->assertSame(0, $response->pullNumber);
        $this->assertFalse($response->threaded);
    }
}
