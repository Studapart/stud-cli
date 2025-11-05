<?php

namespace App\Tests\Please;

use App\Git\GitRepository;
use App\Please\PleaseHandler;
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
        $this->handler = new PleaseHandler($this->gitRepository);
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
        $this->assertStringContainsString('⚠️  Forcing with lease...', $output->fetch());
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
        $this->assertStringContainsString('Your current branch does not have an upstream remote configured.', $output->fetch());
    }
}
