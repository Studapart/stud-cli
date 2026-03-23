<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\CommitHandler;
use App\Handler\PleaseHandler;
use App\Handler\PushHandler;
use App\Service\GitRepository;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Process;

class PushHandlerTest extends CommandTestCase
{
    private function createHandler(
        CommitHandler $commitHandler,
        GitRepository $gitRepository,
        PleaseHandler $pleaseHandler,
        ?Logger $logger = null,
    ): PushHandler {
        $logger ??= $this->createMock(Logger::class);

        return new PushHandler(
            $commitHandler,
            $gitRepository,
            $pleaseHandler,
            $this->translationService,
            $logger,
        );
    }

    private function io(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }

    public function testCommitFailureSkipsPush(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->expects($this->once())->method('handle')->willReturn(2);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->expects($this->never())->method('pushHeadToOrigin');

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertSame(2, $handler->handle($this->io(), false, null, false, false, false, false, true));
    }

    public function testPushSuccess(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->expects($this->once())->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertSame(0, $handler->handle($this->io(), false, null, false, false, false, false, true));
    }

    public function testPushFailsWithNoPleaseReturnsOne(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('error');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertSame(1, $handler->handle($this->io(), false, null, false, false, true, false, true));
    }

    public function testPushFailsQuietRunsPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->willReturn(0);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertSame(0, $handler->handle($this->io(), false, null, false, true, false, false, true));
    }

    public function testPushFailsAgentWithPleaseFallbackFalse(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('error');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertSame(1, $handler->handle($this->io(), false, null, false, true, false, true, false));
    }

    public function testPushFailsAgentWithPleaseFallbackTrueRunsPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->willReturn(0);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertSame(0, $handler->handle($this->io(), false, null, false, true, false, true, true));
    }

    public function testPushFailsInteractiveUserDeclinesPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->never())->method('handle');

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(false);
        $logger->expects($this->once())->method('error');

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertSame(1, $handler->handle($this->io(), false, null, false, false, false, false, true));
    }

    public function testPushFailsInteractiveUserAcceptsPlease(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->willReturn(0);

        $logger = $this->createMock(Logger::class);
        $logger->expects($this->once())->method('confirm')->willReturn(true);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler, $logger);

        $this->assertSame(0, $handler->handle($this->io(), false, null, false, false, false, false, true));
    }

    public function testPleaseFailureExitCodePropagates(): void
    {
        $commitHandler = $this->createMock(CommitHandler::class);
        $commitHandler->method('handle')->willReturn(0);

        $process = $this->createMock(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('getCurrentBranchName')->willReturn('feat/foo');
        $gitRepository->method('pushHeadToOrigin')->willReturn($process);

        $pleaseHandler = $this->createMock(PleaseHandler::class);
        $pleaseHandler->expects($this->once())->method('handle')->willReturn(1);

        $handler = $this->createHandler($commitHandler, $gitRepository, $pleaseHandler);

        $this->assertSame(1, $handler->handle($this->io(), false, null, false, true, false, false, true));
    }
}
