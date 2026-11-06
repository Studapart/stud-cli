<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\ConfluenceResponseGuard;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ConfluenceResponseGuardTest extends TestCase
{
    private ConfluenceResponseGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new ConfluenceResponseGuard();
    }

    public function testAssertStatusPassesForAnyExpectedStatus(): void
    {
        $this->guard->assertStatus($this->response(201, ''), 'unused', 200, 201);

        $this->addToAssertionCount(1);
    }

    public function testAssertStatusThrowsWithResponseBody(): void
    {
        $exception = $this->captureFailure($this->response(404, 'Not found'));

        self::assertSame('Boom.', $exception->getMessage());
        self::assertSame('HTTP 404. Not found', $exception->getTechnicalDetails());
        self::assertSame(404, $exception->getStatusCode());
    }

    public function testAssertStatusReportsMissingBody(): void
    {
        self::assertSame('HTTP 500. No response body', $this->captureFailure($this->response(500, ''))->getTechnicalDetails());
    }

    public function testAssertStatusTruncatesLongBody(): void
    {
        $details = $this->captureFailure($this->response(400, str_repeat('x', 501)))->getTechnicalDetails();

        self::assertSame('HTTP 400. ' . str_repeat('x', 500) . '... (truncated)', $details);
    }

    public function testAssertStatusReportsUnreadableBody(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(502);
        $response->method('getContent')->willThrowException(new \RuntimeException('stream closed'));

        self::assertSame(
            'HTTP 502. Unable to read response body: stream closed',
            $this->captureFailure($response)->getTechnicalDetails(),
        );
    }

    private function captureFailure(ResponseInterface $response): ApiException
    {
        try {
            $this->guard->assertStatus($response, 'Boom.', 200);
        } catch (ApiException $e) {
            return $e;
        }

        self::fail('Expected ApiException');
    }

    private function response(int $status, string $content): ResponseInterface
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getContent')->willReturn($content);

        return $response;
    }
}
