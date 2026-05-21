<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\Response\PrCommentResponse;
use PHPUnit\Framework\TestCase;

class PrCommentResponseTest extends TestCase
{
    public function testPostedFactoryCreatesPostedResponse(): void
    {
        $response = PrCommentResponse::posted('Posted', 123);

        $this->assertTrue($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertSame('Posted', $response->message);
        $this->assertSame('posted', $response->action);
        $this->assertSame(123, $response->pullNumber);
        $this->assertNull($response->target);
        $this->assertFalse($response->resolved);
    }

    public function testRepliedFactoryCreatesRepliedResponse(): void
    {
        $response = PrCommentResponse::replied('Replied', 123, 'github:review_thread:thread', true);

        $this->assertTrue($response->isSuccess());
        $this->assertSame('Replied', $response->message);
        $this->assertSame('replied', $response->action);
        $this->assertSame(123, $response->pullNumber);
        $this->assertSame('github:review_thread:thread', $response->target);
        $this->assertTrue($response->resolved);
    }

    public function testErrorFactoryCreatesErrorResponse(): void
    {
        $response = PrCommentResponse::error('Failure');

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Failure', $response->getError());
        $this->assertSame('', $response->message);
        $this->assertSame('posted', $response->action);
        $this->assertSame(0, $response->pullNumber);
        $this->assertNull($response->target);
        $this->assertFalse($response->resolved);
    }
}
