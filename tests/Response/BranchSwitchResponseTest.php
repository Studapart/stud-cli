<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\Response\BranchSwitchResponse;
use PHPUnit\Framework\TestCase;

class BranchSwitchResponseTest extends TestCase
{
    public function testNeedsSelectionFactoryCreatesSelectionResponse(): void
    {
        $matches = ['feat/SCI-123-a', 'fix/SCI-123-b'];

        $response = BranchSwitchResponse::needsSelection('SCI-123', $matches);

        $this->assertFalse($response->isSuccess());
        $this->assertNull($response->getError());
        $this->assertTrue($response->needsSelection);
        $this->assertSame($matches, $response->matches);
    }

    public function testSwitchedFactoryCreatesSuccessfulResponse(): void
    {
        $response = BranchSwitchResponse::switched('SCI-123', 'feat/SCI-123-a');

        $this->assertTrue($response->isSuccess());
        $this->assertSame('feat/SCI-123-a', $response->branch);
        $this->assertTrue($response->switched);
        $this->assertFalse($response->synced);
    }

    public function testErrorFactoryCreatesFailureResponse(): void
    {
        $response = BranchSwitchResponse::error('SCI-123', 'No branch', ['feat/SCI-123-a']);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('No branch', $response->getError());
        $this->assertSame(['feat/SCI-123-a'], $response->matches);
    }

    public function testWithSyncResultMarksSuccessOrFailure(): void
    {
        $response = BranchSwitchResponse::switched('SCI-123', 'feat/SCI-123-a');

        $success = $response->withSyncResult(0, 'sync failed');
        $failure = $response->withSyncResult(1, 'sync failed');

        $this->assertTrue($success->isSuccess());
        $this->assertTrue($success->synced);
        $this->assertSame(0, $success->syncExitCode);
        $this->assertFalse($failure->isSuccess());
        $this->assertSame('sync failed', $failure->getError());
        $this->assertSame(1, $failure->syncExitCode);
    }
}
