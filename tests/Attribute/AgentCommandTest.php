<?php

declare(strict_types=1);

namespace App\Tests\Attribute;

use App\Attribute\AgentCommand;
use PHPUnit\Framework\TestCase;

class AgentCommandTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $attr = new AgentCommand();

        $this->assertFalse($attr->essential);
    }

    public function testEssentialFlag(): void
    {
        $attr = new AgentCommand(essential: true);

        $this->assertTrue($attr->essential);
    }
}
