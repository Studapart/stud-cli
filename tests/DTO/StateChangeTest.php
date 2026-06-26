<?php

declare(strict_types=1);

namespace App\Tests\DTO;

use App\DTO\StateChange;
use PHPUnit\Framework\TestCase;

class StateChangeTest extends TestCase
{
    public function testConstructsWithOptionalFields(): void
    {
        $change = new StateChange('21', 'In Progress', 'In Progress', 'started');

        $this->assertSame('21', $change->id);
        $this->assertSame('In Progress', $change->name);
        $this->assertSame('In Progress', $change->targetStatus);
        $this->assertSame('started', $change->type);
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $change = new StateChange('state-uuid', 'Todo');

        $this->assertNull($change->targetStatus);
        $this->assertNull($change->type);
    }
}
