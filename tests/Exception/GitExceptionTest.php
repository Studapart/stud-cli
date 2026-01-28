<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\GitException;
use PHPUnit\Framework\TestCase;

class GitExceptionTest extends TestCase
{
    public function testGetTechnicalDetailsReturnsTechnicalDetails(): void
    {
        $exception = new GitException('User message', 'Technical details');

        $this->assertSame('Technical details', $exception->getTechnicalDetails());
    }

    public function testGetMessageReturnsUserMessage(): void
    {
        $exception = new GitException('User message', 'Technical details');

        $this->assertSame('User message', $exception->getMessage());
    }

    public function testPreviousExceptionIsPreserved(): void
    {
        $previous = new \RuntimeException('Previous exception');
        $exception = new GitException('User message', 'Technical details', $previous);

        $this->assertSame($previous, $exception->getPrevious());
    }

    public function testCodeIsZero(): void
    {
        $exception = new GitException('User message', 'Technical details');

        $this->assertSame(0, $exception->getCode());
    }
}
