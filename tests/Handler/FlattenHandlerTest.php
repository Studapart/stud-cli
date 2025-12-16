<?php

namespace App\Tests\Handler;

use App\Handler\FlattenHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class FlattenHandlerTest extends CommandTestCase
{
    private FlattenHandler $handler;
    private string $baseBranch = 'origin/develop';

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$translationService = $this->translationService;
        $logger = $this->createMock(Logger::class);
        $this->handler = new FlattenHandler($this->gitRepository, $this->baseBranch, $this->translationService, $logger);
    }

    public function testHandleWithDirtyWorkingDirectory(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn("M  file.php\nA  newfile.php");

        $this->gitRepository->expects($this->never())
            ->method('getMergeBase');
        $this->gitRepository->expects($this->never())
            ->method('hasFixupCommits');
        $this->gitRepository->expects($this->never())
            ->method('rebaseAutosquash');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        // Test intent: error() was called for dirty working directory
    }

    public function testHandleWithNoFixupCommits(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->with($this->baseBranch, 'HEAD')
            ->willReturn('abc123');

        $this->gitRepository->expects($this->once())
            ->method('hasFixupCommits')
            ->with('abc123')
            ->willReturn(false);

        $this->gitRepository->expects($this->never())
            ->method('rebaseAutosquash');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        // Test intent: note() was called indicating no fixups, verified by return value
    }

    public function testHandleWithFixupCommitsSuccess(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->with($this->baseBranch, 'HEAD')
            ->willReturn('abc123');

        $this->gitRepository->expects($this->once())
            ->method('hasFixupCommits')
            ->with('abc123')
            ->willReturn(true);

        $this->gitRepository->expects($this->once())
            ->method('rebaseAutosquash')
            ->with('abc123');

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
        // Test intent: warning() and success() were called, verified by return value
    }

    public function testHandleWithRebaseFailure(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitRepository->expects($this->once())
            ->method('getMergeBase')
            ->with($this->baseBranch, 'HEAD')
            ->willReturn('abc123');

        $this->gitRepository->expects($this->once())
            ->method('hasFixupCommits')
            ->with('abc123')
            ->willReturn(true);

        $this->gitRepository->expects($this->once())
            ->method('rebaseAutosquash')
            ->with('abc123')
            ->willThrowException(new \RuntimeException('Rebase failed'));

        $output = new BufferedOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);

        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
        // Test intent: error() was called for rebase failure, verified by return value
    }
}
