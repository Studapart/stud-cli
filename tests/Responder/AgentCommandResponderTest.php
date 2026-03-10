<?php

declare(strict_types=1);

namespace App\Tests\Responder;

use App\Responder\AgentCommandResponder;
use PHPUnit\Framework\TestCase;

class AgentCommandResponderTest extends TestCase
{
    private AgentCommandResponder $responder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->responder = new AgentCommandResponder();
    }

    public function testRespondFromExitCodeZeroReturnsSuccess(): void
    {
        $result = $this->responder->respondFromExitCode(0, 'Done', 'Failed');
        $this->assertTrue($result->success);
        $this->assertSame(['message' => 'Done'], $result->data);
        $this->assertNull($result->error);
    }

    public function testRespondFromExitCodeNonZeroReturnsError(): void
    {
        $result = $this->responder->respondFromExitCode(1, 'Done', 'Failed');
        $this->assertFalse($result->success);
        $this->assertSame('Failed', $result->error);
    }

    public function testRespondSuccess(): void
    {
        $result = $this->responder->respondSuccess('Operation completed');
        $this->assertTrue($result->success);
        $this->assertSame(['message' => 'Operation completed'], $result->data);
        $this->assertNull($result->error);
    }
}
