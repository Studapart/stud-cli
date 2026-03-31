<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\ItemDownloadHandler;
use App\Service\FileSystem;
use App\Service\JiraAttachmentService;
use App\Service\TranslationService;
use App\Tests\CommandTestCase;

class ItemDownloadHandlerTest extends CommandTestCase
{
    private FileSystem $fileSystem;

    private JiraAttachmentService $attachmentService;

    private TranslationService $translator;

    private ItemDownloadHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fileSystem = $this->createMock(FileSystem::class);
        $this->attachmentService = $this->createMock(JiraAttachmentService::class);
        $this->translator = $this->createMock(TranslationService::class);
        $this->translator->method('trans')->willReturnCallback(static function (string $id, array $parameters = []): string {
            if ($parameters !== []) {
                return $id . '|' . json_encode($parameters, JSON_THROW_ON_ERROR);
            }

            return $id;
        });

        $this->handler = new ItemDownloadHandler($this->fileSystem, $this->attachmentService, $this->translator);
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

    public function testHandleDownloadsAllForIssue(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir')->with('.cursor/stud-downloads', 0777, true);
        $this->attachmentService->expects($this->once())
            ->method('fetchAttachmentsForIssue')
            ->with('ABC-1')
            ->willReturn([
                ['filename' => 'a.txt', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/1'],
                ['filename' => 'b.txt', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/2'],
            ]);
        $this->attachmentService->expects($this->exactly(2))
            ->method('downloadAttachmentContent')
            ->willReturnMap([
                ['https://x.atlassian.net/rest/api/3/attachment/content/1', 'one'],
                ['https://x.atlassian.net/rest/api/3/attachment/content/2', 'two'],
            ]);
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->fileSystem->expects($this->exactly(2))->method('filePutContents')
            ->willReturnCallback(function (string $path, string $content): void {
                static $i = 0;
                if ($i === 0) {
                    $this->assertSame('.cursor/stud-downloads/a.txt', $path);
                    $this->assertSame('one', $content);
                } else {
                    $this->assertSame('.cursor/stud-downloads/b.txt', $path);
                    $this->assertSame('two', $content);
                }
                ++$i;
            });

        $response = $this->handler->handle('abc-1', 'https://ignored', null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(2, $response->files);
    }

    public function testHandleFetchIssueAttachmentsFails(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->expects($this->once())
            ->method('fetchAttachmentsForIssue')
            ->willThrowException(new \App\Exception\ApiException('load failed', '', 500));

        $response = $this->handler->handle('KEY-X', null, null);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('load failed', $response->getError());
    }

    public function testHandleSingleUrlModeSuccess(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->expects($this->once())
            ->method('downloadAttachmentContent')
            ->willReturn('body');
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->fileSystem->expects($this->once())->method('filePutContents');

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
        $this->attachmentService->expects($this->never())->method('fetchAttachmentsForIssue');
        $this->attachmentService->expects($this->once())
            ->method('downloadAttachmentContent')
            ->willThrowException(new \App\Exception\ApiException('nope', '', 403));

        $response = $this->handler->handle(null, 'https://x.atlassian.net/rest/api/3/attachment/content/99', null);

        $this->assertFalse($response->isSuccess());
        $this->assertSame('nope', $response->getError());
    }

    public function testHandleUsesSuffixWhenFilenameCollides(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->method('fetchAttachmentsForIssue')->willReturn([
            ['filename' => 'same.txt', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/1'],
            ['filename' => 'same.txt', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/2'],
        ]);
        $this->attachmentService->method('downloadAttachmentContent')->willReturn('x');
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
        $attachmentService = $this->createMock(JiraAttachmentService::class);
        $translator = $this->createMock(TranslationService::class);
        $translator->method('trans')->willReturnArgument(0);
        $handler = new ItemDownloadHandler($fileSystem, $attachmentService, $translator);
        $fileSystem->method('fileExists')->willReturn(true);

        $method = new \ReflectionMethod(ItemDownloadHandler::class, 'allocateFilename');
        $method->setAccessible(true);
        $name = $method->invoke($handler, 'dir', 'f.txt');

        $this->assertMatchesRegularExpression('/^f_[a-f0-9]{8}\.txt$/', $name);
    }

    public function testHandleRecordsErrorWhenDownloadThrowsNonApi(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->method('fetchAttachmentsForIssue')->willReturn([
            ['filename' => 'z', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/1'],
        ]);
        $this->attachmentService->method('downloadAttachmentContent')->willThrowException(new \Error('boom'));
        $this->fileSystem->expects($this->never())->method('filePutContents');

        $response = $this->handler->handle('KEY-Z', null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->errors);
        $this->assertStringContainsString('boom', $response->errors[0]['message']);
    }

    public function testHandleRecordsErrorWhenWriteFails(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->method('fetchAttachmentsForIssue')->willReturn([
            ['filename' => 'w.txt', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/1'],
        ]);
        $this->attachmentService->method('downloadAttachmentContent')->willReturn('x');
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->fileSystem->method('filePutContents')->willThrowException(new \RuntimeException('disk full'));

        $response = $this->handler->handle('KEY-W', null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->files);
        $this->assertSame('disk full', $response->errors[0]['message']);
    }

    public function testFilenameHintWhenBasenameIsDotUsesAttachment(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->method('downloadAttachmentContent')->willReturn('b');
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->fileSystem->expects($this->once())->method('filePutContents')
            ->with($this->callback(fn (string $p): bool => str_contains($p, '/attachment')), $this->anything());

        $u = 'https://x.atlassian.net/rest/api/3/attachment/content/foo/.';
        $response = $this->handler->handle(null, $u, null);

        $this->assertTrue($response->isSuccess());
    }

    public function testFilenameHintWhenPathIsEmptyUsesAttachment(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->method('downloadAttachmentContent')->willReturn('b');
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->fileSystem->method('filePutContents')->willReturnCallback(function (string $path): void {
            $this->assertStringEndsWith('/attachment', $path);
        });

        $response = $this->handler->handle(null, 'https://x.atlassian.net', null);

        $this->assertTrue($response->isSuccess());
    }

    public function testSanitizeFallsBackWhenFilenameIsEmptyString(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->method('fetchAttachmentsForIssue')->willReturn([
            ['filename' => '', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/1'],
        ]);
        $this->attachmentService->method('downloadAttachmentContent')->willReturn('b');
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->fileSystem->method('filePutContents')->willReturnCallback(function (string $path): void {
            $this->assertStringContainsString('/attachment', $path);
        });

        $response = $this->handler->handle('KEY-S', null, null);

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleRecordsPerFileErrorsWhenOneDownloadFails(): void
    {
        $this->fileSystem->expects($this->once())->method('mkdir');
        $this->attachmentService->method('fetchAttachmentsForIssue')->willReturn([
            ['filename' => 'ok.txt', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/1'],
            ['filename' => 'bad.txt', 'contentUrl' => 'https://x.atlassian.net/rest/api/3/attachment/content/2'],
        ]);
        $this->attachmentService->method('downloadAttachmentContent')->willReturnCallback(function (string $url): string {
            if (str_contains($url, '/2')) {
                throw new \App\Exception\ApiException('fail-one', '', 500);
            }

            return 'ok';
        });
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->fileSystem->expects($this->once())->method('filePutContents');

        $response = $this->handler->handle('KEY-3', null, null);

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->files);
        $this->assertCount(1, $response->errors);
        $this->assertSame('fail-one', $response->errors[0]['message']);
    }
}
