<?php

declare(strict_types=1);

namespace App\Tests\Exception;

use App\Exception\AgentModeException;
use PHPUnit\Framework\TestCase;

class AgentModeExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $e = new AgentModeException('Invalid JSON');
        $this->assertInstanceOf(\RuntimeException::class, $e);
        $this->assertSame('Invalid JSON', $e->getMessage());
    }
}
