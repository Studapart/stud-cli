<?php

declare(strict_types=1);

namespace App\Tests\Handler;

use App\DTO\WorkflowRecorder;
use App\Handler\BranchAction;
use App\Handler\ItemBranchWorkspace;
use App\Service\GitBaseBranchResolver;
use App\Service\GitBranchService;
use App\Service\GitRepository;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ItemBranchWorkspaceTest extends TestCase
{
    public function testCreateActionUsesRemoteBaseWhenLocalDevelopIsAhead(): void
    {
        $gitRepository = $this->createMock(GitRepository::class);
        $gitRepository->method('runQuietly')->willReturnCallback(function (array $command): Process&Stub {
            $line = implode(' ', $command);
            if ($line === 'git merge-base --is-ancestor develop origin/develop') {
                return $this->failedProcess();
            }
            if ($line === 'git rev-parse --verify --quiet develop' || $line === 'git rev-parse --verify --quiet origin/develop') {
                return $this->successfulProcess();
            }
            if ($line === 'git merge-base --is-ancestor origin/develop develop') {
                return $this->successfulProcess();
            }

            return $this->failedProcess();
        });
        $gitRepository->expects($this->once())
            ->method('createBranch')
            ->with('feat/SCI-240-read-ports', 'origin/develop');

        $workspace = new ItemBranchWorkspace(
            $gitRepository,
            new GitBranchService($gitRepository, new GitBaseBranchResolver($gitRepository)),
            'origin/develop',
        );

        $workspace->applyStartAction(
            new WorkflowRecorder(),
            ['action' => BranchAction::CREATE, 'branch' => 'feat/SCI-240-read-ports'],
            'feat/SCI-240-read-ports',
        );
    }

    private function successfulProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(true);

        return $process;
    }

    private function failedProcess(): Process&Stub
    {
        $process = $this->createStub(Process::class);
        $process->method('isSuccessful')->willReturn(false);

        return $process;
    }
}
