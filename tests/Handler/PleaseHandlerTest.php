<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\PleaseHandler;
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
        $this->handler = new PleaseHandler($this->gitRepository, $this->translationService);
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
}
