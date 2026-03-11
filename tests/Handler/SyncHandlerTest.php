<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\SyncHandler;
use App\Service\Logger;
use App\Tests\CommandTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncHandlerTest extends CommandTestCase
{
    private Logger $logger;
    private string $baseBranch = 'origin/develop';

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(Logger::class);
        $this->logger->method('section');
        $this->logger->method('text');
        $this->logger->method('note');
        $this->logger->method('success');
        $this->logger->method('error');
    }

    public function testHandleRebaseSuccess(): void
    {
        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitRepository->method('resolveLatestBaseBranch')
            ->with($this->baseBranch)
            ->willReturn('origin/develop');

        $this->gitRepository->method('isAncestor')
            ->with('origin/develop', 'HEAD')
            ->willReturn(false);

        $this->gitRepository->expects($this->once())
            ->method('tryRebase')
            ->with('origin/develop')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('rebaseAbort');

        $handler = new SyncHandler($this->gitRepository, $this->baseBranch, $this->translationService, $this->logger);
        $result = $handler->handle($this->createIo());

        $this->assertSame(0, $result);
    }

    public function testHandleAlreadyUpToDate(): void
    {
        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitRepository->method('resolveLatestBaseBranch')
            ->willReturn('origin/develop');

        $this->gitRepository->method('isAncestor')
            ->with('origin/develop', 'HEAD')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('tryRebase');

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->expects($this->once())
            ->method('note');

        $handler = new SyncHandler($this->gitRepository, $this->baseBranch, $this->translationService, $logger);
        $result = $handler->handle($this->createIo());

        $this->assertSame(0, $result);
    }

    public function testHandleConflictsAbortsRebase(): void
    {
        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitRepository->method('resolveLatestBaseBranch')
            ->willReturn('origin/develop');

        $this->gitRepository->method('isAncestor')
            ->with('origin/develop', 'HEAD')
            ->willReturn(false);

        $this->gitRepository->method('tryRebase')
            ->with('origin/develop')
            ->willReturn(false);

        $this->gitRepository->expects($this->once())
            ->method('rebaseAbort');

        $logger = $this->createMock(Logger::class);
        $logger->method('section');
        $logger->method('text');
        $logger->expects($this->once())
            ->method('error');

        $handler = new SyncHandler($this->gitRepository, $this->baseBranch, $this->translationService, $logger);
        $result = $handler->handle($this->createIo());

        $this->assertSame(1, $result);
    }

    public function testHandleDirtyWorkingDirectory(): void
    {
        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn("M  file.php\nA  newfile.php");

        $this->gitRepository->expects($this->never())
            ->method('fetch');
        $this->gitRepository->expects($this->never())
            ->method('tryRebase');

        $handler = new SyncHandler($this->gitRepository, $this->baseBranch, $this->translationService, $this->logger);
        $result = $handler->handle($this->createIo());

        $this->assertSame(1, $result);
    }

    public function testHandleOnBaseBranch(): void
    {
        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->never())
            ->method('getPorcelainStatus');
        $this->gitRepository->expects($this->never())
            ->method('fetch');
        $this->gitRepository->expects($this->never())
            ->method('tryRebase');

        $handler = new SyncHandler($this->gitRepository, $this->baseBranch, $this->translationService, $this->logger);
        $result = $handler->handle($this->createIo());

        $this->assertSame(1, $result);
    }

    public function testHandleOnBaseBranchWithoutOriginPrefix(): void
    {
        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('main');

        $this->gitRepository->expects($this->never())
            ->method('fetch');

        $handler = new SyncHandler($this->gitRepository, 'main', $this->translationService, $this->logger);
        $result = $handler->handle($this->createIo());

        $this->assertSame(1, $result);
    }

    private function createIo(): SymfonyStyle
    {
        return new SymfonyStyle(new ArrayInput([]), new BufferedOutput());
    }
}
