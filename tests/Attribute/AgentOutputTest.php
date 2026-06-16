<?php

declare(strict_types=1);

namespace App\Tests\Attribute;

use App\Attribute\AgentOutput;
use PHPUnit\Framework\TestCase;

class AgentOutputTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attr = new AgentOutput();
        $this->assertNull($attr->responseClass);
        $this->assertSame([], $attr->properties);
        $this->assertNull($attr->description);
        $this->assertFalse($attr->completionOnly);
    }

    public function testWithResponseClass(): void
    {
        $attr = new AgentOutput(responseClass: \stdClass::class, description: 'Test output');
        $this->assertSame(\stdClass::class, $attr->responseClass);
        $this->assertSame([], $attr->properties);
        $this->assertSame('Test output', $attr->description);
        $this->assertFalse($attr->completionOnly);
    }

    public function testWithExplicitProperties(): void
    {
        $attr = new AgentOutput(properties: ['message' => 'string'], description: 'Simple output');
        $this->assertNull($attr->responseClass);
        $this->assertSame(['message' => 'string'], $attr->properties);
        $this->assertSame('Simple output', $attr->description);
        $this->assertFalse($attr->completionOnly);
    }

    public function testWithCompletionOnlyOutput(): void
    {
        $attr = new AgentOutput(properties: ['message' => 'string'], completionOnly: true);
        $this->assertSame(['message' => 'string'], $attr->properties);
        $this->assertTrue($attr->completionOnly);
    }
}
