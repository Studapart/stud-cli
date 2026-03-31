<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Exception\ApiException;
use App\Service\JiraAttachmentService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class JiraAttachmentServiceTest extends TestCase
{
    public function testFetchAttachmentsReturnsEmptyWhenAttachmentFieldNotArray(): void
    {
        $payload = ['fields' => ['attachment' => 'broken']];
        $client = new MockHttpClient([
            new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->assertSame([], $service->fetchAttachmentsForIssue('K-1'));
    }

    public function testFetchAttachmentsSkipsRowWhenContentNotString(): void
    {
        $payload = [
            'fields' => [
                'attachment' => [
                    ['filename' => 'x', 'content' => null],
                    ['filename' => 'y', 'content' => 42],
                ],
            ],
        ];
        $client = new MockHttpClient([
            new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->assertSame([], $service->fetchAttachmentsForIssue('K-1'));
    }

    public function testFetchAttachmentsSkipsNonArrayRows(): void
    {
        $payload = [
            'fields' => [
                'attachment' => [
                    'not-array',
                    ['filename' => 'ok.bin', 'content' => 'https://acme.atlassian.net/rest/api/3/attachment/content/2'],
                ],
            ],
        ];
        $client = new MockHttpClient([
            new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), ['http_code' => 200]),
        ]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $rows = $service->fetchAttachmentsForIssue('K-1');
        $this->assertCount(1, $rows);
        $this->assertSame('ok.bin', $rows[0]['filename']);
    }

    public function testFetchAttachmentsThrowsOnHttpError(): void
    {
        $client = new MockHttpClient([
            new MockResponse('{}', ['http_code' => 404]),
        ]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->expectException(ApiException::class);
        $service->fetchAttachmentsForIssue('MISS');
    }

    public function testDownloadWithFullUrlSucceeds(): void
    {
        $url = 'https://acme.atlassian.net/rest/api/3/attachment/content/7';
        $client = new MockHttpClient(static function (string $method, string $passedUrl) use ($url): MockResponse {
            if ($method === 'GET' && str_contains($passedUrl, 'attachment/content/7')) {
                return new MockResponse('data', ['http_code' => 200]);
            }

            return new MockResponse('', ['http_code' => 404]);
        });
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->assertSame('data', $service->downloadAttachmentContent($url));
    }

    public function testDownloadNonOkThrowsAndExtractsTechnicalDetailsWithTruncation(): void
    {
        $long = str_repeat('y', 600);
        $client = new MockHttpClient([
            new MockResponse($long, ['http_code' => 500]),
        ]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        try {
            $service->downloadAttachmentContent('/rest/api/3/attachment/content/1');
            $this->fail('Expected ApiException');
        } catch (ApiException $e) {
            $this->assertStringContainsString('truncated', $e->getTechnicalDetails());
        }
    }

    public function testDownloadUsesRelativePath(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url): MockResponse {
            if ($method === 'GET' && str_contains($url, '/rest/api/3/attachment/content/5')) {
                return new MockResponse('binary', ['http_code' => 200]);
            }

            return new MockResponse('', ['http_code' => 404]);
        });
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $body = $service->downloadAttachmentContent('/rest/api/3/attachment/content/5');

        $this->assertSame('binary', $body);
    }

    public function testDownloadRejectsRelativePathWithoutAttachmentSegment(): void
    {
        $client = new MockHttpClient([]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->expectException(ApiException::class);
        $service->downloadAttachmentContent('/rest/api/3/issue/1');
    }

    public function testDownloadRejectsEmptyTarget(): void
    {
        $client = new MockHttpClient([]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->expectException(ApiException::class);
        $service->downloadAttachmentContent('   ');
    }

    public function testDownloadRejectsWrongHost(): void
    {
        $client = new MockHttpClient([]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->expectException(ApiException::class);
        $service->downloadAttachmentContent('https://evil.example/rest/api/3/attachment/content/1');
    }

    public function testDownloadRejectsMalformedAbsoluteUrl(): void
    {
        $client = new MockHttpClient([]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->expectException(ApiException::class);
        $service->downloadAttachmentContent('://oops');
    }

    public function testDownloadRejectsPathWithoutAttachmentSegmentOnSameHost(): void
    {
        $client = new MockHttpClient([]);
        $service = new JiraAttachmentService($client, 'https://acme.atlassian.net');

        $this->expectException(ApiException::class);
        $service->downloadAttachmentContent('https://acme.atlassian.net/rest/api/3/issue/1');
    }

    public function testDownloadFailsWhenJiraBaseUrlHasNoHost(): void
    {
        $client = new MockHttpClient([]);
        $service = new JiraAttachmentService($client, 'http://');

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid Jira base URL configuration.');
        $service->downloadAttachmentContent('https://acme.atlassian.net/rest/api/3/attachment/content/1');
    }
}
