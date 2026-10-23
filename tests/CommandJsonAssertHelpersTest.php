<?php

declare(strict_types=1);

namespace App\Tests;

use App\Response\AgentJsonResponse;

final class CommandJsonAssertHelpersTest extends CommandTestCase
{
    public function testAgentJsonAssertHelpersMatchToPayloadContract(): void
    {
        $successPayload = (new AgentJsonResponse(true, data: ['key' => 'SCI-1']))->toPayload();
        $this->assertAgentJsonSuccess($successPayload, ['key' => 'SCI-1']);

        $errorPayload = (new AgentJsonResponse(false, error: 'Something went wrong'))->toPayload();
        $this->assertAgentJsonErrorString($errorPayload, 'Something went wrong');
    }
}
