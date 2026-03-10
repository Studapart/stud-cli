<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\Response\AgentJsonResponse;
use PHPUnit\Framework\TestCase;

class AgentJsonResponseTest extends TestCase
{
    public function testSuccessPayload(): void
    {
        $response = new AgentJsonResponse(true, data: ['key' => 'PROJ-1']);
        $payload = $response->toPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame(['key' => 'PROJ-1'], $payload['data']);
        $this->assertArrayNotHasKey('error', $payload);
    }

    public function testErrorPayload(): void
    {
        $response = new AgentJsonResponse(false, error: 'Something went wrong');
        $payload = $response->toPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('Something went wrong', $payload['error']);
        $this->assertArrayNotHasKey('data', $payload);
    }

    public function testErrorPayloadDefaultsToUnknownError(): void
    {
        $response = new AgentJsonResponse(false);
        $payload = $response->toPayload();
        $this->assertFalse($payload['success']);
        $this->assertSame('Unknown error', $payload['error']);
    }

    public function testSuccessPayloadEmptyData(): void
    {
        $response = new AgentJsonResponse(true);
        $payload = $response->toPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame([], $payload['data']);
    }

    public function testReadonlyProperties(): void
    {
        $response = new AgentJsonResponse(true, ['foo' => 'bar'], 'ignored');
        $this->assertTrue($response->success);
        $this->assertSame(['foo' => 'bar'], $response->data);
        $this->assertSame('ignored', $response->error);
    }
}
