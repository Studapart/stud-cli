<?php

namespace App\Tests\Handler;

use App\Handler\BranchAction;
use PHPUnit\Framework\TestCase;

class BranchActionTest extends TestCase
{
    public function testConstantsAreDefined(): void
    {
        $this->assertSame('switch_local', BranchAction::SWITCH_LOCAL);
        $this->assertSame('switch_remote', BranchAction::SWITCH_REMOTE);
        $this->assertSame('create', BranchAction::CREATE);
    }

    public function testClassCannotBeInstantiated(): void
    {
        $reflection = new \ReflectionClass(BranchAction::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $this->assertTrue($constructor->isPrivate());

        // Call the private constructor via reflection to achieve coverage
        // We need to create a temporary instance to invoke the constructor
        $constructor->setAccessible(true);
        $instance = $reflection->newInstanceWithoutConstructor();
        $constructor->invoke($instance);

        // Verify that normal instantiation is not possible
        $this->expectException(\Error::class);
        new BranchAction();
    }
}
