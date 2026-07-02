<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\IssueAttachment;
use App\Exception\ApiException;
use App\Handler\ItemDownloadHandler;
use App\Service\FileSystem;
use App\Service\IssueTrackerPort;
use App\Service\TranslationService;
use App\Tests\CommandTestCase;
use App\Tests\Handler\Support\AttachmentAgentJsonAssertions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

class ItemDownloadHandlerTest extends CommandTestCase
{
    use AttachmentAgentJsonAssertions;
    private FileSystem $fileSystem;

    private IssueTrackerPort&MockObject $provider;

    private TranslationService $translator;

    private ItemDownloadHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = $this->createMock(FileSystem::class);
        $this->provider = $this->createMock(IssueTrackerPort::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []): string {
            if ($parameters !== []) {
                return $id . '|' . json_encode($parameters, JSON_THROW_ON_ERROR);
            }

            return $id;
        });

        $this->handler = new ItemDownloadHandler($this->fileSystem, $this->provider, $this->translator);
    }

    public function testHandleReturnsFatalWhenKeyAndUrlMissing(): void
    {
        $response = $this->handler->handle(null, null, null);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('item.download.error_key_or_url', $response->getError());
    }

    public function testHandleRejectsPathTraversal(): void
    {
        $response = $this->handler->handle('KEY-1', null, '../evil');

        $this->assertFalse($response->isSuccess());
        $this->assertStringContainsString('item.download.error_path_traversal', (string) $response->getError());
    }

    public function testHandleReturnsFatalWhenTargetDirectoryCannotBeCreated(): void
    {
        $this->fileSystem->expects($this->once())
            ->method('mkdir')
            ->willThrowException(new \RuntimeException('mkdir failed'));

        $response = $this->handler->handle('KEY-1', null, 'downloads');

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'item.download.error_target_dir', ['error' => 'mkdir failed']);
    }

    public function testHandleDownloadsAllForIssue(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir')->with('.cursor/stud-downloads', 0777, true);
        $this->provider->expects($this->once())
            ->method('listAttachments')
            ->with('ABC-1')
            ->willReturn([
                new IssueAttachment('1', 'a.txt', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/1'),
                new IssueAttachment('2', 'b.txt', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/2'),
            ]);
        $this->provider->expects($this->exactly(2))
            ->method('downloadAttachment')
            ->willReturnCallback(function (string $url, string $path): void {
                static $i = 0;
                if ($i === 0) {
                    $this->assertSame('https://x.atlassian.net/rest/api/3/attachment/content/1', $url);
                    $this->assertSame('.cursor/stud-downloads/a.txt', $path);
                } else {
                    $this->assertSame('https://x.atlassian.net/rest/api/3/attachment/content/2', $url);
                    $this->assertSame('.cursor/stud-downloads/b.txt', $path);
                }
                ++$i;
            });
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle('abc-1', 'https://ignored', null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, $response->files);
    }

    public function testHandleFetchIssueAttachmentsFails(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->expects($this->once())
            ->method('listAttachments')
            ->willThrowException(new \App\Exception\ApiException('load failed', '', 500));

        $response = $this->handler->handle('KEY-X', null, null);

        $this->assertFalse($response->isSuccess());
        $message = $this->assertMessageRef($response->getErrorMessage(), 'item.download.error_fetch');
        $this->assertSame('load failed', $message->parameters['error']);
    }

    public function testHandleSingleUrlModeSuccess(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->expects($this->once())
            ->method('downloadAttachment')
            ->with(
                'https://x.atlassian.net/rest/api/3/attachment/content/1',
                $this->stringContains('.cursor/stud-downloads/')
            );
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle(
            null,
            'https://x.atlassian.net/rest/api/3/attachment/content/1',
            null
        );

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->files);
        $this->assertSame([], $response->errors);
    }

    public function testHandleSingleUrlModeFailureReturnsFatal(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->expects($this->never())->method('listAttachments');
        $this->provider->expects($this->once())
            ->method('downloadAttachment')
            ->willThrowException(new \App\Exception\ApiException('nope', '', 403));

        $response = $this->handler->handle(null, 'https://x.atlassian.net/rest/api/3/attachment/content/99', null);

        $this->assertFalse($response->isSuccess());
        $this->assertMessageRef($response->getErrorMessage(), 'item.download.error_file', ['error' => 'nope']);
    }

    public function testHandleUsesSuffixWhenFilenameCollides(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('listAttachments')->willReturn([
            new IssueAttachment('1', 'same.txt', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/1'),
            new IssueAttachment('2', 'same.txt', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/2'),
        ]);
        $this->provider->method('downloadAttachment');
        $this->fileSystem->method('fileExists')->willReturnCallback(function (string $path): bool {
            return $path === '.cursor/stud-downloads/same.txt';
        });

        $response = $this->handler->handle('KEY-2', null, null);

        $this->assertTrue($response->isSuccess());
        $names = array_column($response->files, 'filename');
        $this->assertContains('same_1.txt', $names);
    }

    public function testAllocateFilenameFallsBackToRandomWhenManyCollisions(): void
    {
        $fileSystem = $this->createMock(FileSystem::class);
        $provider = $this->createMock(IssueTrackerPort::class);
        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);
        $handler = new ItemDownloadHandler($fileSystem, $provider, $translator);
        $fileSystem->method('fileExists')->willReturn(true);

        $method = new \ReflectionMethod(ItemDownloadHandler::class, 'allocateFilename');
        $method->setAccessible(true);
        $name = $method->invoke($handler, 'dir', 'f.txt');

        $this->assertMatchesRegularExpression('/^f_[a-f0-9]{8}\.txt$/', $name);
    }

    public function testHandleRecordsErrorWhenDownloadThrowsNonApi(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('listAttachments')->willReturn([
            new IssueAttachment('1', 'z', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/1'),
        ]);
        $this->provider->method('downloadAttachment')->willThrowException(new \Error('boom'));

        $response = $this->handler->handle('KEY-Z', null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->errors);
        $message = $response->errors[0]['message'];
        $this->assertInstanceOf(\App\DTO\MessageRef::class, $message);
        $this->assertSame('item.download.error_file', $message->key);
        $this->assertStringContainsString('boom', (string) $message->parameters['error']);
    }

    public function testHandleRecordsErrorWhenWriteFails(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('listAttachments')->willReturn([
            new IssueAttachment('1', 'w.txt', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/1'),
        ]);
        $this->provider->method('downloadAttachment')->willThrowException(new \RuntimeException('disk full'));

        $response = $this->handler->handle('KEY-W', null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->files);
        $this->assertMessageRef($response->errors[0]['message'], 'item.download.error_file', ['error' => 'disk full']);
    }

    public function testFilenameHintWhenBasenameIsDotUsesAttachment(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('downloadAttachment');
        $this->fileSystem->method('fileExists')->willReturn(false);

        $u = 'https://x.atlassian.net/rest/api/3/attachment/content/foo/.';
        $response = $this->handler->handle(null, $u, null);

        $this->assertTrue($response->isSuccess());
        $this->assertStringContainsString('attachment', $response->files[0]['path']);
    }

    public function testFilenameHintWhenPathIsEmptyUsesAttachment(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('downloadAttachment')->willReturnCallback(function (string $url, string $path): void {
            $this->assertStringEndsWith('/attachment', $path);
        });
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle(null, 'https://x.atlassian.net', null);

        $this->assertTrue($response->isSuccess());
    }

    public function testSanitizeFallsBackWhenFilenameIsEmptyString(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('listAttachments')->willReturn([
            new IssueAttachment('1', '', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/1'),
        ]);
        $this->provider->method('downloadAttachment')->willReturnCallback(function (string $url, string $path): void {
            $this->assertStringContainsString('/attachment', $path);
        });
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle('KEY-S', null, null);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleRecordsPerFileErrorsWhenOneDownloadFails(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('listAttachments')->willReturn([
            new IssueAttachment('1', 'ok.txt', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/1'),
            new IssueAttachment('2', 'bad.txt', 1, 'https://x.atlassian.net/rest/api/3/attachment/content/2'),
        ]);
        $this->provider->method('downloadAttachment')->willReturnCallback(function (string $url): void {
            if (str_contains($url, '/2')) {
                throw new \App\Exception\ApiException('fail-one', '', 500);
            }
        });
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle('KEY-3', null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->files);
        $this->assertCount(1, $response->errors);
        $this->assertMessageRef($response->errors[0]['message'], 'item.download.error_file', ['error' => 'fail-one']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    #[DataProvider('linearAssetUrlProvider')]
    public function testHandleDownloadsLinearAssetUrls(string $label, string $assetUrl): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->expects($this->once())
            ->method('listAttachments')
            ->with('SCI-123')
            ->willReturn([
                new IssueAttachment('att-1', 'report.md', 12, $assetUrl),
            ]);
        $this->provider->expects($this->once())
            ->method('downloadAttachment')
            ->with($assetUrl, '.cursor/tmp/SCI-123/report.md');
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle('SCI-123', null, '.cursor/tmp/SCI-123');

        $this->assertDownloadHandlerBatchShape($response);
        $this->assertCount(1, $response->files);
        $this->assertSame('report.md', $response->files[0]['filename']);
        $this->assertSame('.cursor/tmp/SCI-123/report.md', $response->files[0]['path']);
        $this->assertSame([], $response->errors);

        $payload = $this->downloadAgentPayload($response, $this->translator);
        $this->assertAgentBatchPayloadParity($payload);
        $this->assertSame($response->files, $payload['data']['files']);
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function linearAssetUrlProvider(): iterable
    {
        yield 'public.linear.app' => ['public', 'https://public.linear.app/assets/report.md'];
        yield 'uploads.linear.app' => ['uploads', 'https://uploads.linear.app/object/report.md'];
    }

    public function testHandleLinearSingleUrlAgentJsonParity(): void
    {
        $url = 'https://public.linear.app/assets/report.md';
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->expects($this->once())
            ->method('downloadAttachment')
            ->with($url, $this->stringContains('.cursor/stud-downloads/'));
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle(null, $url, null);

        $this->assertDownloadHandlerBatchShape($response);
        $payload = $this->downloadAgentPayload($response, $this->translator);
        $this->assertAgentBatchPayloadParity($payload);
    }

    public function testHandleLinearPartialDownloadAgentJsonParity(): void
    {
        $okUrl = 'https://public.linear.app/assets/ok.txt';
        $badUrl = 'https://public.linear.app/assets/bad.txt';
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->provider->method('listAttachments')->willReturn([
            new IssueAttachment('1', 'ok.txt', 1, $okUrl),
            new IssueAttachment('2', 'bad.txt', 1, $badUrl),
        ]);
        $this->provider->method('downloadAttachment')->willReturnCallback(function (string $url): void {
            if (str_contains($url, 'bad.txt')) {
                throw new ApiException('linear download failed', '', 500);
            }
        });
        $this->fileSystem->method('fileExists')->willReturn(false);

        $response = $this->handler->handle('SCI-123', null, '.cursor/tmp/SCI-123');

        $this->assertDownloadHandlerBatchShape($response);
        $this->assertCount(1, $response->files);
        $this->assertCount(1, $response->errors);

        $payload = $this->downloadAgentPayload($response, $this->translator);
        $this->assertAgentBatchPayloadParity($payload);
    }
}
