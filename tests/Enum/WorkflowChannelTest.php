<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\WorkflowChannel;
use PHPUnit\Framework\TestCase;

final class WorkflowChannelTest extends TestCase
{
    public function testCases(): void
    {
        self::assertSame(['Default', 'Jira', 'Git'], array_map(
            static fn (WorkflowChannel $channel): string => $channel->name,
            WorkflowChannel::cases(),
        ));
    }
}
