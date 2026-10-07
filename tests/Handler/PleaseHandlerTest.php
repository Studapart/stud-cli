<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\MessageRef;
use App\DTO\ResponseMessage;
use App\Handler\FlattenHandler;
use App\Handler\PleaseHandler;
use App\Response\CommandResponse;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Process\Process;

class PleaseHandlerTest extends CommandTestCase
{
    private PleaseHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$translationService = $this->translationService;
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->never())->method('handle');
        $this->handler = new PleaseHandler($this->gitRepository, $this->translationService, $flattenHandler);
    }

    public function testHandleWithUpstream(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getUpstreamBranch')
            ->willReturn('origin/my-branch');

        $processMock = $this->createMock(Process::class);
        $this->gitRepository->expects($this->once())
            ->method('forcePushWithLease')
            ->willReturn($processMock);

        $result = $this->handler->handle();

        $this->assertTrue($result->isSuccess());
        $this->assertNotEmpty($result->getMessages());
    }

    public function testHandleWithoutUpstreamSetsUpstreamAndPushes(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getUpstreamBranch')
            ->willReturn(null);

        $this->gitRepository->expects($this->never())
            ->method('forcePushWithLease');

        $this->gitRepository->expects($this->once())
            ->method('getCurrentBranchName')
            ->willReturn('feat/foo');

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->expects($this->once())
            ->method('pushToOrigin')
            ->with('feat/foo')
            ->willReturn($process);

        $result = $this->handler->handle(false);

        $this->assertTrue($result->isSuccess());
        $this->assertNotEmpty($result->getMessages());
    }

    public function testHandleWithoutUpstreamQuietOmitsNotice(): void
    {
        $this->gitRepository->method('getUpstreamBranch')->willReturn(null);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);

        $result = $this->handler->handle(true);

        $this->assertTrue($result->isSuccess());
        $this->assertSame([], $result->getMessages());
    }

    public function testHandleWithoutUpstreamPushFailure(): void
    {
        $this->gitRepository->method('getUpstreamBranch')->willReturn(null);
        $this->gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);
        $this->gitRepository->method('pushToOrigin')->willReturn($process);

        $result = $this->handler->handle(false);

        $this->assertFalse($result->isSuccess());
    }

    public function testFlattenDirtyTreeSkipsForcePush(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->once())->method('handle')->willReturn(
            CommandResponse::error(MessageRef::key('flatten.error_dirty_working')),
        );
        $handler = new PleaseHandler($this->gitRepository, $this->translationService, $flattenHandler);
        $this->gitRepository->expects($this->never())->method('forcePushWithLease');
        $this->gitRepository->expects($this->never())->method('pushToOrigin');

        $result = $handler->handle(true, true);

        $this->assertFalse($result->isSuccess());
        $error = $result->getErrorMessage();
        $this->assertInstanceOf(MessageRef::class, $error);
        $this->assertSame('flatten.error_dirty_working', $error->key);
    }

    public function testFlattenWithoutFixupsStillForcePushes(): void
    {
        $flattenHandler = $this->createMock(FlattenHandler::class);
        $flattenHandler->expects($this->once())->method('handle')->willReturn(
            CommandResponse::success(messages: [
                ResponseMessage::notice(MessageRef::key('flatten.no_fixups')),
            ]),
        );
        $handler = new PleaseHandler($this->gitRepository, $this->translationService, $flattenHandler);
        $this->gitRepository->method('getUpstreamBranch')->willReturn('origin/chore/SCI-213');
        $this->gitRepository->expects($this->once())->method('forcePushWithLease');

        $result = $handler->handle(true, true);

        $this->assertTrue($result->isSuccess());
        $this->assertNotEmpty($result->getNotices());
        $this->assertNotEmpty($result->getWarnings());
    }
}
