<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\ItemUploadInput;
use App\Handler\ItemUploadHandler;
use App\Service\FileSystem;
use App\Service\JiraAttachmentService;
use App\Service\TranslationService;
use App\Tests\CommandTestCase;

class ItemUploadHandlerTest extends CommandTestCase
{
    private FileSystem $fileSystem;

    private JiraAttachmentService $attachmentService;

    private TranslationService $translator;

    private ItemUploadHandler $handler;

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

        $this->handler = new ItemUploadHandler($this->fileSystem, $this->attachmentService, $this->translator);
    }

    public function testHandleReturnsFatalWhenKeyMissing(): void
    {
        $response = $this->handler->handle(new ItemUploadInput('', ['a.txt']));

        $this->assertFalse($response->isSuccess());
        $this->assertSame('item.upload.error_no_key', $response->getError());
    }

    public function testHandleReturnsFatalWhenNoFiles(): void
    {
        $response = $this->handler->handle(new ItemUploadInput('KEY-1', []));

        $this->assertFalse($response->isSuccess());
        $this->assertSame('item.upload.error_no_files', $response->getError());
    }

    public function testHandleRejectsPathTraversal(): void
    {
        $this->attachmentService->expects($this->never())->method('uploadFileToIssue');
        $response = $this->handler->handle(new ItemUploadInput('KEY-1', ['../evil']));

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->files);
        $this->assertCount(1, $response->errors);
        $this->assertStringContainsString('item.upload.error_path_traversal', $response->errors[0]['message']);
    }

    public function testHandleRecordsErrorWhenFileMissing(): void
    {
        $this->fileSystem->method('fileExists')->willReturn(false);
        $this->attachmentService->expects($this->never())->method('uploadFileToIssue');

        $response = $this->handler->handle(new ItemUploadInput('KEY-1', ['missing.txt']));

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->files);
        $this->assertCount(1, $response->errors);
        $this->assertSame('item.upload.error_not_found', $response->errors[0]['message']);
    }

    public function testHandleRecordsErrorWhenPathIsDirectory(): void
    {
        $this->fileSystem->method('fileExists')->willReturn(true);
        $this->fileSystem->method('isDir')->willReturn(true);
        $this->attachmentService->expects($this->never())->method('uploadFileToIssue');

        $response = $this->handler->handle(new ItemUploadInput('KEY-1', ['adir']));

        $this->assertTrue($response->isSuccess());
        $this->assertSame('item.upload.error_not_file', $response->errors[0]['message']);
    }

    public function testHandleUploadsOneFile(): void
    {
        $this->fileSystem->method('fileExists')->willReturn(true);
        $this->fileSystem->method('isDir')->willReturn(false);
        $this->attachmentService->expects($this->once())
            ->method('uploadFileToIssue')
            ->with('ABC-1', $this->isType('string'));

        $response = $this->handler->handle(new ItemUploadInput('abc-1', ['notes.txt']));

        $this->assertTrue($response->isSuccess());
        $this->assertCount(1, $response->files);
        $this->assertSame('notes.txt', $response->files[0]['filename']);
        $this->assertSame('notes.txt', $response->files[0]['path']);
        $this->assertSame([], $response->errors);
    }

    public function testHandleNormalizesIssueKey(): void
    {
        $this->fileSystem->method('fileExists')->willReturn(true);
        $this->fileSystem->method('isDir')->willReturn(false);
        $this->attachmentService->expects($this->once())
            ->method('uploadFileToIssue')
            ->with('XY-2', $this->anything());

        $response = $this->handler->handle(new ItemUploadInput('  xy-2 ', ['f.txt']));

        $this->assertTrue($response->isSuccess());
    }

    public function testHandleRecordsPartialErrorOnThrowable(): void
    {
        $this->fileSystem->method('fileExists')->willReturn(true);
        $this->fileSystem->method('isDir')->willReturn(false);
        $this->attachmentService->expects($this->once())
            ->method('uploadFileToIssue')
            ->willThrowException(new \RuntimeException('disk full'));

        $response = $this->handler->handle(new ItemUploadInput('K-1', ['y.txt']));

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->files);
        $this->assertCount(1, $response->errors);
        $this->assertSame('disk full', $response->errors[0]['message']);
    }

    public function testHandleRecordsPartialErrorOnApiException(): void
    {
        $this->fileSystem->method('fileExists')->willReturn(true);
        $this->fileSystem->method('isDir')->willReturn(false);
        $this->attachmentService->expects($this->once())
            ->method('uploadFileToIssue')
            ->willThrowException(new \App\Exception\ApiException('Jira said no', 'detail', 403));

        $response = $this->handler->handle(new ItemUploadInput('K-1', ['x.bin']));

        $this->assertTrue($response->isSuccess());
        $this->assertSame([], $response->files);
        $this->assertCount(1, $response->errors);
        $this->assertStringContainsString('Jira said no', $response->errors[0]['message']);
        $this->assertStringContainsString('detail', $response->errors[0]['message']);
    }

    public function testHandleSkipsEmptyPathSegments(): void
    {
        $this->fileSystem->method('fileExists')->willReturn(true);
        $this->fileSystem->method('isDir')->willReturn(false);
        $this->attachmentService->expects($this->once())->method('uploadFileToIssue');

        $response = $this->handler->handle(new ItemUploadInput('K-1', ['   ', 'ok.txt']));

        $this->assertCount(1, $response->files);
    }
}
