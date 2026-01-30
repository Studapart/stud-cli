<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\ApiException;
use PHPUnit\Framework\TestCase;

class ApiExceptionTest extends TestCase
{
    public function testGetTechnicalDetailsReturnsTechnicalDetails(): void
    {
        $exception = new ApiException('User message', 'Technical details', 422);

        $this->assertSame('Technical details', $exception->getTechnicalDetails());
    }

    public function testGetStatusCodeReturnsStatusCode(): void
    {
        $exception = new ApiException('User message', 'Technical details', 422);

        $this->assertSame(422, $exception->getStatusCode());
    }

    public function testGetStatusCodeReturnsNullWhenNotProvided(): void
    {
        $exception = new ApiException('User message', 'Technical details');

        $this->assertNull($exception->getStatusCode());
    }

    public function testGetMessageReturnsUserMessage(): void
    {
        $exception = new ApiException('User message', 'Technical details', 422);

        $this->assertSame('User message', $exception->getMessage());
    }

    public function testPreviousExceptionIsPreserved(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new ApiException('User message', 'Technical details', 422, $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCodeIsZero(): void
    {
        $exception = new ApiException('User message', 'Technical details', 422);

        $this->assertSame(0, $exception->getCode());
    }
}
