<?php

declare(strict_types=1);

namespace App\Tests\Response;

use App\DTO\ResponseMessage;
use App\Response\AgentJsonResponse;
use App\Response\CommandResponse;
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

    public function testSuccessPayloadWithoutData(): void
    {
        $payload = AgentJsonResponse::successWithoutData()->toPayload();
        $this->assertSame(['success' => true], $payload);
    }

    public function testSuccessWithoutDataKeepsDiagnostics(): void
    {
        $payload = AgentJsonResponse::successWithoutData([
            'warnings' => [['message' => 'Careful']],
        ])->toPayload();

        $this->assertSame([
            'success' => true,
            'diagnostics' => [
                'warnings' => [['message' => 'Careful']],
            ],
        ], $payload);
    }

    public function testErrorPayloadKeepsDiagnostics(): void
    {
        $response = new AgentJsonResponse(false, error: 'Failed', diagnostics: [
            'errors' => [['message' => 'Failed', 'technicalDetails' => 'details']],
        ]);

        $this->assertSame([
            'success' => false,
            'error' => 'Failed',
            'diagnostics' => [
                'errors' => [['message' => 'Failed', 'technicalDetails' => 'details']],
            ],
        ], $response->toPayload());
    }

    public function testFromResponseCompactsSuccessWithoutDataAndKeepsDiagnostics(): void
    {
        $response = CommandResponse::success(messages: [ResponseMessage::warning('Careful')]);

        $this->assertSame([
            'success' => true,
            'diagnostics' => [
                'warnings' => [['message' => 'Careful']],
            ],
        ], AgentJsonResponse::fromResponse($response, compact: true)->toPayload());
    }

    public function testFromResponseSerializesSuccessData(): void
    {
        $response = CommandResponse::success('Done');

        $this->assertSame([
            'success' => true,
            'data' => ['message' => 'Done'],
        ], AgentJsonResponse::fromResponse($response, $response->payloadData())->toPayload());
    }

    public function testFromResponseSerializesError(): void
    {
        $response = CommandResponse::error('Failed', [ResponseMessage::error('Failed', 'details')]);

        $this->assertSame([
            'success' => false,
            'error' => 'Failed',
            'diagnostics' => [
                'errors' => [['message' => 'Failed', 'technicalDetails' => 'details']],
            ],
        ], AgentJsonResponse::fromResponse($response)->toPayload());
    }

    public function testSuccessPayloadWithScalarData(): void
    {
        $response = new AgentJsonResponse(true, data: '1.2.3');
        $payload = $response->toPayload();
        $this->assertTrue($payload['success']);
        $this->assertSame('1.2.3', $payload['data']);
    }

    public function testReadonlyProperties(): void
    {
        $response = new AgentJsonResponse(true, ['foo' => 'bar'], 'ignored');
        $this->assertTrue($response->success);
        $this->assertSame(['foo' => 'bar'], $response->data);
        $this->assertSame('ignored', $response->error);
        $this->assertTrue($response->hasData);
    }
}
