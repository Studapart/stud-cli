<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\DTO\PullRequestComment;
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
        $this->assertSame(0, $response->pullNumber);
    }
}
