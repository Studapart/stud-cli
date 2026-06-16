<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\DTO\ResponseMessage;
use App\Response\CommandResponse;
use PHPUnit\Framework\TestCase;

final class CommandResponseTest extends TestCase
{
    public function testSuccessPayloadDataIncludesMessageBeforeData(): void
    {
        $response = CommandResponse::success('Done', ['branch' => 'feat/test']);

        $this->assertTrue($response->hasReusableData());
        $this->assertSame(['message' => 'Done', 'branch' => 'feat/test'], $response->payloadData());
    }

    public function testCompletionOnlySuccessHasNoReusableData(): void
    {
        $response = CommandResponse::success();

        $this->assertFalse($response->hasReusableData());
        $this->assertSame([], $response->payloadData());
    }

    public function testErrorCarriesDiagnosticsAndData(): void
    {
        $response = CommandResponse::error('Failed', [ResponseMessage::error('Failed')], ['branch' => 'feat/test']);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('Failed', $response->getError());
        $this->assertSame(['branch' => 'feat/test'], $response->payloadData());
        $this->assertSame('Failed', $response->getErrors()[0]->message);
    }
}
