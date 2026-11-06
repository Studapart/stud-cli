<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\Handler\SyncHandler;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use App\Tests\CommandTestCase;

class SyncHandlerTest extends CommandTestCase
{
    private string $baseBranch = 'origin/develop';

    protected function setUp(): void
    {
        parent::setUp();
        $this->gitBranchService = $this->createStub(GitBranchService::class);
        $this->gitRepository = $this->createStub(GitRepository::class);
    }

    public function testHandleRebaseSuccess(): void
    {
        $this->gitBranchService = $this->createMock(GitBranchService::class);
        $this->gitRepository = $this->createMock(GitRepository::class);

        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitRepository->expects($this->once())
            ->method('fetch');

        $this->gitBranchService->expects($this->once())->method('resolveLatestBaseBranch')
            ->with($this->baseBranch)
            ->willReturn('origin/develop');

        $this->gitRepository->expects($this->once())->method('isAncestor')
            ->with('origin/develop', 'HEAD')
            ->willReturn(false);

        $this->gitRepository->expects($this->once())
            ->method('tryRebase')
            ->with('origin/develop')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('rebaseAbort');

        $handler = new SyncHandler($this->gitRepository, $this->gitBranchService, $this->baseBranch);
        $result = $handler->handle();

        $this->assertTrue($result->isSuccess());
    }

    public function testHandleAlreadyUpToDate(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);

        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitBranchService->method('resolveLatestBaseBranch')
            ->willReturn('origin/develop');

        $this->gitRepository->expects($this->once())->method('isAncestor')
            ->with('origin/develop', 'HEAD')
            ->willReturn(true);

        $this->gitRepository->expects($this->never())
            ->method('tryRebase');

        $handler = new SyncHandler($this->gitRepository, $this->gitBranchService, $this->baseBranch);
        $result = $handler->handle();

        $this->assertTrue($result->isSuccess());
    }

    public function testHandleConflictsAbortsRebase(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);

        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn('');

        $this->gitBranchService->method('resolveLatestBaseBranch')
            ->willReturn('origin/develop');

        $this->gitRepository->expects($this->once())->method('isAncestor')
            ->with('origin/develop', 'HEAD')
            ->willReturn(false);

        $this->gitRepository->expects($this->once())->method('tryRebase')
            ->with('origin/develop')
            ->willReturn(false);

        $this->gitRepository->expects($this->once())
            ->method('rebaseAbort');

        $handler = new SyncHandler($this->gitRepository, $this->gitBranchService, $this->baseBranch);
        $result = $handler->handle();

        $this->assertFalse($result->isSuccess());
    }

    public function testHandleDirtyWorkingDirectory(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);

        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('feat/SCI-42-my-feature');

        $this->gitRepository->method('getPorcelainStatus')
            ->willReturn("M  file.php\nA  newfile.php");

        $this->gitRepository->expects($this->never())
            ->method('fetch');
        $this->gitRepository->expects($this->never())
            ->method('tryRebase');

        $handler = new SyncHandler($this->gitRepository, $this->gitBranchService, $this->baseBranch);
        $result = $handler->handle();

        $this->assertFalse($result->isSuccess());
    }

    public function testHandleOnBaseBranch(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);

        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('develop');

        $this->gitRepository->expects($this->never())
            ->method('getPorcelainStatus');
        $this->gitRepository->expects($this->never())
            ->method('fetch');
        $this->gitRepository->expects($this->never())
            ->method('tryRebase');

        $handler = new SyncHandler($this->gitRepository, $this->gitBranchService, $this->baseBranch);
        $result = $handler->handle();

        $this->assertFalse($result->isSuccess());
    }

    public function testHandleOnBaseBranchWithoutOriginPrefix(): void
    {
        $this->gitRepository = $this->createMock(GitRepository::class);

        $this->gitRepository->method('getCurrentBranchName')
            ->willReturn('main');

        $this->gitRepository->expects($this->never())
            ->method('fetch');

        $handler = new SyncHandler($this->gitRepository, $this->gitBranchService, 'main');
        $result = $handler->handle();

        $this->assertFalse($result->isSuccess());
    }
}
