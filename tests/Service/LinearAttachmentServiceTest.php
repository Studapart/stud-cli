<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\LinearApiClient;
use App\Service\LinearAttachmentService;
use App\Service\LinearGraphqlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\ScopingHttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class LinearAttachmentServiceTest extends TestCase
{
    public function testUploadFileToIssueRunsFileUploadPutAndAttachmentCreate(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'studlinup');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'report-body');

        try {
            $graphqlClient = new MockHttpClient([
                new MockResponse(json_encode([
                    'data' => [
                        'fileUpload' => [
                            'success' => true,
                            'uploadFile' => [
                                'uploadUrl' => 'https://uploads.linear.app/signed/object',
                                'assetUrl' => 'https://public.linear.app/assets/report.md',
                                'headers' => [
                                    ['key' => 'Content-Type', 'value' => 'text/plain'],
                                    ['key' => 'x-amz-acl', 'value' => 'private'],
                                ],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR)),
                new MockResponse(json_encode([
                    'data' => [
                        'attachmentCreate' => [
                            'success' => true,
                            'attachment' => ['id' => 'att-1', 'url' => 'https://linear.app/file'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR)),
            ]);
            $linearApi = new LinearApiClient(new LinearGraphqlClient($graphqlClient));

            $uploadClient = new MockHttpClient(static function (string $method, string $url, array $options = []) use ($tmp): MockResponse {
                if ($method !== 'PUT' || $url !== 'https://uploads.linear.app/signed/object') {
                    return new MockResponse('', ['http_code' => 404]);
                }

                TestCase::assertArrayHasKey('headers', $options);
                $headers = $options['headers'];
                TestCase::assertIsArray($headers);
                $flat = json_encode($headers, JSON_THROW_ON_ERROR);
                TestCase::assertStringContainsStringIgnoringCase('content-type', $flat);
                TestCase::assertStringContainsString('text\/plain', $flat);
                TestCase::assertStringContainsString('x-amz-acl', $flat);
                TestCase::assertStringContainsString('private', $flat);
                TestCase::assertSame('report-body', $options['body'] ?? null);

                return new MockResponse('', ['http_code' => 200]);
            });

            $service = new LinearAttachmentService($linearApi, $uploadClient);
            $service->uploadFileToIssue('SCI-123', $tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testUploadRejectsEmptyAbsolutePath(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Cannot read local file for upload.');
        $service->uploadFileToIssue('SCI-1', '');
    }

    public function testUploadRejectsMissingFile(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi);

        $this->expectException(ApiException::class);
        $service->uploadFileToIssue('SCI-1', '/nonexistent/stud-linear-upload-test.bin');
    }

    public function testUploadRejectsDirectoryPath(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi);

        $this->expectException(ApiException::class);
        $service->uploadFileToIssue('SCI-1', sys_get_temp_dir());
    }

    public function testUploadPutNonSuccessThrows(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'studlinup');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'x');

        try {
            $graphqlClient = new MockHttpClient([
                new MockResponse(json_encode([
                    'data' => [
                        'fileUpload' => [
                            'success' => true,
                            'uploadFile' => [
                                'uploadUrl' => 'https://uploads.linear.app/signed/object',
                                'assetUrl' => 'https://public.linear.app/assets/x.bin',
                                'headers' => [],
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR)),
            ]);
            $linearApi = new LinearApiClient(new LinearGraphqlClient($graphqlClient));
            $uploadClient = new MockHttpClient([
                new MockResponse('forbidden', ['http_code' => 403]),
            ]);
            $service = new LinearAttachmentService($linearApi, $uploadClient);

            $this->expectException(ApiException::class);
            $this->expectExceptionMessage('Linear attachment upload failed.');
            $service->uploadFileToIssue('SCI-1', $tmp);
        } finally {
            unlink($tmp);
        }
    }

    public function testUploadHttpClientUsesDefaultWhenNotInjected(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi);
        $method = new \ReflectionMethod(LinearAttachmentService::class, 'uploadHttpClient');

        $client = $method->invoke($service);

        $this->assertInstanceOf(HttpClientInterface::class, $client);
    }

    public function testDetectContentTypeFallsBackToOctetStreamWhenMimeUnknown(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi);
        $method = new \ReflectionMethod(LinearAttachmentService::class, 'detectContentType');

        $mime = $method->invoke($service, '/nonexistent/stud-linear-mime-probe.bin');

        $this->assertSame('application/octet-stream', $mime);
    }

    public function testExtractTechnicalDetailsFallsBackToStatusWhenContentUnavailable(): void
    {
        $response = $this->createMock(\Symfony\Contracts\HttpClient\ResponseInterface::class);
        $response->method('getStatusCode')->willReturn(502);
        $response->method('getContent')->with(false)->willThrowException(new \RuntimeException('broken stream'));

        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi);
        $method = new \ReflectionMethod(LinearAttachmentService::class, 'extractTechnicalDetails');

        $this->assertSame('HTTP 502', $method->invoke($service, $response));
    }

    public function testFetchAttachmentsForIssueReturnsFilenameAndContentUrl(): void
    {
        $linearApi = $this->createMock(LinearApiClient::class);
        $linearApi->expects($this->once())
            ->method('getIssue')
            ->with('SCI-9')
            ->willReturn([
                'id' => 'issue-1',
                'identifier' => 'SCI-9',
                'title' => 'Issue',
                'state' => ['name' => 'Todo'],
                'assignee' => ['name' => 'Ada'],
                'labels' => ['nodes' => []],
                'attachments' => [
                    'nodes' => [
                        [
                            'id' => 'att-1',
                            'title' => 'report.md',
                            'url' => 'https://public.linear.app/assets/report.md',
                            'size' => 12,
                        ],
                    ],
                ],
            ]);

        $service = new LinearAttachmentService($linearApi);
        $rows = $service->fetchAttachmentsForIssue('SCI-9');

        $this->assertSame([
            ['filename' => 'report.md', 'contentUrl' => 'https://public.linear.app/assets/report.md'],
        ], $rows);
    }

    public function testDownloadWithAllowedHostSucceedsAndSendsAuthorization(): void
    {
        $url = 'https://public.linear.app/assets/report.md';
        $mock = new MockHttpClient(static function (string $method, string $passedUrl, array $options = []) use ($url): MockResponse {
            if ($method !== 'GET' || $passedUrl !== $url) {
                return new MockResponse('', ['http_code' => 404]);
            }

            TestCase::assertArrayHasKey('headers', $options);
            $headers = $options['headers'];
            TestCase::assertIsArray($headers);
            $flat = json_encode($headers, JSON_THROW_ON_ERROR);
            TestCase::assertStringContainsString('lin_api_test_key', $flat);

            return new MockResponse('file-bytes', ['http_code' => 200]);
        });
        $assetClient = ScopingHttpClient::forBaseUri($mock, 'https://public.linear.app', [
            'headers' => [
                'Authorization' => 'lin_api_test_key',
            ],
        ]);

        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi, assetHttpClient: $assetClient);

        $this->assertSame('file-bytes', $service->downloadAttachmentContent($url));
    }

    public function testDownloadAllowsUploadsLinearAppHost(): void
    {
        $url = 'https://uploads.linear.app/object/key';
        $assetClient = new MockHttpClient([
            new MockResponse('payload', ['http_code' => 200]),
        ]);
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi, assetHttpClient: $assetClient);

        $this->assertSame('payload', $service->downloadAttachmentContent($url));
    }

    public function testDownloadRejectsEmptyUrl(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi, assetHttpClient: new MockHttpClient([]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid attachment URL.');
        $service->downloadAttachmentContent('   ');
    }

    public function testDownloadRejectsMalformedUrl(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi, assetHttpClient: new MockHttpClient([]));

        $this->expectException(ApiException::class);
        $service->downloadAttachmentContent('://oops');
    }

    public function testDownloadRejectsDisallowedHost(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi, assetHttpClient: new MockHttpClient([]));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('allowed Linear asset host');
        $service->downloadAttachmentContent('https://evil.example/asset.bin');
    }

    public function testDownloadNonOkThrows(): void
    {
        $url = 'https://public.linear.app/assets/missing.bin';
        $assetClient = new MockHttpClient([
            new MockResponse('gone', ['http_code' => 404]),
        ]);
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi, assetHttpClient: $assetClient);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Attachment download failed.');
        $service->downloadAttachmentContent($url);
    }

    public function testAssetHttpClientUsesDefaultWhenNotInjected(): void
    {
        $linearApi = new LinearApiClient(new LinearGraphqlClient(new MockHttpClient([])));
        $service = new LinearAttachmentService($linearApi);
        $method = new \ReflectionMethod(LinearAttachmentService::class, 'assetHttpClient');

        $this->assertInstanceOf(HttpClientInterface::class, $method->invoke($service));
    }
}
