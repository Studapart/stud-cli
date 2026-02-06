<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\CommitUndoHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use App\Tests\TestKernel;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class CommitUndoHandlerTest extends CommandTestCase
{
    private CommitUndoHandler $handler;

    private Logger&\PHPUnit\Framework\MockObject\MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        TestKernel::$gitRepository = $this->gitRepository;
        TestKernel::$translationService = $this->translationService;
        $this->logger = $this->createMock(Logger::class);
        $this->handler = new CommitUndoHandler($this->gitRepository, $this->logger, $this->translationService);
    }

    public function testHandleSuccessWhenNotPushed(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getProjectConfigPath')
            ->willReturn('/repo/.git/stud.config');

        $this->gitRepository->expects($this->once())
            ->method('hasAtLeastOneCommit')
            ->willReturn(true);

        $this->gitRepository->expects($this->once())
            ->method('isHeadPushed')
            ->willReturn(false);

        $this->gitRepository->expects($this->once())
            ->method('undoLastCommit');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleRefusesWhenPushedAndUserDoesNotConfirm(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getProjectConfigPath')
            ->willReturn('/repo/.git/stud.config');

        $this->gitRepository->expects($this->once())
            ->method('hasAtLeastOneCommit')
            ->willReturn(true);

        $this->gitRepository->expects($this->once())
            ->method('isHeadPushed')
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('confirm')
            ->willReturn(false);

        $this->gitRepository->expects($this->never())
            ->method('undoLastCommit');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }

    public function testHandleUndoesWhenPushedAndUserConfirms(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getProjectConfigPath')
            ->willReturn('/repo/.git/stud.config');

        $this->gitRepository->expects($this->once())
            ->method('hasAtLeastOneCommit')
            ->willReturn(true);

        $this->gitRepository->expects($this->once())
            ->method('isHeadPushed')
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('confirm')
            ->willReturn(true);

        $this->gitRepository->expects($this->once())
            ->method('undoLastCommit');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io);

        $this->assertSame(0, $result);
    }

    public function testHandleErrorWhenNotInRepo(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getProjectConfigPath')
            ->willThrowException(new \RuntimeException('Not in a git repository.'));

        $this->gitRepository->expects($this->never())
            ->method('hasAtLeastOneCommit');
        $this->gitRepository->expects($this->never())
            ->method('isHeadPushed');
        $this->gitRepository->expects($this->never())
            ->method('undoLastCommit');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }

    public function testHandleErrorWhenNoCommit(): void
    {
        $this->gitRepository->expects($this->once())
            ->method('getProjectConfigPath')
            ->willReturn('/repo/.git/stud.config');

        $this->gitRepository->expects($this->once())
            ->method('hasAtLeastOneCommit')
            ->willReturn(false);

        $this->gitRepository->expects($this->never())
            ->method('isHeadPushed');
        $this->gitRepository->expects($this->never())
            ->method('undoLastCommit');

        $io = new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
        $result = $this->handler->handle($io);

        $this->assertSame(1, $result);
    }
}
