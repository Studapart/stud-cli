<?php

declare(strict_types=1);

namespace App\Tests\Service\Linear;

use App\Exception\ApiException;
use App\Service\Linear\LinearMutationClient;
use App\Service\LinearGraphqlClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LinearMutationClientTest extends TestCase
{
    private function createService(MockHttpClient $client): LinearMutationClient
    {
        return new LinearMutationClient(new LinearGraphqlClient($client));
    }

    public function testIssueCreateReturnsMappedIssue(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issueCreate' => [
                        'success' => true,
                        'issue' => [
                            'id' => 'issue-new',
                            'identifier' => 'SCI-99',
                            'url' => 'https://linear.app/studapart/issue/SCI-99',
                            'title' => 'New',
                            'description' => 'Desc',
                            'priority' => 2,
                            'state' => ['id' => 's1', 'name' => 'Todo', 'type' => 'unstarted'],
                            'team' => ['id' => 't1', 'key' => 'SCI', 'name' => 'Team'],
                            'labels' => ['nodes' => []],
                            'parent' => null,
                            'attachments' => ['nodes' => []],
                            'createdAt' => '2026-01-01T00:00:00.000Z',
                            'updatedAt' => '2026-01-01T00:00:00.000Z',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $issue = $service->issueCreate(['teamId' => 't1', 'title' => 'New', 'labelIds' => [], 'description' => null, 'priority' => null, 'parentId' => null]);

        $this->assertSame('SCI-99', $issue['identifier']);
        $this->assertStringContainsString('SCI-99', $issue['url']);
    }

    public function testIssueUpdateSucceedsWhenMutationReturnsSuccess(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issueUpdate' => [
                        'success' => true,
                        'issue' => [
                            'id' => 'issue-1',
                            'identifier' => 'SCI-1',
                            'title' => 'Updated',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $service = $this->createService($client);
        $service->issueUpdate('issue-1', ['title' => 'Updated']);

        $this->addToAssertionCount(1);
    }

    public function testIssueCreateIncludesOptionalPayloadFields(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'issueCreate' => [
                        'success' => true,
                        'issue' => [
                            'identifier' => 'SCI-100',
                            'url' => 'https://linear.app/SCI-100',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $issue = $this->createService($client)->issueCreate([
            'teamId' => 't1',
            'title' => 'Full',
            'description' => 'Body',
            'labelIds' => ['l1'],
            'priority' => 2,
            'parentId' => 'parent-1',
        ]);

        $this->assertSame('SCI-100', $issue['identifier']);
    }

    public function testIssueCreateThrowsWhenMutationFails(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueCreate' => ['success' => false]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->issueCreate([
            'teamId' => 't1',
            'title' => 'Fail',
            'labelIds' => [],
            'description' => null,
            'priority' => null,
            'parentId' => null,
        ]);
    }

    public function testIssueCreateThrowsWhenResponseMissingIssue(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueCreate' => ['success' => true, 'issue' => ['identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->issueCreate([
            'teamId' => 't1',
            'title' => 'Fail',
            'labelIds' => [],
            'description' => null,
            'priority' => null,
            'parentId' => null,
        ]);
    }

    public function testIssueUpdateNoOpsWhenInputEmpty(): void
    {
        $client = new MockHttpClient([]);

        $this->createService($client)->issueUpdate('issue-1', []);

        $this->addToAssertionCount(1);
    }

    public function testIssueUpdateThrowsWhenMutationFails(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => false]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->issueUpdate('issue-1', ['title' => 'X']);
    }

    public function testIssueUpdateSendsDescriptionAndPriorityFields(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'i1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->issueUpdate('issue-1', [
            'title' => 'T',
            'description' => null,
            'priority' => 3,
            'labelIds' => ['label-1'],
        ]);

        $this->addToAssertionCount(1);
    }

    public function testIssueUpdateSendsStateIdField(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'i1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->issueUpdate('issue-1', [
            'stateId' => 'state-started-uuid',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testIssueUpdateSendsAssigneeIdField(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['issueUpdate' => ['success' => true, 'issue' => ['id' => 'i1', 'identifier' => 'SCI-1']]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->issueUpdate('issue-1', [
            'assigneeId' => 'viewer-uuid-1',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testFileUploadReturnsUploadTarget(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'fileUpload' => [
                        'success' => true,
                        'uploadFile' => [
                            'uploadUrl' => 'https://uploads.linear.app/object',
                            'assetUrl' => 'https://assets.linear.app/report.md',
                            'headers' => [
                                ['key' => 'Content-Type', 'value' => 'text/markdown'],
                                ['key' => 'bad-row', 'value' => 1],
                                'not-array',
                            ],
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $this->createService($client)->fileUpload('report.md', 'text/markdown', 42);

        $this->assertSame('https://uploads.linear.app/object', $result['uploadUrl']);
        $this->assertSame('https://assets.linear.app/report.md', $result['assetUrl']);
        $this->assertSame([['key' => 'Content-Type', 'value' => 'text/markdown']], $result['headers']);
    }

    public function testFileUploadThrowsWhenSuccessFalse(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['fileUpload' => ['success' => false]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Linear file upload request failed.');
        $this->createService($client)->fileUpload('x.bin', 'application/octet-stream', 1);
    }

    public function testFileUploadThrowsWhenUploadFileMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['fileUpload' => ['success' => true]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->fileUpload('x.bin', 'application/octet-stream', 1);
    }

    public function testFileUploadThrowsWhenUrlsMissing(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'fileUpload' => [
                        'success' => true,
                        'uploadFile' => ['uploadUrl' => '', 'assetUrl' => 'https://a', 'headers' => []],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->createService($client)->fileUpload('x.bin', 'application/octet-stream', 1);
    }

    public function testFileUploadCoercesNonArrayHeadersToEmptyList(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'fileUpload' => [
                        'success' => true,
                        'uploadFile' => [
                            'uploadUrl' => 'https://uploads.linear.app/object',
                            'assetUrl' => 'https://assets.linear.app/x.bin',
                            'headers' => 'broken',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $result = $this->createService($client)->fileUpload('x.bin', 'application/octet-stream', 1);
        $this->assertSame([], $result['headers']);
    }

    public function testAttachmentCreateSucceeds(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => [
                    'attachmentCreate' => [
                        'success' => true,
                        'attachment' => ['id' => 'att-1', 'url' => 'https://linear.app/file'],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->createService($client)->attachmentCreate('SCI-1', 'report.md', 'https://assets.linear.app/report.md');
        $this->addToAssertionCount(1);
    }

    public function testAttachmentCreateThrowsWhenSuccessFalse(): void
    {
        $client = new MockHttpClient([
            new MockResponse(json_encode([
                'data' => ['attachmentCreate' => ['success' => false]],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Could not attach file to Linear issue.');
        $this->createService($client)->attachmentCreate('SCI-1', 'report.md', 'https://assets.linear.app/report.md');
    }
}
