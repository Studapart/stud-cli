<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\DTO\WorkflowRecorder;
use App\Enum\WorkflowChannel;
use App\Service\RecoverableExceptionLogger;
use PHPUnit\Framework\TestCase;

class RecoverableExceptionLoggerTest extends TestCase
{
    public function testLogToRecorderWritesDebugLine(): void
    {
        $recorder = new WorkflowRecorder();
        RecoverableExceptionLogger::logToRecorder(
            $recorder,
            new \RuntimeException('boom'),
            'Context',
            WorkflowChannel::Git,
        );

        $response = $recorder->toResponse(0);
        $this->assertNotEmpty($response->entries);
    }
}
