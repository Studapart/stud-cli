<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\LinearApiClient;
use App\Service\LinearFileUploader;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class LinearFileUploaderTest extends TestCase
{
    public function testUploadSignsPutsBodyAndReturnsAssetUrl(): void
    {
        $linearApi = $this->createMock(LinearApiClient::class);
        $linearApi->expects($this->once())
            ->method('fileUpload')
            ->with('report.md', 'application/x-www-form-urlencoded', 11)
            ->willReturn($this->signedUpload([
                ['key' => 'Content-Type', 'value' => 'text/plain'],
                ['key' => 'x-amz-acl', 'value' => 'private'],
            ]));

        $uploadClient = new MockHttpClient(static function (string $method, string $url, array $options = []): MockResponse {
            TestCase::assertSame('PUT', $method);
            TestCase::assertSame('https://uploads.linear.app/signed/object', $url);
            TestCase::assertSame('report-body', $options['body'] ?? null);
            $flat = json_encode($options['headers'] ?? [], JSON_THROW_ON_ERROR);
            TestCase::assertStringContainsString('application\/x-www-form-urlencoded', $flat);
            TestCase::assertStringNotContainsString('text\/plain', $flat);
            TestCase::assertStringContainsString('x-amz-acl: private', $flat);

            return new MockResponse('', ['http_code' => 200]);
        });

        $uploader = new LinearFileUploader($linearApi, $uploadClient);

        $this->assertSame('https://public.linear.app/assets/report.md', $uploader->upload('report.md', 'report-body'));
    }

    public function testUploadThrowsWithBodyDetailsWhenPutFails(): void
    {
        $uploader = new LinearFileUploader(
            $this->linearApiReturningSignedUpload(),
            new MockHttpClient([new MockResponse('forbidden', ['http_code' => 403])]),
        );

        try {
            $uploader->upload('x.bin', 'x');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('Linear attachment upload failed.', $exception->getMessage());
            $this->assertSame('forbidden', $exception->getTechnicalDetails());
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function testUploadFallsBackToStatusCodeWhenFailureBodyUnreadable(): void
    {
        $response = $this->createStub(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(502);
        $response->method('getContent')->willThrowException(new \RuntimeException('broken stream'));
        $uploadClient = $this->createStub(HttpClientInterface::class);
        $uploadClient->method('request')->willReturn($response);

        $uploader = new LinearFileUploader($this->linearApiReturningSignedUpload(), $uploadClient);

        try {
            $uploader->upload('x.bin', 'x');
            $this->fail('Expected ApiException was not thrown.');
        } catch (ApiException $exception) {
            $this->assertSame('HTTP 502', $exception->getTechnicalDetails());
        }
    }

    private function linearApiReturningSignedUpload(): LinearApiClient
    {
        $linearApi = $this->createStub(LinearApiClient::class);
        $linearApi->method('fileUpload')->willReturn($this->signedUpload([]));

        return $linearApi;
    }

    /**
     * @param list<array{key: string, value: string}> $headers
     *
     * @return array{uploadUrl: string, assetUrl: string, headers: list<array{key: string, value: string}>}
     */
    private function signedUpload(array $headers): array
    {
        return [
            'uploadUrl' => 'https://uploads.linear.app/signed/object',
            'assetUrl' => 'https://public.linear.app/assets/report.md',
            'headers' => $headers,
        ];
    }
}
