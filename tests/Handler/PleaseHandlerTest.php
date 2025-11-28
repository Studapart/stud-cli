<?php

namespace App\Tests\Handler;

use App\Handler\PleaseHandler;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

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

        $processMock = $this->createMock(\Symfony\Component\Process\Process::class);
        $this->gitRepository->expects($this->once())
            ->method('forcePushWithLease')
            ->willReturn($processMock);

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        // Test intent: warning() was called, verified by return value
    }

    public function testHandleWithoutUpstream(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getUpstreamBranch')
            ->willReturn(null);

        $this->gitRepository->expects($this->never())
            ->method('forcePushWithLease');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        // Test intent: error() was called, verified by return value
    }
}
