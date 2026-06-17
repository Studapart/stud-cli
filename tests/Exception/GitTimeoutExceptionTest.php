<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\GitTimeoutException;
use PHPUnit\Framework\TestCase;

class GitTimeoutExceptionTest extends TestCase
{
    public function testExposesCommandAndTimeoutSeconds(): void
    {
        $exception = new GitTimeoutException('git commit -m test', 600.0, 'process timed out');

        $this->assertSame('git commit -m test', $exception->getCommand());
        $this->assertSame(600.0, $exception->getTimeoutSeconds());
        $this->assertSame('process timed out', $exception->getTechnicalDetails());
        $this->assertStringContainsString('git commit -m test', $exception->getMessage());
    }
}
